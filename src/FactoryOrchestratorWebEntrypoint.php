<?php
declare(strict_types=1);

namespace ControlBot\Business;

use Throwable;

require_once __DIR__ . '/FactoryLiveOrchestratorSnapshot.php';
require_once __DIR__ . '/FactoryOrchestratorLiveEndpoint.php';

final class FactoryOrchestratorWebEntrypoint
{
    private const MAX_BYTES = 2_000_000;
    private const MAX_AGE = 300;
    private const TTL = 10;
    private const STALE = 60;
    private const REFRESH_BUDGET = 20;
    private const SNAPSHOT_ENV = 'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH';

    public static function handle(array $server, array $environment, int $now, ?string $snapshotPath = null, ?string $cachePath = null): array
    {
        $source = self::snapshotSource($server, $environment, $snapshotPath);
        $snapshotPath = $source['path'];
        $cachePath ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'controlbot-orchestrator-live-' . hash('sha256', $source['cache_identity']) . '.json';
        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && $uri !== '' && strlen($uri) <= 2048 ? parse_url($uri, PHP_URL_PATH) : null;
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) $path = null;

        return FactoryOrchestratorLiveEndpoint::handle([
            'method' => self::text($server['REQUEST_METHOD'] ?? null),
            'path' => $path,
            'remote_user' => self::text($server['REMOTE_USER'] ?? null),
        ], [
            'owner_login' => is_string($environment['CONTROLBOT_OWNER_LOGIN'] ?? null)
                ? trim($environment['CONTROLBOT_OWNER_LOGIN']) : '',
            'cache_path' => $cachePath,
            'ttl_seconds' => self::TTL,
            'stale_seconds' => self::STALE,
            'refresh_budget_seconds' => self::REFRESH_BUDGET,
        ], $now, static fn (): array => $source['valid']
            ? self::localSnapshot($snapshotPath, $now)
            : self::unknown($now));
    }

    public static function localSnapshot(string $path, int $now): array
    {
        try {
            if ($now < 1 || $path === '' || $path[0] !== DIRECTORY_SEPARATOR || !is_file($path)) return self::unknown($now);
            $size = filesize($path);
            if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) return self::unknown($now);
            $raw = file_get_contents($path);
            if (!is_string($raw) || strlen($raw) !== $size) return self::unknown($now);
            $snapshot = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $observed = is_array($snapshot) && !array_is_list($snapshot) ? ($snapshot['observed_at'] ?? null) : null;
            if (!is_int($observed) || $observed < 1 || $observed > $now || $now - $observed > self::MAX_AGE) return self::unknown($now);
            return FactoryLiveOrchestratorSnapshot::build($snapshot, $now);
        } catch (Throwable) {
            return self::unknown($now);
        }
    }

    private static function snapshotSource(array $server, array $environment, ?string $injected): array
    {
        if ($injected !== null) {
            return ['path' => $injected, 'cache_identity' => $injected, 'valid' => true];
        }

        $legacy = dirname(__DIR__) . '/var/orchestrator-live.json';
        if (!array_key_exists(self::SNAPSHOT_ENV, $environment) || $environment[self::SNAPSHOT_ENV] === null) {
            return ['path' => $legacy, 'cache_identity' => $legacy, 'valid' => true];
        }

        $configured = $environment[self::SNAPSHOT_ENV];
        if (!is_string($configured) || !self::validConfiguredPath($configured, $server)) {
            $identity = is_string($configured) ? $configured : serialize($configured);
            return [
                'path' => is_string($configured) ? $configured : '',
                'cache_identity' => 'invalid:' . hash('sha256', $identity),
                'valid' => false,
            ];
        }

        return ['path' => $configured, 'cache_identity' => $configured, 'valid' => true];
    }

    private static function validConfiguredPath(string $path, array $server): bool
    {
        if ($path === '' || str_contains($path, "\0") || $path[0] !== DIRECTORY_SEPARATOR) return false;
        if (preg_match('~(?:^|/)\.\.(?:/|$)~D', $path) === 1 || self::hasSymlinkComponent($path)) return false;

        $candidate = self::normalizeAbsolutePath($path);
        if ($candidate === null) return false;

        $roots = [dirname(__DIR__)];
        $documentRoot = $server['DOCUMENT_ROOT'] ?? null;
        if (is_string($documentRoot) && $documentRoot !== '') $roots[] = $documentRoot;

        foreach ($roots as $root) {
            $normalizedRoot = self::normalizeAbsolutePath($root);
            if ($normalizedRoot !== null && self::within($candidate, $normalizedRoot)) return false;
            $realRoot = realpath($root);
            if (is_string($realRoot)) {
                $normalizedRealRoot = self::normalizeAbsolutePath($realRoot);
                if ($normalizedRealRoot !== null && self::within($candidate, $normalizedRealRoot)) return false;
            }
        }

        $realCandidate = realpath($path);
        if (is_string($realCandidate)) {
            $normalizedRealCandidate = self::normalizeAbsolutePath($realCandidate);
            if ($normalizedRealCandidate === null) return false;
            foreach ($roots as $root) {
                $realRoot = realpath($root);
                $normalizedRoot = self::normalizeAbsolutePath(is_string($realRoot) ? $realRoot : $root);
                if ($normalizedRoot !== null && self::within($normalizedRealCandidate, $normalizedRoot)) return false;
            }
        }

        return true;
    }

    private static function hasSymlinkComponent(string $path): bool
    {
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            if ($component === '') continue;
            $current .= '/' . $component;
            if (is_link($current)) return true;
        }
        return false;
    }

    private static function normalizeAbsolutePath(string $path): ?string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) return null;
        $normalized = preg_replace('~/+~', '/', $path);
        if (!is_string($normalized)) return null;
        if ($normalized !== '/') $normalized = rtrim($normalized, '/');
        return $normalized;
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function unknown(int $now): array
    {
        $out = [
            'version' => 1, 'observed_at' => $now, 'source_snapshot' => hash('sha256', 'UNKNOWN'),
            'read_only' => true,
            'central' => [
                'activity_state' => 'UNKNOWN', 'available' => null, 'reserved' => null, 'blocked' => null,
                'source_ref' => null, 'observed_at' => null, 'freshness' => 'unknown', 'age_seconds' => null,
            ],
            'fronts' => [], 'owner_decisions' => [],
        ];
        return $out + ['fingerprint' => hash('sha256', json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
