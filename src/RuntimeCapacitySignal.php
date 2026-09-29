<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class RuntimeCapacitySignal
{
    private const SOURCES = ['autofactory', 'factoryrunner'];
    private const STATES = [
        'idle', 'assigned', 'working', 'reviewing', 'blocked',
        'rate_limited', 'requires_login', 'unavailable', 'offline', 'stale', 'unknown',
    ];
    private const SENSITIVE =
        '/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|'
        . '(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|'
        . 'api[_ -]?key|dsn|session[_ -]?token|transcript|chain[_ -]?of[_ -]?thought)\s*[:=]\s*\S+|'
        . '(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|'
        . '(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i';

    public static function normalize(array $raw): array
    {
        self::fields($raw, [
            'version', 'source', 'provider_id', 'account_ref', 'session_ref', 'state',
            'observed_at', 'heartbeat_at', 'total_capacity', 'occupied_capacity',
            'assignment_ref', 'generation', 'attempt', 'capabilities',
        ]);
        if ($raw['version'] !== 1) {
            throw new InvalidArgumentException('Runtime capacity signal version invalid.');
        }

        $source = self::enum($raw['source'], self::SOURCES, 'source');
        $state = self::enum($raw['state'], self::STATES, 'state');
        $observedAt = self::positiveInt($raw['observed_at'], 'observed_at');
        $heartbeatAt = self::nullableNonNegativeInt($raw['heartbeat_at'], 'heartbeat_at');
        if ($heartbeatAt !== null && $heartbeatAt > $observedAt) {
            throw new InvalidArgumentException('heartbeat_at cannot be in the future.');
        }

        $total = self::nullableNonNegativeInt($raw['total_capacity'], 'total_capacity');
        $occupied = self::nullableNonNegativeInt($raw['occupied_capacity'], 'occupied_capacity');
        if (($total === null) !== ($occupied === null) || ($total !== null && $occupied > $total)) {
            throw new InvalidArgumentException('Observed capacity invalid.');
        }
        if ($state === 'unknown' && ($total !== null || $heartbeatAt !== null)) {
            throw new InvalidArgumentException('Unknown state must not assert runtime health or capacity.');
        }

        $assignment = self::nullableRef($raw['assignment_ref'], 'assignment_ref');
        $assignmentRequired = in_array($state, ['assigned', 'working', 'reviewing', 'blocked'], true);
        if (($assignmentRequired && $assignment === null) || ($state === 'idle' && $assignment !== null)) {
            throw new InvalidArgumentException('assignment_ref is incoherent with state.');
        }

        $out = [
            'version' => 1,
            'source' => $source,
            'provider_id' => self::slug($raw['provider_id'], 'provider_id'),
            'account_ref' => self::ref($raw['account_ref'], 'account_ref'),
            'session_ref' => self::ref($raw['session_ref'], 'session_ref'),
            'state' => $state,
            'observed_at' => $observedAt,
            'heartbeat_at' => $heartbeatAt,
            'total_capacity' => $total,
            'occupied_capacity' => $occupied,
            'assignment_ref' => $assignment,
            'generation' => self::positiveInt($raw['generation'], 'generation'),
            'attempt' => self::positiveInt($raw['attempt'], 'attempt'),
            'capabilities' => self::capabilities($raw['capabilities']),
        ];
        self::secretFree($out);
        return $out;
    }

    private static function capabilities(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 64) {
            throw new InvalidArgumentException('capabilities invalid.');
        }
        $set = [];
        foreach ($raw as $value) {
            $capability = self::slug($value, 'capability');
            if (isset($set[$capability])) {
                throw new InvalidArgumentException('capabilities duplicated.');
            }
            $set[$capability] = true;
        }
        $out = array_keys($set);
        sort($out, SORT_STRING);
        return $out;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        self::secretFree($value);
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableNonNegativeInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function secretFree(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::secretFree($item);
            }
            return;
        }
        if (is_string($value) && (str_contains($value, '@') || preg_match(self::SENSITIVE, $value) === 1)) {
            throw new InvalidArgumentException('Runtime capacity signal contains sensitive material.');
        }
    }

    private static function fields(array $raw, array $expected): void
    {
        if (array_is_list($raw)) {
            throw new InvalidArgumentException('Runtime capacity signal invalid.');
        }
        $actual = array_keys($raw);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Runtime capacity signal fields invalid.');
        }
    }
}
