<?php
declare(strict_types=1);

use ControlBot\Business\FactoryLiveOrchestratorSnapshot;
use ControlBot\Business\FactoryOrchestratorLiveEndpoint;

require_once __DIR__ . '/../src/FactoryLiveOrchestratorSnapshot.php';
require_once __DIR__ . '/../src/FactoryOrchestratorLiveEndpoint.php';

function envInt(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return preg_match('/^[0-9]{1,6}$/D', $value) === 1 ? (int) $value : 0;
}

function localSnapshot(string $path, int $now): array
{
    if (
        $path === ''
        || $path[0] !== '/'
        || str_contains($path, "\0")
        || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $path) === 1
        || !is_file($path)
        || !is_readable($path)
    ) {
        throw new RuntimeException('Canonical snapshot source unavailable.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 1 || $size > 2_000_000) {
        throw new RuntimeException('Canonical snapshot source invalid.');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) !== $size) {
        throw new RuntimeException('Canonical snapshot read failed.');
    }
    $snapshot = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || array_is_list($snapshot)) {
        throw new RuntimeException('Canonical snapshot invalid.');
    }
    return FactoryLiveOrchestratorSnapshot::build($snapshot, $now);
}

$now = time();
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
$owner = getenv('CONTROLBOT_OWNER_LOGIN');
$cachePath = getenv('CONTROLBOT_ORCHESTRATOR_CACHE_PATH');
$snapshotPath = getenv('CONTROLBOT_FACTORY_LIVE_SNAPSHOT_PATH');

$response = FactoryOrchestratorLiveEndpoint::handle(
    [
        'method' => is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        'path' => $path,
        'remote_user' => is_string($_SERVER['REMOTE_USER'] ?? null) ? $_SERVER['REMOTE_USER'] : '',
    ],
    [
        'owner_login' => is_string($owner) ? $owner : '',
        'cache_path' => is_string($cachePath) ? $cachePath : '',
        'ttl_seconds' => envInt('CONTROLBOT_ORCHESTRATOR_CACHE_TTL_SECONDS', 15),
        'stale_seconds' => envInt('CONTROLBOT_ORCHESTRATOR_STALE_SECONDS', 120),
        'refresh_budget_seconds' => envInt('CONTROLBOT_ORCHESTRATOR_REFRESH_BUDGET_SECONDS', 5),
    ],
    $now,
    static fn (): array => localSnapshot(is_string($snapshotPath) ? $snapshotPath : '', $now),
);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
