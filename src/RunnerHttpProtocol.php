<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use InvalidArgumentException;
use JsonException;

final class RunnerHttpProtocol
{
    private const MAX_ENVELOPE_BYTES = 65_536;
    private const MAX_PAYLOAD_BYTES = 32_768;

    private const ROUTES = [
        '/v1/runner/heartbeat' => 'heartbeat',
        '/v1/runner/poll' => 'poll',
        '/v1/runner/ack' => 'ack',
        '/v1/runner/event' => 'event',
    ];

    private static function fields(array $record, array $expected, string $label): void
    {
        $actual = array_keys($record);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function uuid(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return strtolower($value);
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function safeRef(mixed $value, string $label, int $max = 160): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        self::assertSafeString($value, $label);
        return $value;
    }

    private static function assertSafeString(string $value, string $label): void
    {
        if (preg_match(
            '/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\s*[:=]\s*\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i',
            $value
        ) === 1) {
            throw new InvalidArgumentException($label . ' contains sensitive material.');
        }
    }

    private static function assertSecretFree(mixed $value, string $label = 'payload', int $depth = 0): void
    {
        if ($depth > 8) {
            throw new InvalidArgumentException($label . ' nesting invalid.');
        }

        if (is_string($value)) {
            self::assertSafeString($value, $label);
            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (count($value) > 128) {
            throw new InvalidArgumentException($label . ' collection too large.');
        }

        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match('/(?:^|[_-])(password|passwd|token|secret|cookie|authorization|private[_-]?key|api[_-]?key|dsn)(?:$|[_-])/i', $key) === 1) {
                throw new InvalidArgumentException($label . ' contains sensitive field.');
            }
            self::assertSecretFree($item, $label . '.' . (string) $key, $depth + 1);
        }
    }

    private static function assertJsonBounded(array $value, int $maxBytes, string $label): void
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException($label . ' is not JSON-safe.');
        }

        if (strlen($encoded) > $maxBytes) {
            throw new InvalidArgumentException($label . ' exceeds byte limit.');
        }
    }

    private static function poll(array $payload): array
    {
        self::fields(
            $payload,
            ['version', 'runner_id', 'session_id', 'generation', 'requested_at'],
            'RunnerPoll'
        );
        if (($payload['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('RunnerPoll version invalid.');
        }

        return [
            'version' => 1,
            'runner_id' => self::uuid($payload['runner_id'], 'runner_id'),
            'session_id' => self::safeRef($payload['session_id'], 'session_id'),
            'generation' => self::positiveInt($payload['generation'], 'generation'),
            'requested_at' => self::nonNegativeInt($payload['requested_at'], 'requested_at'),
        ];
    }

    private static function ack(array $payload): array
    {
        self::fields(
            $payload,
            ['version', 'order_id', 'attempt_id', 'runner_id', 'generation', 'acknowledged_at'],
            'RunnerAck'
        );
        if (($payload['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('RunnerAck version invalid.');
        }

        return [
            'version' => 1,
            'order_id' => self::uuid($payload['order_id'], 'order_id'),
            'attempt_id' => self::uuid($payload['attempt_id'], 'attempt_id'),
            'runner_id' => self::uuid($payload['runner_id'], 'runner_id'),
            'generation' => self::positiveInt($payload['generation'], 'generation'),
            'acknowledged_at' => self::nonNegativeInt($payload['acknowledged_at'], 'acknowledged_at'),
        ];
    }

    public static function request(array $envelope): array
    {
        self::fields($envelope, ['version', 'method', 'path', 'payload'], 'RunnerHttpEnvelope');
        if (($envelope['version'] ?? null) !== 1
            || ($envelope['method'] ?? null) !== 'POST'
            || !is_string($envelope['path'])
            || !array_key_exists($envelope['path'], self::ROUTES)
            || !is_array($envelope['payload'])) {
            throw new InvalidArgumentException('RunnerHttpEnvelope route invalid.');
        }

        self::assertJsonBounded($envelope, self::MAX_ENVELOPE_BYTES, 'RunnerHttpEnvelope');
        self::assertJsonBounded($envelope['payload'], self::MAX_PAYLOAD_BYTES, 'RunnerHttpPayload');
        self::assertSecretFree($envelope);

        $payload = match (self::ROUTES[$envelope['path']]) {
            'heartbeat' => RunnerGateway::heartbeat($envelope['payload']),
            'poll' => self::poll($envelope['payload']),
            'ack' => self::ack($envelope['payload']),
            'event' => RunnerGateway::event($envelope['payload']),
        };

        return [
            'version' => 1,
            'method' => 'POST',
            'path' => $envelope['path'],
            'payload' => $payload,
            'execution' => false,
        ];
    }
}
