<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryOrchestratorWebEntrypoint.php';

use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryOrchestratorWebEntrypoint;

function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/cb-web-entry-' . bin2hex(random_bytes(5));
    if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        throw new RuntimeException('temp dir unavailable');
    }
    return $dir;
}

function canonical_snapshot(int $now): array
{
    return FactoryLiveSnapshot::build([
        'work' => [[
            'id' => 'work:controlbot-656',
            'authority' => 'github_project_snapshot',
            'state' => 'pending',
            'source_ref' => 'github:pl0n3r/ControlBot#656',
            'observed_at' => $now - 5,
            'freshness' => 'current',
            'data' => [
                'repository_ref' => 'pl0n3r/ControlBot',
                'issue_ref' => 'github:pl0n3r/ControlBot#656',
                'status' => 'reserved',
                'progress_percent' => 25,
                'progress_evidence' => 'github:pl0n3r/ControlBot#656',
            ],
        ]],
    ], $now);
}

function write_snapshot(string $path, array $snapshot): void
{
    $raw = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $raw) !== strlen($raw)) {
        throw new RuntimeException('snapshot write failed');
    }
}

function call_entry(
    string $requestPath,
    int $now,
    string $snapshotPath,
    string $cachePath,
    ?string $user = 'pl0n3r',
    ?string $owner = 'pl0n3r',
): array {
    $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $requestPath];
    if ($user !== null) {
        $server['REMOTE_USER'] = $user;
    }
    $env = [];
    if ($owner !== null) {
        $env['CONTROLBOT_OWNER_LOGIN'] = $owner;
    }
    return FactoryOrchestratorWebEntrypoint::handle(
        $server,
        $env,
        $now,
        $snapshotPath,
        $cachePath,
    );
}

function cleanup_dir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

$scenario = $argv[1] ?? '';
$now = 1_800_000_000;
$dir = temp_dir();

try {
    if ($scenario === 'owner' || $scenario === 'json') {
        $snapshot = $dir . '/snapshot.json';
        write_snapshot($snapshot, canonical_snapshot($now));
        $path = $scenario === 'owner' ? '/' : '/api/orchestrator-live?source=local';
        $response = call_entry($path, $now, $snapshot, $dir . '/cache-' . $scenario . '.json');
        echo json_encode($response, JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }

    if ($scenario === 'auth') {
        $snapshot = $dir . '/snapshot.json';
        write_snapshot($snapshot, canonical_snapshot($now));
        $missing = call_entry('/', $now, $snapshot, $dir . '/missing.json', null, 'pl0n3r');
        $wrong = call_entry('/', $now, $snapshot, $dir . '/wrong.json', 'other', 'pl0n3r');
        $config = call_entry('/', $now, $snapshot, $dir . '/config.json', 'pl0n3r', null);
        echo json_encode(
            ['missing' => $missing, 'wrong' => $wrong, 'config' => $config],
            JSON_THROW_ON_ERROR
        ), PHP_EOL;
        exit;
    }

    if ($scenario === 'unknown') {
        $missing = call_entry(
            '/api/orchestrator-live',
            $now,
            $dir . '/absent.json',
            $dir . '/missing-cache.json',
        );

        $invalidPath = $dir . '/invalid.json';
        file_put_contents($invalidPath, '{"observed_at":');
        $invalid = call_entry(
            '/api/orchestrator-live',
            $now,
            $invalidPath,
            $dir . '/invalid-cache.json',
        );

        $stalePath = $dir . '/stale.json';
        write_snapshot($stalePath, FactoryLiveSnapshot::build([], $now - 301));
        $stale = call_entry(
            '/api/orchestrator-live',
            $now,
            $stalePath,
            $dir . '/stale-cache.json',
        );

        echo json_encode(
            ['missing' => $missing, 'invalid' => $invalid, 'stale' => $stale],
            JSON_THROW_ON_ERROR
        ), PHP_EOL;
        exit;
    }

    fwrite(STDERR, "scenario invalid\n");
    exit(2);
} finally {
    cleanup_dir($dir);
}
