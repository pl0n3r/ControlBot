<?php
declare(strict_types=1);

/**
 * Compare a downloaded /health JSON payload against a specific release.
 *
 * This script does not perform HTTP requests and never echoes response bodies,
 * tokens, addresses, or the observed release identity. A workflow step obtains
 * the payload with an HTTPS-only, no-redirect request after deployment.
 *
 * Usage: php scripts/deploy-smoke.php HEALTH_JSON VERSION_JSON EXPECTED_SHA
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fail = static function (): never {
    fwrite(STDERR, "Health smoke failed: identity unavailable or mismatched.\n");
    exit(1);
};

try {
    if ($argc !== 4) {
        $fail();
    }

    [$unused, $healthPath, $versionPath, $expectedSha] = $argv;
    if (preg_match('/\A[0-9a-f]{40}\z/D', $expectedSha) !== 1) {
        $fail();
    }

    $readJson = static function (string $path) use ($fail): array {
        // Data is always a local file. This excludes network wrapper reads.
        if (str_contains($path, '://') || !is_file($path)) {
            $fail();
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > 8192) {
            $fail();
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            $fail();
        }
        $value = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            $fail();
        }
        return $value;
    };

    $versionSource = $readJson($versionPath);
    $expectedVersion = $versionSource['version'] ?? null;
    if (!is_string($expectedVersion)
        || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $expectedVersion) !== 1
    ) {
        $fail();
    }

    $payload = $readJson($healthPath);
    $keys = array_keys($payload);
    sort($keys, SORT_STRING);
    if ($keys !== ['release_sha', 'status', 'version']
        || $payload['status'] !== 'ok'
        || !is_string($payload['version'])
        || !is_string($payload['release_sha'])
        || !hash_equals($expectedVersion, $payload['version'])
        || !hash_equals($expectedSha, $payload['release_sha'])
    ) {
        $fail();
    }

    fwrite(STDOUT, "Health smoke passed: release identity verified.\n");
} catch (Throwable) {
    $fail();
}
