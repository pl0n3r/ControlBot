<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/WorkInventorySnapshot.php';

use InvalidArgumentException;

final class WorkInventoryDriftGuard
{
    private const CONSUMER_RE = '/^[a-z][a-z0-9._-]{0,79}$/D';

    public static function evaluate(
        array $snapshot,
        string $expectedSourceRef,
        int $now,
        int $maxAgeSeconds,
        array $consumers
    ): array {
        if ($now < 1 || $maxAgeSeconds < 1) {
            throw new InvalidArgumentException('Drift guard clock invalid.');
        }
        self::fields(
            $snapshot,
            ['version', 'source_ref', 'observed_at', 'freshness', 'projects'],
            'work_inventory'
        );

        $canonical = WorkInventorySnapshot::fromCanonical(
            ['version' => $snapshot['version'], 'projects' => $snapshot['projects']],
            self::text($snapshot['source_ref'], 'source_ref', 240),
            self::positiveInt($snapshot['observed_at'], 'observed_at'),
            self::text($snapshot['freshness'], 'freshness', 20),
        );
        $expectedSourceRef = self::text($expectedSourceRef, 'expected_source_ref', 240);
        if ($canonical['observed_at'] > $now) {
            throw new InvalidArgumentException('work_inventory observed_at is in the future.');
        }

        $consumerIds = self::consumers($consumers);
        $fingerprint = self::fingerprint($canonical);
        $ageSeconds = $now - $canonical['observed_at'];
        $reasons = [];
        if (!hash_equals($expectedSourceRef, $canonical['source_ref'])) {
            $reasons[] = 'source_mismatch';
        }
        if ($canonical['freshness'] !== 'current' || $ageSeconds > $maxAgeSeconds) {
            $reasons[] = 'stale';
        }

        $ready = $reasons === [];
        $status = $ready ? 'READY' : 'UNKNOWN';
        $projection = $ready ? $canonical : null;
        $consumerViews = [];
        foreach ($consumerIds as $consumerId) {
            $consumerViews[$consumerId] = [
                'status' => $status,
                'ready' => $ready,
                'projection_fingerprint' => $fingerprint,
                'projection' => $projection,
            ];
        }

        return [
            'version' => 1,
            'status' => $status,
            'ready' => $ready,
            'reasons' => $reasons,
            'source_ref' => $canonical['source_ref'],
            'expected_source_ref' => $expectedSourceRef,
            'observed_at' => $canonical['observed_at'],
            'age_seconds' => $ageSeconds,
            'freshness' => $canonical['freshness'],
            'projection_fingerprint' => $fingerprint,
            'consumers' => $consumerViews,
        ];
    }

    private static function consumers(array $consumers): array
    {
        if (!array_is_list($consumers) || $consumers === [] || count($consumers) > 20) {
            throw new InvalidArgumentException('consumers invalid.');
        }
        $out = [];
        foreach ($consumers as $consumer) {
            if (!is_string($consumer) || preg_match(self::CONSUMER_RE, $consumer) !== 1) {
                throw new InvalidArgumentException('consumer invalid.');
            }
            if (isset($out[$consumer])) {
                throw new InvalidArgumentException('consumer duplicated.');
            }
            $out[$consumer] = true;
        }
        return array_keys($out);
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function fields(mixed $raw, array $expected, string $label): void
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label.' fields invalid.');
        }
    }

    private static function fingerprint(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }
}
