<?php
declare(strict_types=1);

namespace ControlBot\Web;

use ControlBot\Business\FactoryOrchestratorWebEntrypoint;
use ControlBot\GitHub\GitHubProjectEntrypoint;
use ControlBot\GitHub\GitHubPublicEntrypoint;
use ControlBot\Ui\ControlCenterShell;

require_once __DIR__ . '/FactoryOrchestratorWebEntrypoint.php';
require_once __DIR__ . '/GitHubPublicEntrypoint.php';
require_once __DIR__ . '/GitHubProjectEntrypoint.php';
require_once __DIR__ . '/ControlCenterShell.php';

final class ControlBotWebEntrypoint
{
    public static function handle(
        array $server,
        array $environment,
        int $now,
        ?string $githubProjectionPath = null,
        ?string $orchestratorSnapshotPath = null,
        ?string $orchestratorCachePath = null,
    ): array {
        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) && $uri !== '' && strlen($uri) <= 2048
            ? parse_url($uri, PHP_URL_PATH)
            : null;

        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            return FactoryOrchestratorWebEntrypoint::handle(
                $server,
                $environment,
                $now,
                $orchestratorSnapshotPath,
                $orchestratorCachePath,
            );
        }

        if ($path !== '/github'
            && preg_match('#^/projects/[a-z][a-z0-9-]{1,63}/github$#D', $path) !== 1) {
            return FactoryOrchestratorWebEntrypoint::handle(
                $server,
                $environment,
                $now,
                $orchestratorSnapshotPath,
                $orchestratorCachePath,
            );
        }

        $request = [
            'method' => self::text($server['REQUEST_METHOD'] ?? null),
            'path' => $path,
            'remote_user' => self::text($server['REMOTE_USER'] ?? null),
        ];
        $config = [
            'owner_login' => self::text($environment['CONTROLBOT_OWNER_LOGIN'] ?? null),
            'max_age_seconds' => 300,
        ];

        $response = $path === '/github'
            ? GitHubPublicEntrypoint::handle($request, $config, $now, $githubProjectionPath)
            : GitHubProjectEntrypoint::handle($request, $config, $now, $githubProjectionPath);

        return self::withShell($response, $path === '/github' ? 'GitHub' : 'GitHub del proyecto');
    }

    private static function withShell(array $response, string $title): array
    {
        if (($response['status'] ?? null) !== 200
            || !is_string($response['body'] ?? null)
            || !str_starts_with((string)($response['headers']['Content-Type'] ?? ''), 'text/html')) {
            return $response;
        }

        $html = $response['body'];
        if (preg_match('#<body[^>]*>(.*)</body>#sD', $html, $bodyMatch) !== 1) {
            return self::error();
        }
        $css = preg_match('#<style>(.*)</style>#sD', $html, $styleMatch) === 1
            ? $styleMatch[1]
            : '';

        $routes = ['overview' => '/', 'github' => '/github'];
        $response['body'] = ControlCenterShell::render(
            $title,
            'github',
            $routes,
            $bodyMatch[1],
            $css,
        );
        return $response;
    }

    private static function error(): array
    {
        return [
            'status' => 503,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
            ],
            'body' => "Service unavailable.\n",
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
