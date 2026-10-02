<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FactoryOrchestratorLiveCache
{
    private const MAX_BYTES = 2000000;

    public static function remember(string $path, int $now, int $ttl, int $stale, int $budget, callable $refresh): array
    {
        if ($path === '' || $path[0] !== '/' || $ttl < 1 || $stale < $ttl || $budget < 1 || $stale > 86400) {
            throw new InvalidArgumentException('cache policy invalid');
        }
        $cached = self::read($path);
        if ($cached !== null) {
            $age = $now - $cached['cached_at'];
            if ($age < 0) throw new RuntimeException('cache clock invalid');
            if ($age <= $ttl) return self::result('fresh', $cached, $age);
            if ($age <= $stale && $now < $cached['next_refresh_at']) return self::result('stale', $cached, $age);
        }
        try {
            $payload = self::payload($refresh());
            $record = ['cached_at' => $now, 'next_refresh_at' => $now + $budget, 'payload' => $payload];
            self::write($path, $record);
            return self::result('refreshed', $record, 0);
        } catch (Throwable $error) {
            if ($cached !== null) {
                $age = $now - $cached['cached_at'];
                if ($age >= 0 && $age <= $stale) {
                    $cached['next_refresh_at'] = $now + $budget;
                    self::write($path, $cached);
                    return self::result('stale', $cached, $age);
                }
            }
            throw new RuntimeException('snapshot unavailable', 0, $error);
        }
    }

    private static function read(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::MAX_BYTES) throw new RuntimeException('cache invalid');
        $row = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($row) || !is_int($row['cached_at'] ?? null) || !is_int($row['next_refresh_at'] ?? null)) {
            throw new RuntimeException('cache invalid');
        }
        $row['payload'] = self::payload($row['payload'] ?? null);
        return $row;
    }

    private static function payload(mixed $payload): array
    {
        if (!is_array($payload) || array_is_list($payload)
            || ($payload['version'] ?? null) !== 1 || ($payload['read_only'] ?? null) !== true
            || !is_string($payload['fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $payload['fingerprint']) !== 1) {
            throw new InvalidArgumentException('payload invalid');
        }
        return $payload;
    }

    private static function write(string $path, array $record): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('cache directory unavailable');
        $raw = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw) > self::MAX_BYTES) throw new RuntimeException('cache too large');
        $tmp = tempnam($dir, '.orchestrator-');
        if (!is_string($tmp)) throw new RuntimeException('cache temp unavailable');
        try {
            if (file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw) || !rename($tmp, $path)) {
                throw new RuntimeException('cache write failed');
            }
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private static function result(string $status, array $record, int $age): array
    {
        return ['status' => $status, 'payload' => $record['payload'], 'age_seconds' => $age];
    }
}
