<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FactoryOrchestratorLiveCache
{
    private const MAX_CACHE_BYTES = 2_000_000;

    public static function remember(
        string $path,
        int $now,
        int $ttlSeconds,
        int $staleSeconds,
        int $refreshBudgetSeconds,
        callable $refresh,
    ): array {
        self::config($path, $now, $ttlSeconds, $staleSeconds, $refreshBudgetSeconds);
        $cached = self::read($path);

        if ($cached !== null) {
            $age = $now - $cached['cached_at'];
            if ($age < 0) {
                throw new RuntimeException('Orchestrator cache clock invalid.');
            }
            if ($age <= $ttlSeconds) {
                return self::result('fresh', $cached, $age);
            }
            if ($age <= $staleSeconds && $now < $cached['next_refresh_at']) {
                return self::result('stale', $cached, $age);
            }
        }

        try {
            $payload = self::payload($refresh());
            $record = [
                'version' => 1,
                'cached_at' => $now,
                'next_refresh_at' => $now + $refreshBudgetSeconds,
                'payload' => $payload,
            ];
            self::write($path, $record);
            return self::result('refreshed', $record, 0);
        } catch (Throwable $error) {
            if ($cached !== null) {
                $age = $now - $cached['cached_at'];
                if ($age >= 0 && $age <= $staleSeconds) {
                    $cached['next_refresh_at'] = $now + $refreshBudgetSeconds;
                    self::write($path, $cached);
                    return self::result('stale', $cached, $age);
                }
            }
            throw new RuntimeException('Orchestrator snapshot unavailable.', 0, $error);
        }
    }

    private static function result(string $status, array $record, int $age): array
    {
        return [
            'status' => $status,
            'payload' => $record['payload'],
            'cached_at' => $record['cached_at'],
            'age_seconds' => $age,
            'next_refresh_at' => $record['next_refresh_at'],
        ];
    }

    private static function config(
        string $path,
        int $now,
        int $ttlSeconds,
        int $staleSeconds,
        int $refreshBudgetSeconds,
    ): void {
        if (
            $path === ''
            || $path[0] !== '/'
            || str_contains($path, "\0")
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $path) === 1
        ) {
            throw new InvalidArgumentException('Orchestrator cache path invalid.');
        }
        if (
            $now < 1
            || $ttlSeconds < 1
            || $staleSeconds < $ttlSeconds
            || $refreshBudgetSeconds < 1
            || $ttlSeconds > 3600
            || $staleSeconds > 86400
            || $refreshBudgetSeconds > 3600
        ) {
            throw new InvalidArgumentException('Orchestrator cache policy invalid.');
        }
    }

    private static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_CACHE_BYTES) {
            throw new RuntimeException('Orchestrator cache file invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Orchestrator cache read failed.');
        }
        try {
            $record = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('Orchestrator cache JSON invalid.', 0, $error);
        }
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException('Orchestrator cache record invalid.');
        }
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== ['cached_at', 'next_refresh_at', 'payload', 'version']) {
            throw new RuntimeException('Orchestrator cache fields invalid.');
        }
        if (
            $record['version'] !== 1
            || !is_int($record['cached_at'])
            || $record['cached_at'] < 1
            || !is_int($record['next_refresh_at'])
            || $record['next_refresh_at'] < $record['cached_at']
        ) {
            throw new RuntimeException('Orchestrator cache metadata invalid.');
        }
        $record['payload'] = self::payload($record['payload']);
        return $record;
    }

    private static function payload(mixed $payload): array
    {
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Orchestrator payload invalid.');
        }
        if (
            ($payload['version'] ?? null) !== 1
            || ($payload['read_only'] ?? null) !== true
            || !is_string($payload['fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $payload['fingerprint']) !== 1
        ) {
            throw new InvalidArgumentException('Orchestrator payload provenance invalid.');
        }
        return $payload;
    }

    private static function write(string $path, array $record): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Orchestrator cache directory unavailable.');
        }
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_CACHE_BYTES) {
            throw new RuntimeException('Orchestrator cache payload too large.');
        }
        $temporary = tempnam($directory, '.orchestrator-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Orchestrator cache temp file unavailable.');
        }
        try {
            $written = file_put_contents($temporary, $encoded, LOCK_EX);
            if ($written !== strlen($encoded) || !rename($temporary, $path)) {
                throw new RuntimeException('Orchestrator cache write failed.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
