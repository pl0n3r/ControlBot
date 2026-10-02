<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryLiveSnapshot.php';
require __DIR__ . '/../src/FactoryLiveOrchestratorSnapshot.php';
require __DIR__ . '/../src/FactoryOrchestratorLiveCache.php';
require __DIR__ . '/../src/FactoryOrchestratorLiveEndpoint.php';

use ControlBot\Business\FactoryLiveOrchestratorSnapshot;
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryOrchestratorLiveCache;
use ControlBot\Business\FactoryOrchestratorLiveEndpoint;

$scenario = $argv[1] ?? '';

function canonical(): array
{
    return FactoryLiveSnapshot::build(['batches' => []], 200);
}

function view(): array
{
    return FactoryLiveOrchestratorSnapshot::build(canonical(), 220);
}

function temporaryDirectory(): string
{
    $path = sys_get_temp_dir() . '/controlbot-orchestrator-' . bin2hex(random_bytes(6));
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('temp dir failed');
    }
    return $path;
}

function endpointResponse(string $path): array
{
    $dir = temporaryDirectory();
    $cache = $dir . '/cache.json';
    try {
        return FactoryOrchestratorLiveEndpoint::handle(
            ['method' => 'GET', 'path' => $path, 'remote_user' => 'pl0n3r'],
            [
                'owner_login' => 'pl0n3r',
                'cache_path' => $cache,
                'ttl_seconds' => 15,
                'stale_seconds' => 120,
                'refresh_budget_seconds' => 5,
            ],
            220,
            static fn (): array => view(),
        );
    } finally {
        @unlink($cache);
        @rmdir($dir);
    }
}

if ($scenario === 'entrypoint_html') {
    echo json_encode(endpointResponse('/'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
if ($scenario === 'entrypoint_json') {
    echo json_encode(endpointResponse('/api/orchestrator-live'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
if ($scenario === 'missing_auth') {
    $calls = 0;
    $dir = temporaryDirectory();
    $response = FactoryOrchestratorLiveEndpoint::handle(
        ['method' => 'GET', 'path' => '/', 'remote_user' => 'pl0n3r'],
        ['owner_login' => '', 'cache_path' => $dir . '/cache.json', 'ttl_seconds' => 10, 'stale_seconds' => 60, 'refresh_budget_seconds' => 20],
        220,
        static function () use (&$calls): array {
            $calls++;
            return view();
        },
    );
    $denied = FactoryOrchestratorLiveEndpoint::handle(
        ['method' => 'GET', 'path' => '/', 'remote_user' => 'someone-else'],
        ['owner_login' => 'pl0n3r', 'cache_path' => $dir . '/cache.json', 'ttl_seconds' => 10, 'stale_seconds' => 60, 'refresh_budget_seconds' => 20],
        220,
        static function () use (&$calls): array {
            $calls++;
            return view();
        },
    );
    @rmdir($dir);
    echo json_encode(['missing' => $response, 'denied' => $denied, 'loader_calls' => $calls], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($scenario === 'cache') {
    $dir = temporaryDirectory();
    $path = $dir . '/cache.json';
    $calls = 0;
    $loader = static function () use (&$calls): array {
        $calls++;
        if ($calls >= 2) {
            throw new RuntimeException('refresh failed');
        }
        return view();
    };
    $first = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, $loader);
    $fresh = FactoryOrchestratorLiveCache::remember($path, 225, 10, 60, 20, $loader);
    $budgeted = FactoryOrchestratorLiveCache::remember($path, 231, 10, 60, 20, $loader);
    $fallback = FactoryOrchestratorLiveCache::remember($path, 241, 10, 60, 20, $loader);
    $bounded = FactoryOrchestratorLiveCache::remember($path, 242, 10, 60, 20, $loader);
    @unlink($path);
    @rmdir($dir);
    echo json_encode([
        'statuses' => [$first['status'], $fresh['status'], $budgeted['status'], $fallback['status'], $bounded['status']],
        'loader_calls' => $calls,
        'fallback_age' => $fallback['age_seconds'],
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($scenario === 'polling') {
    echo json_encode(endpointResponse('/'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);
