<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use InvalidArgumentException;

final class RunnerGateway
{
    private const MODES = ['idle', 'busy', 'paused', 'offline'];

    private static function fields(array $record, array $expected): void
    {
        $actual = array_keys($record);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Runner record fields invalid.');
        }
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Runner identifier invalid.');
        }
        return $value;
    }

    private static function identifiers(mixed $values, int $limit): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $limit) {
            throw new InvalidArgumentException('Runner list invalid.');
        }
        $ids = [];
        foreach ($values as $value) {
            $id = self::identifier($value);
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Duplicate runner list entry.');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    public static function identity(array $record): array
    {
        self::fields($record, [
            'version', 'runner_id', 'runtime', 'runtime_version', 'platform', 'capabilities',
        ]);
        if ($record['version'] !== 1 || !is_string($record['runtime_version'])
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $record['runtime_version']) !== 1) {
            throw new InvalidArgumentException('Runner identity version invalid.');
        }
        $id = self::identifier($record['runner_id']);
        $runtime = self::identifier($record['runtime']);
        $platform = self::identifier($record['platform']);
        $capabilities = self::identifiers($record['capabilities'], 32);
        if ($capabilities === []) {
            throw new InvalidArgumentException('Runner needs an explicit capability.');
        }
        return [
            'version' => 1,
            'runner_id' => $id,
            'runtime' => $runtime,
            'runtime_version' => $record['runtime_version'],
            'platform' => $platform,
            'capabilities' => $capabilities,
        ];
    }

    public static function heartbeat(array $record): array
    {
        self::fields($record, [
            'version', 'runner_id', 'seen_at', 'mode', 'capacity_total',
            'capacity_used', 'active_sessions',
        ]);
        if ($record['version'] !== 1 || !is_int($record['seen_at'])
            || $record['seen_at'] < 0 || !in_array($record['mode'], self::MODES, true)
            || !is_int($record['capacity_total']) || $record['capacity_total'] < 1
            || $record['capacity_total'] > 64 || !is_int($record['capacity_used'])
            || $record['capacity_used'] < 0 || $record['capacity_used'] > $record['capacity_total']) {
            throw new InvalidArgumentException('Runner heartbeat invalid.');
        }
        $id = self::identifier($record['runner_id']);
        $sessions = self::identifiers($record['active_sessions'], 64);
        if (count($sessions) > $record['capacity_used']) {
            throw new InvalidArgumentException('Runner sessions exceed used capacity.');
        }
        return [
            'version' => 1,
            'runner_id' => $id,
            'seen_at' => $record['seen_at'],
            'mode' => $record['mode'],
            'capacity_total' => $record['capacity_total'],
            'capacity_used' => $record['capacity_used'],
            'active_sessions' => $sessions,
        ];
    }

    public static function health(
        array $identity,
        ?array $heartbeat,
        int $now,
        int $ttl,
        array $assignmentIds = [],
    ): array {
        $runner = self::identity($identity);
        if ($now < 0 || $ttl < 1 || $ttl > 3600) {
            throw new InvalidArgumentException('Runner clock or TTL invalid.');
        }
        $assignments = self::identifiers($assignmentIds, 64);
        $signal = $heartbeat === null ? null : self::heartbeat($heartbeat);
        if ($signal !== null && $signal['runner_id'] !== $runner['runner_id']) {
            throw new InvalidArgumentException('Runner heartbeat identity mismatch.');
        }
        $status = 'offline';
        $free = 0;
        if ($signal !== null && !in_array($signal['mode'], ['offline', 'paused'], true)
            && $signal['seen_at'] <= $now) {
            $age = $now - $signal['seen_at'];
            $status = $age <= $ttl ? 'healthy' : ($age <= $ttl * 3 ? 'stale' : 'offline');
            if ($status === 'healthy') {
                $free = $signal['capacity_total'] - $signal['capacity_used'];
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
