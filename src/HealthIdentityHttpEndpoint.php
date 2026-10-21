<?php
declare(strict_types=1);

namespace ControlBot\Health;

require_once __DIR__ . '/HealthIdentityPayload.php';

/** A signed, private deployment receipt, never request-supplied release identity. */
final class HealthIdentityHttpEndpoint
{
    public static function handle(array $server, array $configuration): array
    {
        $headers = [
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
        $method = $server['REQUEST_METHOD'] ?? null;
        $authType = $server['AUTH_TYPE'] ?? null;
        $user = $server['REMOTE_USER'] ?? null;
        $owner = $configuration['owner_login'] ?? null;
        // HTTPS and authenticated owner are server variables, never forwarded headers.
        if ($method !== 'GET' || ($server['HTTPS'] ?? null) !== 'on'
            || $authType !== 'Basic'
            || !is_string($user) || !is_string($owner)
            || preg_match('/\A[A-Za-z0-9-]{1,39}\z/', $owner) !== 1
            || !hash_equals($owner, $user)) {
            return self::fail(403, 'Forbidden.', $headers);
        }

        $path = $configuration['release_manifest_path'] ?? null;
        $keyEncoded = $configuration['attestation_key'] ?? null;
        $documentRoot = $server['DOCUMENT_ROOT'] ?? null;
        if (!is_string($path) || $path === '' || strlen($path) > 1024
            || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, "\n") || str_contains($path, "\r")
            || !is_string($documentRoot) || $documentRoot === ''
            || !is_string($keyEncoded) || strlen($keyEncoded) > 128) {
            return self::fail(503, 'Service unavailable.', $headers);
        }
        $key = base64_decode($keyEncoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            return self::fail(503, 'Service unavailable.', $headers);
        }
        $manifest = realpath($path);
        $webroot = realpath($documentRoot);
        if ($manifest === false || $webroot === false
            || $manifest !== $path || is_link($path) || !is_file($manifest)
            || !is_readable($manifest)
            || $manifest === $webroot
            || str_starts_with($manifest, rtrim($webroot, '/') . '/')) {
            return self::fail(503, 'Service unavailable.', $headers);
        }
        $size = filesize($manifest);
        if (!is_int($size) || $size < 2 || $size > 512) {
            return self::fail(503, 'Service unavailable.', $headers);
        }
        $raw = file_get_contents($manifest, false, null, 0, 513);
        if (!is_string($raw) || strlen($raw) !== $size) {
            return self::fail(503, 'Service unavailable.', $headers);
        }

        try {
            $data = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 3
                || !array_key_exists('version', $data)
                || !array_key_exists('release_sha', $data)
                || !array_key_exists('signature', $data)
                || !is_string($data['version']) || !is_string($data['release_sha'])
                || !is_string($data['signature'])
                || preg_match('/\A[0-9a-f]{64}\z/', $data['signature']) !== 1
                || json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $raw) {
                throw new \UnexpectedValueException('Invalid deployment receipt.');
            }
            // A signed SHA alone cannot substitute the version of this deployed PHP tree.
            $versionRaw = file_get_contents(__DIR__ . '/../config/version.json');
            $versionData = is_string($versionRaw)
                ? json_decode($versionRaw, true, 3, JSON_THROW_ON_ERROR) : null;
            if (!is_array($versionData) || !is_string($versionData['version'] ?? null)
                || !hash_equals($versionData['version'], $data['version'])) {
                throw new \UnexpectedValueException('Release version mismatch.');
            }
            $payload = HealthIdentityPayload::build($data['version'], $data['release_sha']);
            $signed = "controlbot-release-v1\n" . $data['version'] . "\n" . $data['release_sha'];
            $expected = hash_hmac('sha256', $signed, $key);
            if (!hash_equals($expected, $data['signature'])) {
                throw new \UnexpectedValueException('Unauthenticated deployment receipt.');
            }
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return self::fail(503, 'Service unavailable.', $headers);
        }
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            'body' => $body . "\n",
        ];
    }

    private static function fail(int $status, string $message, array $headers): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'] + $headers,
            'body' => $message . "\n",
        ];
    }
}
