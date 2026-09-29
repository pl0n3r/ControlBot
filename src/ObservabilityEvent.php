<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use InvalidArgumentException;

final class ObservabilityEvent
{
    private const SOURCES = ['health', 'ci', 'deploy', 'agent'];
    private const SEVERITIES = ['info', 'warning', 'error', 'critical'];
    private const TYPES = [
        'health' => ['health_probe'],
        'ci' => ['ci_run', 'runner_capacity'],
        'deploy' => ['deploy', 'rollback'],
        'agent' => ['heartbeat', 'capacity'],
    ];
    private const PAYLOAD_KEYS = [
        'health' => ['status', 'http_status', 'version', 'sha', 'schema', 'reason_code'],
        'ci' => ['status', 'conclusion', 'runner_status', 'application_status', 'startup_failure', 'steps', 'reason_code', 'sha'],
        'deploy' => ['status', 'deployment_ref', 'release_ref', 'sha', 'rollback', 'reason_code'],
        'agent' => ['status', 'session_id', 'agent_id', 'capacity_total', 'capacity_used', 'reason_code'],
    ];

    public static function normalize(array $raw, int $now, array $ttlBySource): array
    {
        self::fields($raw, [
            'version', 'source', 'project_id', 'environment_id', 'repository_id',
            'severity', 'type', 'payload', 'occurred_at', 'received_at', 'correlation_keys',
        ], 'ObservabilityEvent');

        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Event version invalid.');
        }

        $source = self::enum($raw['source'], self::SOURCES, 'source');
        $type = self::enum($raw['type'], self::TYPES[$source], 'type');
        $occurred = self::positiveInt($raw['occurred_at'], 'occurred_at');
        $received = self::positiveInt($raw['received_at'], 'received_at');
        if ($received < $occurred || $now < $received) {
            throw new InvalidArgumentException('Event time ordering invalid.');
        }

        $payload = self::payload($source, $raw['payload']);
        $correlation = self::correlationKeys($raw['correlation_keys']);
        $canonical = [
            'version' => 1,
            'source' => $source,
            'project_id' => self::id($raw['project_id'], 'project_id'),
            'environment_id' => self::nullableRef($raw['environment_id'], 'environment_id'),
            'repository_id' => self::nullableRef($raw['repository_id'], 'repository_id'),
            'severity' => self::enum($raw['severity'], self::SEVERITIES, 'severity'),
            'type' => $type,
            'payload_allowlisted' => $payload,
            'occurred_at' => $occurred,
            'correlation_keys' => $correlation,
        ];
        $fingerprint = hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        return $canonical + [
            'fingerprint' => $fingerprint,
            'received_at' => $received,
            'freshness' => self::freshness($source, $occurred, $now, $ttlBySource),
        ];
    }

    private static function payload(string $source, mixed $raw): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > 16) {
            throw new InvalidArgumentException('Event payload invalid.');
        }

        $allowed = array_fill_keys(self::PAYLOAD_KEYS[$source], true);
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Event payload field invalid.');
            }
            $out[$key] = self::payloadValue($key, $value);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function payloadValue(string $key, mixed $value): string|int|bool|null
    {
        if (in_array($key, ['startup_failure', 'rollback'], true)) {
            if (!is_bool($value)) {
                throw new InvalidArgumentException('Event payload boolean invalid.');
            }
            return $value;
        }
        if (in_array($key, ['http_status', 'capacity_total', 'capacity_used', 'steps'], true)) {
            if ($key === 'steps' && $value === null) {
                return null;
            }
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Event payload integer invalid.');
            }
            if ($key === 'http_status' && ($value < 100 || $value > 599)) {
                throw new InvalidArgumentException('HTTP status invalid.');
            }
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Event payload string invalid.');
        }
        return self::safeText($value, 'payload.' . $key, 160, !in_array($key, ['sha','deployment_ref','release_ref','session_id','agent_id'], true));
    }

    private static function freshness(string $source, int $occurred, int $now, array $ttlBySource): string
    {
        $ttl = $ttlBySource[$source] ?? null;
        if ($ttl === null) {
            return 'unknown';
        }
        if (!is_int($ttl) || $ttl < 1 || $ttl > 86400) {
            throw new InvalidArgumentException('Freshness TTL invalid.');
        }
        return ($now - $occurred) <= $ttl ? 'fresh' : 'stale';
    }

    private static function correlationKeys(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 16) {
            throw new InvalidArgumentException('Correlation keys invalid.');
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)
                || preg_match('/^[a-z][a-z0-9._-]{0,31}:[A-Za-z0-9][A-Za-z0-9._\\/-]{0,95}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Correlation key invalid.');
            }
            self::safeText($value, 'correlation_key', 128, false);
            $out[$value] = true;
        }
        $keys = array_keys($out);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function safeText(string $value, string $label, int $max, bool $numericPii = true): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max
            || preg_match('/[\\x00-\\x1f\\x7f]/', $value) === 1
            || preg_match('/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i', $value) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9.-]*://[^/@:]+:[^/@]+@#D', $value) === 1
            || ($numericPii && preg_match('/(?:\\+?\\d[\\d .()\\-]{7,}\\d)/', $value) === 1)
            || preg_match('/(?:(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\\s*[:=]\\s*\\S+|bearer\\s+\\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i', $value) === 1
            || preg_match('/\\b(?:SELECT\\b.+\\bFROM\\b|INSERT\\s+INTO\\b|UPDATE\\b.+\\bSET\\b|DELETE\\s+FROM\\b)/i', $value) === 1) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }
        return $value;
    }

    private static function fields(mixed $raw, array $expected, string $label): void
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return self::safeText($value, $label, 80, false);
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::safeText(self::ref($value, $label), $label, 180, false);
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\\/#-]{0,179}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}
