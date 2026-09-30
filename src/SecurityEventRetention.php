<?php
declare(strict_types=1);

namespace ControlBot\Security;

use InvalidArgumentException;

final class SecurityEventRetention
{
    public static function plan(array $event, array $policy, int $now, bool $legalHold = false, ?array $acknowledge = null, array $artifacts = []): array
    {
        $event = self::canonicalEvent($event);
        $policy = self::policy($policy);
        if ($now < $event['observed_at']) {
            throw new InvalidArgumentException('now invalid.');
        }
        if ($acknowledge !== null) {
            $acknowledge = self::canonicalEvent($acknowledge);
            self::validateAcknowledge($event, $acknowledge);
        }
        $artifactPlan = self::artifacts($artifacts);

        $referenceAt = $event['occurred_at'] ?? $event['observed_at'];
        $window = $policy['event_type_retention_seconds'][$event['event_type']][$event['severity']]
            ?? $policy['retention_seconds'][$event['severity']];
        $expired = ($now - $referenceAt) >= $window;
        $policyFingerprint = hash(
            'sha256',
            json_encode(self::canonical($policy), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        $retain = [
            'event_identity' => $event['event_identity'],
            'fingerprint' => $event['fingerprint'],
            'event_type' => $event['event_type'],
            'severity' => $event['severity'],
            'confidence' => $event['confidence'],
            'observed_at' => $event['observed_at'],
            'occurred_at' => $event['occurred_at'],
            'account_scope' => $event['account_scope'],
            'acknowledge' => $acknowledge === null ? null : [
                'state' => 'acknowledged',
                'event_identity' => $acknowledge['event_identity'],
                'observed_at' => $acknowledge['observed_at'],
            ],
        ];

        $classifications = [
            'event_identity' => 'retain_minimum',
            'fingerprint' => 'retain_minimum',
            'event_type' => 'retain_minimum',
            'severity' => 'retain_minimum',
            'confidence' => 'retain_minimum',
            'observed_at' => 'retain_minimum',
            'occurred_at' => 'retain_minimum',
            'provider' => 'purge',
            'account_scope' => 'keep_reference',
            'device' => $expired ? 'purge' : 'redact',
            'actor' => $expired ? 'purge' : 'redact',
            'raw_payload' => 'purge',
            'secrets' => 'purge',
            'ip' => 'purge',
            'precise_location' => 'purge',
            'credentials' => 'purge',
            'artifacts' => $artifactPlan['classifications'],
        ];

        $basis = [
            'event_identity' => $event['event_identity'],
            'policy_version' => $policy['version'],
            'policy_fingerprint' => $policyFingerprint,
            'expired' => $expired,
            'legal_hold' => $legalHold,
            'classifications' => $classifications,
            'retain' => $retain,
            'retained_artifact_references' => $artifactPlan['retained_references'],
            'retention_window_seconds' => $window,
        ];

        return [
            'version' => 1,
            'policy_version' => $policy['version'],
            'policy_fingerprint' => $policyFingerprint,
            'event_identity' => $event['event_identity'],
            'expired' => $expired,
            'legal_hold' => $legalHold,
            'classifications' => $classifications,
            'retain_minimum' => $retain,
            'retained_artifact_references' => $artifactPlan['retained_references'],
            'retention_window_seconds' => $window,
            'purge_plan_id' => 'security-retention:'.substr(hash('sha256', json_encode(self::canonical($basis), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 40),
        ];
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

    private static function policy(array $policy): array
    {
        $keys = array_keys($policy);
        sort($keys, SORT_STRING);
        if ($keys !== ['event_type_retention_seconds', 'retention_seconds', 'version']) {
            throw new InvalidArgumentException('RetentionPolicy fields invalid.');
        }
        if (!is_int($policy['version']) || $policy['version'] < 1) {
            throw new InvalidArgumentException('policy version invalid.');
        }
        $durations = $policy['retention_seconds'];
        if (!is_array($durations) || array_is_list($durations)) {
            throw new InvalidArgumentException('retention_seconds invalid.');
        }
        $durationKeys = array_keys($durations);
        sort($durationKeys, SORT_STRING);
        if ($durationKeys !== ['critical', 'info', 'warning']) {
            throw new InvalidArgumentException('retention_seconds fields invalid.');
        }
        foreach ($durations as $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('retention_seconds value invalid.');
            }
        }
        $overrides = $policy['event_type_retention_seconds'];
        if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
            throw new InvalidArgumentException('event_type_retention_seconds invalid.');
        }
        $allowedTypes = [
            'new_login','new_device','privileged_session','passkey_added','passkey_removed','totp_changed',
            'recovery_method_changed','credential_rotated','production_authority_changed','billing_security_changed',
            'security_alert_acknowledged','repository_visibility_changed',
        ];
        foreach ($overrides as $type => $values) {
            if (!is_string($type) || !in_array($type, $allowedTypes, true) || !is_array($values) || array_is_list($values)) {
                throw new InvalidArgumentException('event_type retention invalid.');
            }
            $keys = array_keys($values); sort($keys, SORT_STRING);
            if ($keys !== ['critical','info','warning']) {
                throw new InvalidArgumentException('event_type retention fields invalid.');
            }
            foreach ($values as $value) if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException('event_type retention value invalid.');
            }
        }
        ksort($overrides, SORT_STRING);
        return ['version' => $policy['version'], 'retention_seconds' => $durations, 'event_type_retention_seconds' => $overrides];
    }

    private static function validateAcknowledge(array $event, array $acknowledge): void
    {
        $actor = $acknowledge['actor'];
        if ($acknowledge['event_type'] !== 'security_alert_acknowledged' || $acknowledge['provider'] !== 'controlbot' || $actor === null) {
            throw new InvalidArgumentException('acknowledge event invalid.');
        }
        $expectedId = 'ack-'.substr(hash('sha256', $event['event_identity'].'|'.$actor['actor_ref'].'|'.$actor['context_ref']), 0, 40);
        if ($acknowledge['event_id'] !== $expectedId
            || $acknowledge['account_scope'] !== $event['account_scope']
            || $acknowledge['severity'] !== $event['severity']
            || $acknowledge['confidence'] !== $event['confidence']
            || $acknowledge['observed_at'] < $event['observed_at']
            || $acknowledge['occurred_at'] !== $acknowledge['observed_at']) {
            throw new InvalidArgumentException('acknowledge does not belong to event.');
        }
    }

    private static function artifacts(array $artifacts): array
    {
        if (!array_is_list($artifacts) || count($artifacts) > 32) {
            throw new InvalidArgumentException('artifacts invalid.');
        }
        $classifications = [];
        $references = [];
        foreach ($artifacts as $index => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) throw new InvalidArgumentException('artifact invalid.');
            $keys = array_keys($artifact); sort($keys, SORT_STRING);
            if ($keys !== ['kind','value'] || !is_string($artifact['kind']) || !is_string($artifact['value']) || $artifact['value'] === '') {
                throw new InvalidArgumentException('artifact fields invalid.');
            }
            $kind = $artifact['kind'];
            if ($kind === 'reference') {
                if (preg_match('/^controlbot:[A-Za-z0-9._\/-]{1,160}$/D', $artifact['value']) !== 1) {
                    throw new InvalidArgumentException('artifact reference invalid.');
                }
                $classifications[(string)$index] = 'keep_reference';
                $references[] = $artifact['value'];
                continue;
            }
            if (!in_array($kind, ['raw_payload','secret','ip','precise_location','credential'], true)) {
                throw new InvalidArgumentException('artifact kind invalid.');
            }
            $classifications[(string)$index] = $kind === 'raw_payload' ? 'redact' : 'purge';
        }
        sort($references, SORT_STRING);
        return ['classifications' => $classifications, 'retained_references' => $references];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }
}
