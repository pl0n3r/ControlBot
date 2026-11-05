<?php
declare(strict_types=1);

namespace ControlBot\Routing;

use ControlBot\Security\OwnerSessionService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Authenticated, fail-closed HTTP registry for /api/v1 owner routes.
 *
 * Route modules are trusted PHP files, not client-supplied descriptors. Adding
 * a module under Routes/ requires no edit to this class. No REMOTE_USER,
 * cookies, request flags, or headers grant owner authority.
 */
final class OwnerAuthenticatedRouter
{
    public static function verifiedOwner(OwnerSessionService $service, array $session, int $now): ?string
    {
        try {
            if ($now < 1) {
                return null;
            }
            $context = $service->contextFromRequest(
                $session,
                ['_csrf' => $service->csrfToken($session)],
                $now,
            );
            $service->githubToken($session);
            return $context->isOwner ? $context->login : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Return null for routes owned by other controllers; never read anonymous
     * session material and never trust REMOTE_USER or HTTP authorization.
     * $backend is an optional SERVER-BOUND source/executor, not a request field.
     */
    public static function dispatch(
        OwnerSessionService $service,
        array $session,
        array $server,
        int $now,
        ?callable $backend = null,
        ?string $routesDirectory = null,
        ?string $rawBody = null,
    ): ?array {
        $uri = $server['REQUEST_URI'] ?? null;
        if (!is_string($uri) || strlen($uri) > 2048 || str_contains($uri, "\0")) {
            return self::response(400, ['error' => 'invalid_request']);
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/api/v1/')) {
            return null;
        }
        if (str_contains($path, '%') || str_contains($path, '//')
            || str_contains($path, '..') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return self::response(400, ['error' => 'invalid_request']);
        }
        if (self::verifiedOwner($service, $session, $now) === null) {
            return self::response(403, ['error' => 'forbidden']);
        }
        $method = $server['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || !in_array($method, ['GET', 'POST'], true)) {
            return self::response(405, ['error' => 'method_not_allowed']);
        }

        try {
            $matched = self::match($method, $path, self::routes($routesDirectory));
            if ($matched === null) {
                return self::response(404, ['error' => 'not_found']);
            }
            [$handler, $params] = $matched;
            $payload = [];
            if ($method === 'POST') {
                // CSRF is a request HEADER, never a field in the documented
                // immutable JSON decision payload. Validate BEFORE invoking a
                // backend that might perform a mutation.
                $csrf = $server['HTTP_X_CSRF_TOKEN'] ?? null;
                if (!is_string($csrf) || strlen($csrf) > 256) {
                    return self::response(403, ['error' => 'forbidden']);
                }
                try {
                    $service->assertCsrf($session, $csrf);
                } catch (Throwable) {
                    return self::response(403, ['error' => 'forbidden']);
                }
                $type = $server['CONTENT_TYPE'] ?? '';
                if (!is_string($type)
                    || preg_match('#^application/json(?:\s*;\s*charset=utf-8)?$#iD', $type) !== 1) {
                    return self::response(400, ['error' => 'invalid_request']);
                }
                $size = $server['CONTENT_LENGTH'] ?? null;
                if ($size !== null && (!is_scalar($size) || !ctype_digit((string) $size)
                    || (int) $size > 16384)) {
                    return self::response(400, ['error' => 'invalid_request']);
                }
                $raw = $rawBody ?? file_get_contents('php://input', false, null, 0, 16385);
                if (!is_string($raw) || $raw === '' || strlen($raw) > 16384) {
                    return self::response(400, ['error' => 'invalid_request']);
                }
                $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || array_is_list($payload)) {
                    return self::response(400, ['error' => 'invalid_request']);
                }
            }
            if ($backend === null) {
                // No fabricated owner data or executed decisions in the absence
                // of a trusted server adapter (D-059).
                return self::response(503, ['error' => 'source_unavailable']);
            }
            $output = $handler($backend, $params, $payload, $now);
            if (!is_array($output) || array_is_list($output)) {
                throw new RuntimeException('Owner route returned no object.');
            }
            return self::response(200, $output);
        } catch (InvalidArgumentException|\JsonException) {
            return self::response(400, ['error' => 'invalid_request']);
        } catch (Throwable) {
            return self::response(503, ['error' => 'source_unavailable']);
        }
    }

    private static function routes(?string $directory): array
    {
        $directory ??= __DIR__ . '/Routes';
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Owner routes are unavailable.');
        }
        $files = glob($root . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('No owner routes.');
        }
        sort($files, SORT_STRING);
        $routes = [];
        foreach ($files as $file) {
            if (is_link($file) || realpath($file) !== $file) {
                throw new RuntimeException('Untrusted owner routes file.');
            }
            $module = require $file;
            if (!is_array($module) || !array_is_list($module) || $module === []) {
                throw new RuntimeException('Invalid owner routes module.');
            }
            foreach ($module as $definition) {
                if (!is_array($definition) || array_keys($definition) !== ['method', 'path', 'handler']
                    || !in_array($definition['method'], ['GET', 'POST'], true)
                    || !is_string($definition['path'])
                    || !str_starts_with($definition['path'], '/api/v1/')
                    || !is_callable($definition['handler'])) {
                    throw new RuntimeException('Invalid owner route definition.');
                }
                $key = $definition['method'] . ' ' . $definition['path'];
                if (isset($routes[$key])) {
                    throw new RuntimeException('Duplicate owner route.');
                }
                $routes[$key] = $definition['handler'];
            }
        }
        return $routes;
    }

    private static function match(string $method, string $path, array $routes): ?array
    {
        foreach ($routes as $key => $handler) {
            [$verb, $template] = explode(' ', $key, 2);
            if ($verb !== $method) {
                continue;
            }
            $segments = explode('/', trim($template, '/'));
            $actual = explode('/', trim($path, '/'));
            if (count($segments) !== count($actual)) {
                continue;
            }
            $values = [];
            foreach ($segments as $index => $segment) {
                if (preg_match('/^\{([a-z][a-z_]*)\}$/D', $segment, $matches) === 1) {
                    if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $actual[$index]) !== 1) {
                        continue 2;
                    }
                    $values[$matches[1]] = $actual[$index];
                } elseif (!hash_equals($segment, $actual[$index])) {
                    continue 2;
                }
            }
            return [$handler, $values];
        }
        return null;
    }

    private static function response(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
            ],
            'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }
}
