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
    return FactoryLiveSnapshot::build([], 200);
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

function entrypoint(string $path, bool $withOwner): array
{
    $dir = temporaryDirectory();
    $snapshot = $dir . '/snapshot.json';
    $cache = $dir . '/cache.json';
    file_put_contents($snapshot, json_encode(canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    putenv('CONTROLBOT_OWNER_LOGIN=' . ($withOwner ? 'pl0n3r' : ''));
    putenv('CONTROLBOT_ORCHESTRATOR_CACHE_PATH=' . $cache);
    putenv('CONTROLBOT_FACTORY_LIVE_SNAPSHOT_PATH=' . $snapshot);
    putenv('CONTROLBOT_ORCHESTRATOR_CACHE_TTL_SECONDS=15');
    putenv('CONTROLBOT_ORCHESTRATOR_STALE_SECONDS=120');
    putenv('CONTROLBOT_ORCHESTRATOR_REFRESH_BUDGET_SECONDS=5');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['REMOTE_USER'] = 'pl0n3r';
    ob_start();
    require __DIR__ . '/../public/index.php';
    $body = (string) ob_get_clean();
    $status = http_response_code();
    @unlink($cache);
    @unlink($snapshot);
    @rmdir($dir);
    return ['status' => $status, 'body' => $body];
}

if ($scenario === 'entrypoint_html') {
    echo json_encode(entrypoint('/', true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
if ($scenario === 'entrypoint_json') {
    echo json_encode(entrypoint('/api/orchestrator-live', true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
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
    $dir = temporaryDirectory();
    $response = FactoryOrchestratorLiveEndpoint::handle(
        ['method' => 'GET', 'path' => '/', 'remote_user' => 'pl0n3r'],
        ['owner_login' => 'pl0n3r', 'cache_path' => $dir . '/cache.json', 'ttl_seconds' => 10, 'stale_seconds' => 60, 'refresh_budget_seconds' => 20],
        220,
        static fn (): array => view(),
    );
    @unlink($dir . '/cache.json');
    @rmdir($dir);
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);
