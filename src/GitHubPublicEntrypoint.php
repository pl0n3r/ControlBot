<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/GitHubProjectView.php';
require_once __DIR__ . '/GitHubGlobalUi.php';

final class GitHubPublicEntrypoint
{
    private const MAX_BYTES = 2_000_000;
    private const MAX_PROJECTS = 50;
    private const DEFAULT_MAX_AGE_SECONDS = 300;

    public static function handle(
        array $request,
        array $config,
        int $now,
        ?string $projectionPath = null,
    ): array {
        try {
            $owner = self::owner($config['owner_login'] ?? null);
            $maxAge = self::maxAge($config['max_age_seconds'] ?? self::DEFAULT_MAX_AGE_SECONDS);
            if ($now < 1) {
                throw new InvalidArgumentException('clock invalid');
            }
            $method = self::requestText($request['method'] ?? null);
            $path = self::requestText($request['path'] ?? null);
            $remoteUser = self::requestText($request['remote_user'] ?? null);
        } catch (Throwable) {
            return self::response(503, "Service unavailable.\n");
        }

        if (!hash_equals($owner, $remoteUser)) {
            return self::response(403, "Forbidden.\n");
        }
        if ($method !== 'GET') {
            return self::response(405, "Method not allowed.\n", ['Allow' => 'GET']);
        }
        if ($path !== '/github') {
            return self::response(404, "Not found.\n");
        }

        $projectionPath ??= dirname(__DIR__) . '/var/github-readonly.json';
        $projects = self::localProjects($projectionPath, $now, $maxAge);
        try {
            $html = GitHubGlobalUi::render($projects);
        } catch (Throwable) {
            $html = GitHubGlobalUi::render([self::unknown($now, $maxAge)]);
        }

        return self::response(200, $html, [], 'text/html; charset=utf-8');
    }

    private static function localProjects(string $path, int $now, int $maxAge): array
    {
        try {
            if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0") || !is_file($path)) {
                throw new InvalidArgumentException('projection path invalid');
            }
            $size = filesize($path);
            if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
                throw new InvalidArgumentException('projection size invalid');
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || strlen($raw) !== $size) {
                throw new InvalidArgumentException('projection read invalid');
            }
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidArgumentException('projection invalid');
            }
            $keys = array_keys($decoded);
            sort($keys, SORT_STRING);
            if ($keys !== ['projects', 'version'] || $decoded['version'] !== 1) {
                throw new InvalidArgumentException('projection contract invalid');
            }
            $rows = $decoded['projects'];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_PROJECTS) {
                throw new InvalidArgumentException('projects invalid');
            }

            $projects = [];
            foreach ($rows as $snapshot) {
                $projects[] = GitHubProjectView::project(is_array($snapshot) ? $snapshot : [], $now, $maxAge);
            }
            return $projects;
        } catch (Throwable) {
            return [self::unknown($now, $maxAge)];
        }
    }

    private static function unknown(int $now, int $maxAge): array
    {
        return GitHubProjectView::project([], $now, $maxAge);
    }

    private static function owner(mixed $value): string
    {
        if (
            !is_string($value)
            || trim($value) === ''
            || strlen($value) > 100
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            throw new InvalidArgumentException('owner invalid');
        }
        return $value;
    }

    private static function maxAge(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 3600) {
            throw new InvalidArgumentException('max age invalid');
        }
        return $value;
    }

    private static function requestText(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('request invalid');
        }
        return $value;
    }

    private static function response(
        int $status,
        string $body,
        array $extra = [],
        string $type = 'text/plain; charset=utf-8',
    ): array {
        return ['status' => $status, 'headers' => $extra + [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ], 'body' => $body];
    }
}
