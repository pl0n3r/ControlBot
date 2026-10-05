<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use InvalidArgumentException;

final class RunnerOutcomeProjection
{
    private const EVENT_STALE_AFTER_SECONDS = 300;
    private const DELIVERY_FIELDS = [
        'version', 'session_id', 'assignment_id', 'runner_id', 'generation',
        'order_id', 'attempt_id', 'work_item_id', 'order_fingerprint', 'execution',
    ];

    private static function canonicalDelivery(array $record): array
    {
        $keys = array_keys($record);
        $expected = self::DELIVERY_FIELDS;
        sort($keys);
        sort($expected);

        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        $ref = '/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]{0,179}$/D';

        if ($keys !== $expected
            || ($record['version'] ?? null) !== 1
            || ($record['execution'] ?? null) !== false
            || !is_int($record['generation'])
            || $record['generation'] < 1
            || $record['generation'] > 1_000_000_000
            || !is_string($record['order_fingerprint'])
            || preg_match('/^[0-9a-f]{64}$/D', $record['order_fingerprint']) !== 1) {
            throw new InvalidArgumentException('SessionDelivery invalid.');
        }

        foreach (['session_id', 'assignment_id', 'work_item_id'] as $field) {
            if (!is_string($record[$field]) || preg_match($ref, $record[$field]) !== 1) {
                throw new InvalidArgumentException('SessionDelivery reference invalid.');
            }
        }
        foreach (['runner_id', 'order_id', 'attempt_id'] as $field) {
            if (!is_string($record[$field]) || preg_match($uuid, $record[$field]) !== 1) {
                throw new InvalidArgumentException('SessionDelivery identity invalid.');
            }
            $record[$field] = strtolower($record[$field]);
        }

        return $record;
    }

    private static function projection(
        array $delivery,
        string $deliveryState,
        string $ack,
        string $progress,
        string $outcome,
        bool $terminal,
        string $freshness,
        ?array $event,
    ): array {
        $provenance = null;
        if ($event !== null) {
            $provenance = [
                'event_id' => $event['event_id'],
                'sequence' => $event['sequence'],
                'state' => $event['state'],
                'occurred_at' => $event['occurred_at'],
                'evidence_code' => $event['evidence']['code'],
                'evidence_ref' => $event['evidence']['ref'],
            ];
        }

        return [
            'version' => 1,
            'session_id' => $delivery['session_id'],
            'assignment_id' => $delivery['assignment_id'],
            'order_id' => $delivery['order_id'],
            'attempt_id' => $delivery['attempt_id'],
            'runner_id' => $delivery['runner_id'],
            'generation' => $delivery['generation'],
            'delivery' => $deliveryState,
            'ack' => $ack,
            'progress' => $progress,
            'outcome' => $outcome,
            'terminal' => $terminal,
            'freshness' => $freshness,
            'provenance' => $provenance,
            'execution' => false,
        ];
    }

    public static function project(
        array $deliveryRecord,
        ?array $eventRecord,
        int $now,
        int $staleAfterSeconds = self::EVENT_STALE_AFTER_SECONDS,
    ): array {
        $delivery = self::canonicalDelivery($deliveryRecord);
        if ($now < 0 || $staleAfterSeconds < 1 || $staleAfterSeconds > 3600) {
            throw new InvalidArgumentException('Projection freshness thresholds invalid.');
        }

        if ($eventRecord === null) {
            return self::projection(
                $delivery, 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', false, 'missing', null
            );
        }

        $event = RunnerGateway::event($eventRecord);
        $fresh = $event['occurred_at'] <= $now
            && $now - $event['occurred_at'] <= $staleAfterSeconds;
        if (!$fresh) {
            return self::projection(
                $delivery, 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', false, 'stale', $event
            );
        }

        $owned = $event['order_id'] === $delivery['order_id']
            && $event['attempt_id'] === $delivery['attempt_id']
            && $event['runner_id'] === $delivery['runner_id']
            && $event['generation'] === $delivery['generation'];
        if (!$owned) {
            return self::projection(
                $delivery, 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', false, 'mismatched', $event
            );
        }

        [$progress, $outcome, $terminal] = match ($event['state']) {
            'accepted' => ['accepted', 'UNKNOWN', false],
            'started', 'heartbeat' => ['running', 'UNKNOWN', false],
            'progress', 'checkpoint' => ['in_progress', 'UNKNOWN', false],
            'waiting_human' => ['waiting_human', 'UNKNOWN', false],
            'blocked' => ['blocked', 'UNKNOWN', false],
            'completed' => ['complete', 'SUCCEEDED', true],
            'failed' => ['complete', 'FAILED', true],
            'cancelled' => ['complete', 'CANCELLED', true],
        };

        return self::projection(
            $delivery, 'DELIVERED', 'ACKNOWLEDGED', $progress, $outcome, $terminal, 'fresh', $event
        );
    }
}
