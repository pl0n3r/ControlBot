<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Throwable;

require_once __DIR__ . '/GitHubProjectView.php';
require_once __DIR__ . '/GitHubProjectUi.php';

final class GitHubProjectEntrypoint
{
    private const MAX_BYTES = 2_000_000;
    private const MAX_PROJECTS = 50;

    public static function handle(array $request, array $config, int $now, ?string $projectionPath = null): array
    {
        $owner = $config['owner_login'] ?? null;
        $maxAge = $config['max_age_seconds'] ?? 300;
        if (!is_string($owner) || trim($owner) === '' || !is_int($maxAge) || $maxAge < 1 || $now < 1) {
            return self::response(503, "Service unavailable.\n");
        }
        if (($request['remote_user'] ?? null) !== $owner) {
            return self::response(403, "Forbidden.\n");
        }
        if (($request['method'] ?? null) !== 'GET') {
            return self::response(405, "Method not allowed.\n", ['Allow' => 'GET']);
        }
        $path = $request['path'] ?? null;
        if (!is_string($path)
            || preg_match('#^/projects/([a-z][a-z0-9-]{1,63})/github$#D', $path, $matches) !== 1) {
            return self::response(404, "Not found.\n");
        }

        $projectionPath ??= dirname(__DIR__) . '/var/github-readonly.json';
        $snapshot = self::findSnapshot($projectionPath, $matches[1]);
        if ($snapshot === null) {
            return self::response(404, "Not found.\n");
        }

        try {
            $view = GitHubProjectView::project($snapshot, $now, $maxAge);
            $html = GitHubProjectUi::render($view);
        } catch (Throwable) {
            return self::response(404, "Not found.\n");
        }
        return self::response(200, $html, [], 'text/html; charset=utf-8');
    }

    private static function findSnapshot(string $path, string $projectId): ?array
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0") || !is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data) || array_is_list($data) || ($data['version'] ?? null) !== 1) {
            return null;
        }
        $projects = $data['projects'] ?? null;
        if (!is_array($projects) || !array_is_list($projects) || count($projects) > self::MAX_PROJECTS) {
            return null;
        }

        $match = null;
        foreach ($projects as $snapshot) {
            if (!is_array($snapshot) || ($snapshot['project_id'] ?? null) !== $projectId) {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = $snapshot;
        }
        return $match;
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
