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

    private static function ref(mixed $value, string $label, int $max = 160): string
    {
        if (!is_string($value) || strlen($value) > $max
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
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
}
