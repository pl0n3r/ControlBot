<?php
declare(strict_types=1);

namespace ControlBot\Business;

use Throwable;

require_once __DIR__ . '/FactoryLiveSnapshot.php';
require_once __DIR__ . '/FactoryLiveOrchestratorSnapshot.php';
require_once __DIR__ . '/FactoryOrchestratorLiveEndpoint.php';

final class FactoryOrchestratorWebEntrypoint
{
    private const SNAPSHOT_MAX_BYTES = 2_000_000;
    private const SNAPSHOT_MAX_AGE_SECONDS = 300;
    private const CACHE_TTL_SECONDS = 10;
    private const CACHE_STALE_SECONDS = 60;
    private const REFRESH_BUDGET_SECONDS = 20;

    public static function handle(
        array $server,
        array $environment,
        int $now,
        ?string $snapshotPath = null,
        ?string $cachePath = null,
    ): array {
        $snapshotPath ??= dirname(__DIR__) . '/var/orchestrator-live.json';
        $cachePath ??= self::defaultCachePath($snapshotPath);

        $request = [
            'method' => self::stringOrNull($server['REQUEST_METHOD'] ?? null),
            'path' => self::requestPath($server['REQUEST_URI'] ?? null),
            'remote_user' => self::stringOrNull($server['REMOTE_USER'] ?? null),
        ];
        $config = [
            'owner_login' => self::stringOrEmpty(
                $environment['CONTROLBOT_OWNER_LOGIN'] ?? null
            ),
            'cache_path' => $cachePath,
            'ttl_seconds' => self::CACHE_TTL_SECONDS,
            'stale_seconds' => self::CACHE_STALE_SECONDS,
            'refresh_budget_seconds' => self::REFRESH_BUDGET_SECONDS,
        ];

        return FactoryOrchestratorLiveEndpoint::handle(
            $request,
            $config,
            $now,
            static fn (): array => self::localSnapshot($snapshotPath, $now),
        );
    }

    public static function localSnapshot(string $path, int $now): array
    {
        try {
            if ($now < 1 || ! self::isAbsolutePath($path) || ! is_file($path)) {
                return self::unknownSnapshot($now);
            }
            $size = filesize($path);
            if (! is_int($size) || $size < 2 || $size > self::SNAPSHOT_MAX_BYTES) {
                return self::unknownSnapshot($now);
            }
            $raw = file_get_contents($path);
            if (! is_string($raw) || strlen($raw) !== $size) {
                return self::unknownSnapshot($now);
            }
            $snapshot = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || array_is_list($snapshot)) {
                return self::unknownSnapshot($now);
            }
            $observedAt = $snapshot['observed_at'] ?? null;
            if (
                ! is_int($observedAt)
                || $observedAt < 1
                || $observedAt > $now
                || ($now - $observedAt) > self::SNAPSHOT_MAX_AGE_SECONDS
            ) {
                return self::unknownSnapshot($now);
            }
            return FactoryLiveOrchestratorSnapshot::build($snapshot, $now);
        } catch (Throwable) {
            return self::unknownSnapshot($now);
        }
    }

    private static function unknownSnapshot(int $now): array
    {
        $canonical = FactoryLiveSnapshot::build([], $now);
        return FactoryLiveOrchestratorSnapshot::build($canonical, $now);
    }

    private static function requestPath(mixed $uri): ?string
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > 2048) {
            return null;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            return null;
        }
        return $path;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private static function defaultCachePath(string $snapshotPath): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'controlbot-orchestrator-live-'
            . hash('sha256', $snapshotPath)
            . '.json';
    }
}
