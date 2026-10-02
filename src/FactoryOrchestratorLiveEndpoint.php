<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/FactoryOrchestratorLiveCache.php';
require_once __DIR__ . '/FactoryOrchestratorLiveUi.php';

final class FactoryOrchestratorLiveEndpoint
{
    private const JSON_PATH = '/api/orchestrator-live';

    public static function handle(
        array $request,
        array $config,
        int $now,
        callable $refresh,
    ): array {
        try {
            $request = self::request($request);
            $config = self::config($config);
        } catch (Throwable) {
            return self::response(503, 'text/plain; charset=utf-8', "Service unavailable.\n");
        }

        if (!hash_equals($config['owner_login'], $request['remote_user'])) {
            return self::response(403, 'text/plain; charset=utf-8', "Forbidden.\n");
        }
        if ($request['method'] !== 'GET') {
            return self::response(405, 'text/plain; charset=utf-8', "Method not allowed.\n", ['Allow' => 'GET']);
        }
        if (!in_array($request['path'], ['/', self::JSON_PATH], true)) {
            return self::response(404, 'text/plain; charset=utf-8', "Not found.\n");
        }

        try {
            $cached = FactoryOrchestratorLiveCache::remember(
                $config['cache_path'],
                $now,
                $config['ttl_seconds'],
                $config['stale_seconds'],
                $config['refresh_budget_seconds'],
                $refresh,
            );
        } catch (Throwable) {
            return self::response(503, 'text/plain; charset=utf-8', "Service unavailable.\n");
        }

        if ($request['path'] === self::JSON_PATH) {
            $body = json_encode([
                'version' => 1,
                'read_only' => true,
                'cache_status' => $cached['status'],
                'cache_age_seconds' => $cached['age_seconds'],
                'snapshot' => $cached['payload'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            return self::response(200, 'application/json; charset=utf-8', $body . "\n");
        }

        try {
            $html = FactoryOrchestratorLiveUi::render($cached['payload']);
        } catch (Throwable) {
            return self::response(503, 'text/plain; charset=utf-8', "Service unavailable.\n");
        }
        $script = self::pollingScript($cached['payload']['fingerprint']);
        $html = str_replace('</body>', $script . '</body>', $html);
        return self::response(200, 'text/html; charset=utf-8', $html);
    }

    private static function request(array $request): array
    {
        self::fields($request, ['method', 'path', 'remote_user'], 'request');
        $method = $request['method'] ?? null;
        $path = $request['path'] ?? null;
        $remoteUser = $request['remote_user'] ?? null;
        if (
            !is_string($method)
            || preg_match('/^[A-Z]{3,8}$/D', $method) !== 1
            || !is_string($path)
            || preg_match('#^/[A-Za-z0-9_./-]*$#D', $path) !== 1
            || !is_string($remoteUser)
            || preg_match('/^[A-Za-z0-9-]{1,39}$/D', $remoteUser) !== 1
        ) {
            throw new InvalidArgumentException('Orchestrator request invalid.');
        }
        return ['method' => $method, 'path' => $path, 'remote_user' => $remoteUser];
    }

    private static function config(array $config): array
    {
        self::fields(
            $config,
            ['owner_login', 'cache_path', 'ttl_seconds', 'stale_seconds', 'refresh_budget_seconds'],
            'config',
        );
        $owner = $config['owner_login'] ?? null;
        if (!is_string($owner) || preg_match('/^[A-Za-z0-9-]{1,39}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('Owner configuration invalid.');
        }
        foreach (['ttl_seconds', 'stale_seconds', 'refresh_budget_seconds'] as $field) {
            if (!is_int($config[$field] ?? null)) {
                throw new InvalidArgumentException('Cache configuration invalid.');
            }
        }
        if (!is_string($config['cache_path'] ?? null) || $config['cache_path'] === '') {
            throw new InvalidArgumentException('Cache path configuration invalid.');
        }
        return $config;
    }

    private static function response(
        int $status,
        string $contentType,
        string $body,
        array $extraHeaders = [],
    ): array {
        return [
            'status' => $status,
            'headers' => array_merge([
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            ], $extraHeaders),
            'body' => $body,
        ];
    }

    private static function pollingScript(string $fingerprint): string
    {
        $current = json_encode($fingerprint, JSON_THROW_ON_ERROR);
        return '<script>(()=>{const current=' . $current
            . ';const endpoint="/api/orchestrator-live";const refresh=async()=>{try{'
            . 'const response=await fetch(endpoint,{method:"GET",credentials:"same-origin",cache:"no-store",headers:{Accept:"application/json"}});'
            . 'if(!response.ok)return;const payload=await response.json();'
            . 'if(payload&&payload.snapshot&&payload.snapshot.fingerprint&&payload.snapshot.fingerprint!==current){window.location.reload();}'
            . '}catch(_error){}};window.setInterval(refresh,5000);})();</script>';
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}
