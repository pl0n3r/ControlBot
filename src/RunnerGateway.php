<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use InvalidArgumentException;

final class RunnerGateway
{
    private const HEARTBEAT_STATUSES = ['ready', 'busy', 'draining', 'offline'];

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

    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function safeText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        if (preg_match(
            '/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\s*[:=]\s*\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i',
            $value
        ) === 1) {
            throw new InvalidArgumentException($label . ' contains sensitive material.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label, int $max = 160): string
    {
        $value = self::safeText($value, $label, $max);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label, int $max = PHP_INT_MAX): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function uniqueSlugs(mixed $values, int $limit): array
    {
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > $limit) {
            throw new InvalidArgumentException('Capabilities invalid.');
        }
        $result = [];
        foreach ($values as $value) {
            $id = self::slug($value, 'Capability');
            if (isset($result[$id])) {
                throw new InvalidArgumentException('Capabilities duplicated.');
            }
            $result[$id] = true;
        }
        $values = array_keys($result);
        sort($values);
        return $values;
    }

    private static function uniqueRefs(mixed $values, int $limit, string $label): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $limit) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $result = [];
        foreach ($values as $value) {
            $id = self::ref($value, $label);
            if (isset($result[$id])) {
                throw new InvalidArgumentException($label . ' duplicated.');
            }
            $result[$id] = true;
        }
        $values = array_keys($result);
        sort($values);
        return $values;
    }

    public static function identity(array $record): array
    {
        self::fields($record, [
            'version', 'runner_id', 'protocol_version', 'runtime', 'runtime_version',
            'platform', 'placement', 'capabilities', 'max_parallel',
        ], 'RunnerIdentity');
        if ($record['version'] !== 1 || $record['protocol_version'] !== 1
            || !is_string($record['runtime_version'])
            || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $record['runtime_version']) !== 1
            || !is_int($record['max_parallel']) || $record['max_parallel'] < 1 || $record['max_parallel'] > 64) {
            throw new InvalidArgumentException('RunnerIdentity version or capacity invalid.');
        }

        return [
            'version' => 1,
            'runner_id' => self::uuid($record['runner_id'], 'runner_id'),
            'protocol_version' => 1,
            'runtime' => self::slug($record['runtime'], 'runtime'),
            'runtime_version' => $record['runtime_version'],
            'platform' => self::slug($record['platform'], 'platform'),
            'placement' => self::slug($record['placement'], 'placement'),
            'capabilities' => self::uniqueSlugs($record['capabilities'], 64),
            'max_parallel' => $record['max_parallel'],
        ];
    }

    public static function heartbeat(array $record): array
    {
        self::fields($record, [
            'version', 'runner_id', 'sequence', 'observed_at', 'status',
            'capacity', 'active_sessions',
        ], 'RunnerHeartbeat');
        if ($record['version'] !== 1
            || !is_int($record['sequence']) || $record['sequence'] < 0
            || !is_int($record['observed_at']) || $record['observed_at'] < 0
            || !is_string($record['status']) || !in_array($record['status'], self::HEARTBEAT_STATUSES, true)
            || !is_array($record['capacity'])) {
            throw new InvalidArgumentException('RunnerHeartbeat invalid.');
        }
        self::fields($record['capacity'], ['max', 'active'], 'RunnerHeartbeat capacity');
        if (!is_int($record['capacity']['max']) || $record['capacity']['max'] < 1 || $record['capacity']['max'] > 64
            || !is_int($record['capacity']['active']) || $record['capacity']['active'] < 0
            || $record['capacity']['active'] > $record['capacity']['max']) {
            throw new InvalidArgumentException('RunnerHeartbeat capacity invalid.');
        }
        $sessions = self::uniqueRefs($record['active_sessions'], 64, 'active_session');
        if (count($sessions) > $record['capacity']['active']) {
            throw new InvalidArgumentException('active_sessions inconsistent.');
        }

        return [
            'version' => 1,
            'runner_id' => self::uuid($record['runner_id'], 'runner_id'),
            'sequence' => $record['sequence'],
            'observed_at' => $record['observed_at'],
            'status' => $record['status'],
            'capacity' => [
                'max' => $record['capacity']['max'],
                'active' => $record['capacity']['active'],
            ],
            'active_sessions' => $sessions,
        ];
    }

    public static function health(
        array $identity,
        ?array $heartbeat,
        int $now,
        int $staleAfterSeconds,
        array $assignmentIds = [],
        int $offlineAfterSeconds = 300,
    ): array {
        $runner = self::identity($identity);
        if ($now < 0
            || $staleAfterSeconds < 1
            || $offlineAfterSeconds <= $staleAfterSeconds
            || $offlineAfterSeconds > 3600) {
            throw new InvalidArgumentException('Runner clock or TTL invalid.');
        }
        $assignments = self::uniqueRefs($assignmentIds, 64, 'assignment_id');
        $signal = $heartbeat === null ? null : self::heartbeat($heartbeat);

        if ($signal !== null) {
            if ($signal['runner_id'] !== $runner['runner_id']) {
                throw new InvalidArgumentException('Runner heartbeat identity mismatch.');
            }
            if ($signal['capacity']['max'] !== $runner['max_parallel']) {
                throw new InvalidArgumentException('Runner heartbeat capacity mismatch.');
            }
        }

        $status = 'offline';
        $free = 0;
        if ($signal !== null && $signal['status'] !== 'offline' && $signal['observed_at'] <= $now) {
            $age = $now - $signal['observed_at'];
            $status = $age <= $staleAfterSeconds
                ? 'healthy'
                : ($age <= $offlineAfterSeconds ? 'stale' : 'offline');

            if ($status === 'healthy' && $signal['status'] !== 'draining') {
                $free = $signal['capacity']['max'] - $signal['capacity']['active'];
            }
        }

        return [
            'runner_id' => $runner['runner_id'],
            'status' => $status,
            'eligible' => $status === 'healthy' && $free > 0,
            'free_capacity' => $free,
            'assignment_ids' => $assignments,
        ];
    }

    public static function order(array $record): array
    {
        self::fields($record, [
            'version', 'order_id', 'attempt_id', 'generation', 'work_item_id',
            'runner_id', 'capability', 'attempt', 'scope', 'issued_at',
            'expires_at', 'instruction_ref',
        ], 'ExecutionOrder');
        if ($record['version'] !== 1) {
            throw new InvalidArgumentException('ExecutionOrder version invalid.');
        }
        $issuedAt = self::positiveInt($record['issued_at'], 'issued_at');
        $expiresAt = self::positiveInt($record['expires_at'], 'expires_at');
        if ($expiresAt <= $issuedAt || $expiresAt - $issuedAt > 86_400) {
            throw new InvalidArgumentException('ExecutionOrder TTL invalid.');
        }
        $instructionRef = self::ref($record['instruction_ref'], 'instruction_ref', 256);
        if (!str_starts_with($instructionRef, 'controlbot:')) {
            throw new InvalidArgumentException('instruction_ref must belong to ControlBot.');
        }

        return [
            'version' => 1,
            'order_id' => self::uuid($record['order_id'], 'order_id'),
            'attempt_id' => self::uuid($record['attempt_id'], 'attempt_id'),
            'generation' => self::positiveInt($record['generation'], 'generation', 1_000_000_000),
            'work_item_id' => self::ref($record['work_item_id'], 'work_item_id', 160),
            'runner_id' => self::uuid($record['runner_id'], 'runner_id'),
            'capability' => self::slug($record['capability'], 'capability'),
            'attempt' => self::positiveInt($record['attempt'], 'attempt', 10),
            'scope' => self::ref($record['scope'], 'scope', 160),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'instruction_ref' => $instructionRef,
        ];
    }

    public static function orderFingerprint(array $record): string
    {
        $order = self::order($record);
        return hash('sha256', json_encode($order, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function assertIdempotentOrder(array $existing, array $incoming): void
    {
        $left = self::order($existing);
        $right = self::order($incoming);
        if ($left['order_id'] !== $right['order_id']) {
            throw new InvalidArgumentException('order_id differs.');
        }
        if ($left['attempt_id'] !== $right['attempt_id'] || $left['generation'] !== $right['generation']
            || self::orderFingerprint($left) !== self::orderFingerprint($right)) {
            throw new InvalidArgumentException('Conflicting order_id reuse.');
        }
    }

    public static function event(array $record): array
    {
        self::fields($record, [
            'version', 'event_id', 'order_id', 'attempt_id', 'runner_id',
            'generation', 'sequence', 'state', 'occurred_at', 'evidence',
        ], 'ExecutionEvent');
        $states = [
            'accepted', 'started', 'heartbeat', 'progress', 'checkpoint',
            'waiting_human', 'blocked', 'failed', 'completed', 'cancelled',
        ];
        if ($record['version'] !== 1
            || !is_string($record['state'])
            || !in_array($record['state'], $states, true)
            || !is_array($record['evidence'])) {
            throw new InvalidArgumentException('ExecutionEvent invalid.');
        }

        return [
            'version' => 1,
            'event_id' => self::uuid($record['event_id'], 'event_id'),
            'order_id' => self::uuid($record['order_id'], 'order_id'),
            'attempt_id' => self::uuid($record['attempt_id'], 'attempt_id'),
            'runner_id' => self::uuid($record['runner_id'], 'runner_id'),
            'generation' => self::positiveInt($record['generation'], 'generation', 1_000_000_000),
            'sequence' => self::positiveInt($record['sequence'], 'sequence'),
            'state' => $record['state'],
            'occurred_at' => self::positiveInt($record['occurred_at'], 'occurred_at'),
            'evidence' => self::evidence($record['evidence']),
        ];
    }

    public static function assertEventOwnedByOrder(array $event, array $currentOrder): void
    {
        $event = self::event($event);
        $order = self::order($currentOrder);
        if ($event['order_id'] !== $order['order_id']
            || $event['attempt_id'] !== $order['attempt_id']
            || $event['generation'] !== $order['generation']
            || $event['runner_id'] !== $order['runner_id']) {
            throw new InvalidArgumentException('Stale or foreign execution event.');
        }
    }

    public static function assertEventTransition(array $previous, array $next, array $currentOrder): void
    {
        $left = self::event($previous);
        $right = self::event($next);
        self::assertEventOwnedByOrder($left, $currentOrder);
        self::assertEventOwnedByOrder($right, $currentOrder);

        if ($right['sequence'] !== $left['sequence'] + 1) {
            throw new InvalidArgumentException('Execution event sequence invalid.');
        }
        if ($right['occurred_at'] < $left['occurred_at']) {
            throw new InvalidArgumentException('Execution event timestamp regressed.');
        }
        if (!in_array($right['state'], self::transitionsFrom($left['state']), true)) {
            throw new InvalidArgumentException(
                'Invalid execution transition: ' . $left['state'] . ' -> ' . $right['state'] . '.'
            );
        }
    }

    private static function transitionsFrom(string $state): array
    {
        if (in_array($state, ['failed', 'completed', 'cancelled'], true)) {
            return [];
        }
        return match ($state) {
            'accepted' => ['started', 'cancelled', 'failed'],
            'waiting_human', 'blocked' => ['started', 'cancelled', 'failed'],
            default => ['heartbeat', 'progress', 'checkpoint', 'waiting_human', 'blocked', 'failed', 'completed', 'cancelled'],
        };
    }

    private static function evidence(array $record): array
    {
        self::fields($record, ['code', 'summary', 'ref'], 'ExecutionEvidence');
        $summary = self::safeText($record['summary'], 'evidence.summary', 500);
        $ref = null;
        if ($record['ref'] !== null) {
            $ref = self::safeText($record['ref'], 'evidence.ref', 512);
            if (str_contains($ref, '\\')
                || preg_match('/(?:^|[:\/._-])(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)(?:$|[:\/._=-])/i', $ref) === 1) {
                throw new InvalidArgumentException('evidence.ref invalid.');
            }
            if (str_starts_with($ref, 'https://')) {
                self::validateGithubEvidenceRef($ref);
            } elseif (!str_starts_with($ref, 'controlbot:')) {
                throw new InvalidArgumentException('evidence.ref invalid.');
            }
        }
        return [
            'code' => self::slug($record['code'], 'evidence.code'),
            'summary' => $summary,
            'ref' => $ref,
        ];
    }

    private static function validateGithubEvidenceRef(string $ref): void
    {
        $parts = parse_url($ref);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'github.com'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('evidence.ref invalid.');
        }

        $decoded = $parts['path'] ?? '/';
        for ($i = 0; $i < 3 && str_contains($decoded, '%'); $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (str_contains($decoded, '%')) {
            throw new InvalidArgumentException('evidence.ref invalid.');
        }
        $segments = explode('/', $decoded);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('evidence.ref invalid.');
        }
        self::safeText($decoded, 'evidence.ref', 512);
        if (preg_match('/(?:^|[:\/._-])(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)(?:$|[:\/._=-])/i', $decoded) === 1) {
            throw new InvalidArgumentException('evidence.ref invalid.');
        }
    }

}
