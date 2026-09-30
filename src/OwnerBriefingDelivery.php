<?php
declare(strict_types=1);

namespace ControlBot\Briefing;

use InvalidArgumentException;

final class OwnerBriefingDelivery
{
    private const CHANNELS = ['email', 'push'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];

    public static function plan(
        array $snapshot,
        array $tick,
        array $policy,
        ?array $previousDelivery = null
    ): array {
        $briefing = OwnerBriefing::build($snapshot);
        $tick = self::tick($tick);
        $policy = self::policy($policy);
        $previous = self::previous($previousDelivery);

        $briefingFingerprint = hash('sha256', self::json($briefing));
        $identity = hash('sha256', $tick['day'] . '|' . $briefingFingerprint);
        $deliveryRef = 'controlbot:briefing-delivery/' . $identity;
        $dedupeKey = 'controlbot:dedupe/owner-briefing/' . $identity;

        $reasons = [];
        if ($tick['freshness'] !== 'fresh') {
            $reasons[] = 'scheduler_' . $tick['freshness'];
        }
        if (!$policy['enabled']) {
            $reasons[] = 'policy_blocked';
        }
        if (!$policy['channel_available']) {
            $reasons[] = 'channel_unavailable';
        }
        if (
            $previous !== null
            && $previous['day'] === $tick['day']
            && hash_equals($previous['briefing_fingerprint'], $briefingFingerprint)
        ) {
            $reasons[] = 'duplicate';
        }
        sort($reasons, SORT_STRING);

        $deliver = $reasons === [];
        $intent = $deliver ? [
            'kind' => 'owner_briefing',
            'channel' => $policy['channel'],
            'delivery_ref' => $deliveryRef,
            'dedupe_key' => $dedupeKey,
            'briefing_fingerprint' => $briefingFingerprint,
        ] : null;

        return [
            'version' => 1,
            'day' => $tick['day'],
            'schedule_ref' => $tick['schedule_ref'],
            'policy_ref' => $policy['policy_ref'],
            'channel' => $policy['channel'],
            'briefing' => $briefing,
            'briefing_fingerprint' => $briefingFingerprint,
            'delivery_ref' => $deliveryRef,
            'dedupe_key' => $dedupeKey,
            'decision' => $deliver ? 'deliver' : 'suppress',
            'reasons' => $reasons,
            'delivery_status' => $deliver ? 'pending' : 'suppressed',
            'delivery_intent' => $intent,
        ];
    }

    private static function tick(array $raw): array
    {
        self::fields($raw, ['version', 'source', 'schedule_ref', 'day', 'freshness'], 'SchedulerTick');
        if (($raw['version'] ?? null) !== 1 || ($raw['source'] ?? null) !== 'scheduler') {
            throw new InvalidArgumentException('SchedulerTick invalid.');
        }

        return [
            'version' => 1,
            'source' => 'scheduler',
            'schedule_ref' => self::ref($raw['schedule_ref'], 'schedule_ref'),
            'day' => self::day($raw['day']),
            'freshness' => self::enum($raw['freshness'], self::FRESHNESS, 'freshness'),
        ];
    }

    private static function policy(array $raw): array
    {
        self::fields(
            $raw,
            ['version', 'policy_ref', 'enabled', 'channel', 'channel_available'],
            'DeliveryPolicy'
        );
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('DeliveryPolicy version invalid.');
        }
        if (!is_bool($raw['enabled'] ?? null) || !is_bool($raw['channel_available'] ?? null)) {
            throw new InvalidArgumentException('DeliveryPolicy flags invalid.');
        }

        return [
            'version' => 1,
            'policy_ref' => self::ref($raw['policy_ref'], 'policy_ref'),
            'enabled' => $raw['enabled'],
            'channel' => self::enum($raw['channel'], self::CHANNELS, 'channel'),
            'channel_available' => $raw['channel_available'],
        ];
    }

    private static function previous(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        self::fields(
            $raw,
            ['version', 'delivery_ref', 'day', 'briefing_fingerprint', 'status'],
            'DeliveryReceipt'
        );
        if (($raw['version'] ?? null) !== 1 || ($raw['status'] ?? null) !== 'delivered') {
            throw new InvalidArgumentException('DeliveryReceipt invalid.');
        }

        $day = self::day($raw['day']);
        $fingerprint = self::sha256($raw['briefing_fingerprint'], 'briefing_fingerprint');
        $expectedRef = 'controlbot:briefing-delivery/' . hash('sha256', $day . '|' . $fingerprint);
        $deliveryRef = self::ref($raw['delivery_ref'], 'delivery_ref');
        if (!hash_equals($expectedRef, $deliveryRef)) {
            throw new InvalidArgumentException('DeliveryReceipt provenance invalid.');
        }

        return [
            'version' => 1,
            'delivery_ref' => $deliveryRef,
            'day' => $day,
            'briefing_fingerprint' => $fingerprint,
            'status' => 'delivered',
        ];
    }

    private static function fields(array $raw, array $expected, string $label): void
    {
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function day(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) !== 1) {
            throw new InvalidArgumentException('day invalid.');
        }
        if (!checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw new InvalidArgumentException('day invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\\/#@-]{0,479}$/D', $value) !== 1
            || preg_match('/(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)/i', $value) === 1
        ) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
