<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class AgentRuntime
{
    private const ACCOUNT_STATUSES = ['active', 'rate_limited', 'requires_login', 'offline', 'paused'];
    private const SESSION_STATES = [
        'offline', 'idle', 'assigned', 'working', 'waiting_tool', 'waiting_human',
        'reviewing', 'blocked', 'rate_limited', 'requires_login', 'paused', 'stopping', 'failed',
    ];
    private const ASSIGNMENT_STATES = ['assigned', 'running', 'waiting', 'review', 'blocked', 'done'];
    private const OBSERVED_CAPACITY_STATES = ['healthy', 'saturated', 'rate_limited', 'requires_login', 'offline', 'unknown'];

    private static function fields(array $record, array $expected, string $label): void
    {
        $actual = array_keys($record);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function safeText(mixed $value, string $label, int $max = 160): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|session[_ -]?token)\s*[:=]\s*\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function slug(mixed $value, string $label): string
    {
        $value = self::safeText($value, $label, 80);
        if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label, int $max = 180): string
    {
        $value = self::safeText($value, $label, $max);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function nullableText(mixed $value, string $label, int $max): ?string
    {
        return $value === null ? null : self::safeText($value, $label, $max);
    }

    private static function workRef(mixed $value, string $label): string
    {
        $value = self::safeText($value, $label, 180);
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function evidenceRef(mixed $value): string
    {
        $value = self::safeText($value, 'evidence_ref', 240);
        if (str_starts_with($value, 'controlbot:')) {
            return self::ref($value, 'evidence_ref', 240);
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'github.com'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($value, '%')) {
            throw new InvalidArgumentException('evidence_ref invalid.');
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || preg_match('/(?:^|\/)(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)(?:\/|$)/i', $path) === 1) {
            throw new InvalidArgumentException('evidence_ref invalid.');
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

    private static function capabilities(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 32) {
            throw new InvalidArgumentException('capabilities invalid.');
        }
        $set = [];
        foreach ($values as $value) {
            $capability = self::slug($value, 'capability');
            if (isset($set[$capability])) {
                throw new InvalidArgumentException('capabilities duplicated.');
            }
            $set[$capability] = true;
        }
        $result = array_keys($set);
        sort($result);
        return $result;
    }

    public static function provider(array $record): array
    {
        self::fields($record, ['version', 'provider_id', 'adapter'], 'Provider');
        if ($record['version'] !== 1) {
            throw new InvalidArgumentException('Provider version invalid.');
        }
        return [
            'version' => 1,
            'provider_id' => self::slug($record['provider_id'], 'provider_id'),
            'adapter' => self::slug($record['adapter'], 'adapter'),
        ];
    }

    public static function account(array $record): array
    {
        self::fields($record, ['version', 'account_id', 'provider_id', 'account_alias', 'plan', 'capacity', 'status'], 'Account');
        if ($record['version'] !== 1 || !is_string($record['status']) || !in_array($record['status'], self::ACCOUNT_STATUSES, true)) {
            throw new InvalidArgumentException('Account invalid.');
        }
        return [
            'version' => 1,
            'account_id' => self::ref($record['account_id'], 'account_id'),
            'provider_id' => self::slug($record['provider_id'], 'provider_id'),
            'account_alias' => self::safeText($record['account_alias'], 'account_alias', 80),
            'plan' => self::safeText($record['plan'], 'plan', 80),
            'capacity' => self::positiveInt($record['capacity'], 'capacity'),
            'status' => $record['status'],
        ];
    }

    public static function agent(array $record): array
    {
        self::fields($record, ['version', 'agent_id', 'role', 'capabilities'], 'Agent');
        if ($record['version'] !== 1) {
            throw new InvalidArgumentException('Agent version invalid.');
        }
        return [
            'version' => 1,
            'agent_id' => self::ref($record['agent_id'], 'agent_id'),
            'role' => self::slug($record['role'], 'role'),
            'capabilities' => self::capabilities($record['capabilities']),
        ];
    }

    public static function assignment(array $record): array
    {
        self::fields($record, ['version', 'assignment_id', 'session_id', 'project_id', 'source_ref', 'status'], 'Assignment');
        if ($record['version'] !== 1 || !is_string($record['status']) || !in_array($record['status'], self::ASSIGNMENT_STATES, true)) {
            throw new InvalidArgumentException('Assignment invalid.');
        }
        return [
            'version' => 1,
            'assignment_id' => self::ref($record['assignment_id'], 'assignment_id'),
            'session_id' => self::ref($record['session_id'], 'session_id'),
            'project_id' => self::ref($record['project_id'], 'project_id'),
            'source_ref' => self::ref($record['source_ref'], 'source_ref'),
            'status' => $record['status'],
        ];
    }

    public static function session(array $record): array
    {
        self::fields($record, [
            'version', 'session_id', 'agent_id', 'account_id', 'profile_alias', 'tab_id', 'status',
            'assignment_id', 'last_heartbeat_at', 'mode', 'repository', 'issue_number',
        ], 'Session');
        if ($record['version'] !== 1 || !is_string($record['status']) || !in_array($record['status'], self::SESSION_STATES, true)) {
            throw new InvalidArgumentException('Session invalid.');
        }
        $heartbeat = $record['last_heartbeat_at'];
        if ($heartbeat !== null && (!is_int($heartbeat) || $heartbeat < 0)) {
            throw new InvalidArgumentException('last_heartbeat_at invalid.');
        }
        $repository = $record['repository'];
        $issue = $record['issue_number'];
        if (($repository === null) !== ($issue === null)) {
            throw new InvalidArgumentException('repository and issue_number must be paired.');
        }
        if ($repository !== null && (!is_string($repository) || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1)) {
            throw new InvalidArgumentException('repository invalid.');
        }
        if ($issue !== null && (!is_int($issue) || $issue < 1)) {
            throw new InvalidArgumentException('issue_number invalid.');
        }
        return [
            'version' => 1,
            'session_id' => self::ref($record['session_id'], 'session_id'),
            'agent_id' => self::ref($record['agent_id'], 'agent_id'),
            'account_id' => self::ref($record['account_id'], 'account_id'),
            'profile_alias' => self::safeText($record['profile_alias'], 'profile_alias', 80),
            'tab_id' => self::ref($record['tab_id'], 'tab_id'),
            'status' => $record['status'],
            'assignment_id' => self::nullableRef($record['assignment_id'], 'assignment_id'),
            'last_heartbeat_at' => $heartbeat,
            'mode' => self::slug($record['mode'], 'mode'),
            'repository' => $repository,
            'issue_number' => $issue,
        ];
    }

    public static function sessionHealth(array $record, int $now, int $staleAfter = 90, int $offlineAfter = 300): array
    {
        $session = self::session($record);
        if ($now < 0 || $staleAfter < 1 || $offlineAfter <= $staleAfter || $offlineAfter > 3600) {
            throw new InvalidArgumentException('Session health thresholds invalid.');
        }
        $health = 'offline';
        $heartbeat = $session['last_heartbeat_at'];
        if ($heartbeat !== null && $heartbeat <= $now) {
            $age = $now - $heartbeat;
            $health = $age <= $staleAfter ? 'healthy' : ($age <= $offlineAfter ? 'stale' : 'offline');
        }
        if (in_array($session['status'], ['offline', 'rate_limited', 'requires_login', 'paused', 'stopping', 'failed'], true)) {
            $health = 'offline';
        }
        return [
            'session_id' => $session['session_id'],
            'health' => $health,
            'assignment_id' => $session['assignment_id'],
            'eligible' => $health === 'healthy',
        ];
    }

    public static function capacitySnapshot(array $accountRecord, array $sessionRecords): array
    {
        $account = self::account($accountRecord);
        $sessionIds = self::sessionIds($account, $sessionRecords);
        if (count($sessionIds) > $account['capacity']) {
            throw new InvalidArgumentException('Account capacity exceeded.');
        }
        $free = $account['status'] === 'active' ? $account['capacity'] - count($sessionIds) : 0;
        return [
            'account_id' => $account['account_id'],
            'eligible' => $account['status'] === 'active' && $free > 0,
            'free_capacity' => $free,
            'session_ids' => $sessionIds,
        ];
    }

    public static function observedCapacitySnapshot(
        array $accountRecord,
        array $sessionRecords,
        array $observation,
    ): array {
        $account = self::account($accountRecord);
        $sessionIds = self::sessionIds($account, $sessionRecords, null);
        self::fields($observation, [
            'version', 'state', 'total_capacity', 'occupied_capacity', 'observed_at',
        ], 'ObservedCapacity');
        if ($observation['version'] !== 1
            || !is_string($observation['state'])
            || !in_array($observation['state'], self::OBSERVED_CAPACITY_STATES, true)) {
            throw new InvalidArgumentException('Observed capacity invalid.');
        }
        $total = self::nonNegativeInt($observation['total_capacity'], 'total_capacity');
        $occupied = self::nonNegativeInt($observation['occupied_capacity'], 'occupied_capacity');
        $observedAt = self::nonNegativeInt($observation['observed_at'], 'observed_at');

        $operational = $account['status'] === 'active' && $observation['state'] === 'healthy';
        $effectiveOccupied = max($occupied, count($sessionIds));
        $free = $operational ? max(0, $total - $effectiveOccupied) : 0;

        return [
            'account_id' => $account['account_id'],
            'declared_capacity' => $account['capacity'],
            'observed_state' => $observation['state'],
            'observed_total_capacity' => $total,
            'observed_occupied_capacity' => $occupied,
            'effective_occupied_capacity' => $effectiveOccupied,
            'observed_at' => $observedAt,
            'eligible' => $free > 0,
            'free_capacity' => $free,
            'session_ids' => $sessionIds,
        ];
    }

    private static function sessionIds(array $account, array $sessionRecords, ?int $maxSessions = 64): array
    {
        if (!array_is_list($sessionRecords)
            || ($maxSessions !== null && count($sessionRecords) > $maxSessions)) {
            throw new InvalidArgumentException('sessions invalid.');
        }
        $ids = [];
        foreach ($sessionRecords as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('session invalid.');
            }
            $session = self::session($record);
            if ($session['account_id'] !== $account['account_id']) {
                throw new InvalidArgumentException('Session belongs to another account.');
            }
            if (in_array($session['session_id'], $ids, true)) {
                throw new InvalidArgumentException('Session duplicated.');
            }
            $ids[] = $session['session_id'];
        }
        sort($ids);
        return $ids;
    }

    private static function nonNegativeInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    public static function handoff(array $record): array
    {
        self::fields($record, [
            'version', 'handoff_id', 'assignment_id', 'from_session_id', 'to_session_id',
            'objective', 'issue_ref', 'pr_ref', 'sha', 'last_result', 'evidence_ref', 'blocker', 'next_action',
        ], 'Handoff');
        if ($record['version'] !== 1 || !is_string($record['sha']) || preg_match('/^[0-9a-f]{40}$/D', $record['sha']) !== 1) {
            throw new InvalidArgumentException('Handoff invalid.');
        }
        return [
            'version' => 1,
            'handoff_id' => self::ref($record['handoff_id'], 'handoff_id'),
            'assignment_id' => self::ref($record['assignment_id'], 'assignment_id'),
            'from_session_id' => self::ref($record['from_session_id'], 'from_session_id'),
            'to_session_id' => self::nullableRef($record['to_session_id'], 'to_session_id'),
            'objective' => self::safeText($record['objective'], 'objective', 240),
            'issue_ref' => self::workRef($record['issue_ref'], 'issue_ref'),
            'pr_ref' => $record['pr_ref'] === null ? null : self::workRef($record['pr_ref'], 'pr_ref'),
            'sha' => $record['sha'],
            'last_result' => self::safeText($record['last_result'], 'last_result', 400),
            'evidence_ref' => self::evidenceRef($record['evidence_ref']),
            'blocker' => self::nullableText($record['blocker'], 'blocker', 240),
            'next_action' => self::safeText($record['next_action'], 'next_action', 300),
        ];
    }
}
