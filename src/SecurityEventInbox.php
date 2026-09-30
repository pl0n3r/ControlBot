<?php
declare(strict_types=1);

namespace ControlBot\Security;

use InvalidArgumentException;

final class SecurityEventInbox
{
    private const STATES = ['unread', 'acknowledged', 'superseded'];
    private const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    public static function project(
        array $events,
        array $acknowledged = [],
        array $superseded = []
    ): array {
        if (!array_is_list($events) || !array_is_list($acknowledged) || !array_is_list($superseded)) {
            throw new InvalidArgumentException('Security inbox lists invalid.');
        }

        $ack = self::identitySet($acknowledged, 'acknowledged');
        $old = self::identitySet($superseded, 'superseded');
        $cards = [];
        $seen = [];

        foreach ($events as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Security event invalid.');
            }
            $event = self::canonicalEvent($raw);
            $identity = $event['event_identity'];
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Security event duplicated.');
            }
            $seen[$identity] = true;

            $state = isset($old[$identity])
                ? 'superseded'
                : (isset($ack[$identity]) ? 'acknowledged' : 'unread');

            $cards[] = self::card($event, $state);
        }

        usort($cards, self::compare(...));
        foreach ($cards as &$card) {
            unset($card['_sort_at']);
        }
        unset($card);
        return ['version' => 1, 'cards' => $cards];
    }

    public static function acknowledge(
        array $eventRaw,
        int $acknowledgedAt,
        string $actorRef,
        string $contextRef
    ): array {
        $event = self::canonicalEvent($eventRaw);
        if ($acknowledgedAt < $event['observed_at']) {
            throw new InvalidArgumentException('acknowledged_at invalid.');
        }

        $seed = $event['event_identity'].'|'.$actorRef.'|'.$contextRef;
        $eventId = 'ack-'.substr(hash('sha256', $seed), 0, 40);

        return SecurityEvent::normalize([
            'version' => 1,
            'provider' => 'controlbot',
            'event_id' => $eventId,
            'observed_at' => $acknowledgedAt,
            'occurred_at' => $acknowledgedAt,
            'event_type' => 'security_alert_acknowledged',
            'severity' => $event['severity'],
            'account_scope' => $event['account_scope'],
            'confidence' => $event['confidence'],
            'device' => null,
            'actor' => [
                'actor_ref' => $actorRef,
                'context_ref' => $contextRef,
            ],
        ]);
    }

    private static function card(array $event, string $state): array
    {
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Security card state invalid.');
        }

        $device = $event['device'];
        $location = $device['location'] ?? null;
        $timestamp = $event['occurred_at'] ?? $event['observed_at'];

        return [
            'event_identity' => $event['event_identity'],
            'event_type' => $event['event_type'],
            'severity' => $event['severity'],
            'confidence' => $event['confidence'],
            'provider' => $event['provider'],
            'observed_at' => $event['observed_at'],
            'occurred_at' => $event['occurred_at'],
            'state' => $state,
            'context' => [
                'account_scope' => $event['account_scope'],
                'device_type' => $device['type'] ?? null,
                'platform' => $device['platform'] ?? null,
                'browser' => $device['browser'] ?? null,
                'country' => $location['country'] ?? null,
            ],
            'actions' => [
                'acknowledge' => [
                    'enabled' => $state === 'unread',
                    'label' => 'Acknowledge security event',
                    'keyboard_focusable' => true,
                    'surfaces' => ['mobile', 'desktop'],
                    'requires_hover' => false,
                    'requires_drag' => false,
                ],
            ],
            '_sort_at' => $timestamp,
        ];
    }

    private static function compare(array $a, array $b): int
    {
        $severity = self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']];
        if ($severity !== 0) {
            return $severity;
        }
        return ($a['_sort_at'] <=> $b['_sort_at'])
            ?: ($a['event_identity'] <=> $b['event_identity']);
    }

    private static function canonicalEvent(array $event): array
    {
        $raw = $event;
        unset($raw['event_identity'], $raw['fingerprint']);
        $canonical = SecurityEvent::normalize($raw);
        if ($canonical !== $event) {
            throw new InvalidArgumentException('SecurityEvent must be canonical normalized v1.');
        }
        return $canonical;
    }

    private static function identitySet(array $values, string $label): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value) || !str_starts_with($value, 'security-event:')) {
                throw new InvalidArgumentException($label.' identity invalid.');
            }
            if (isset($out[$value])) {
                throw new InvalidArgumentException($label.' identity duplicated.');
            }
            $out[$value] = true;
        }
        return $out;
    }
}
