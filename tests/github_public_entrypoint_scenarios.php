<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GitHubPublicEntrypoint.php';

use ControlBot\GitHub\GitHubPublicEntrypoint;

function snapshot(int $observedAt = 900): array
{
    return [
        'version' => 1,
        'project_id' => 'factory-control',
        'observed_at' => $observedAt,
        'repositories' => [[
            'repository_id' => 'controlbot',
            'repository' => 'pl0n3r/ControlBot',
            'source_ref' => 'https://github.com/pl0n3r/ControlBot',
            'observed_at' => $observedAt,
            'main_sha' => str_repeat('a', 40),
            'checks' => ['items' => [['name' => 'CI', 'status' => 'completed', 'conclusion' => 'success']], 'truncated' => false],
            'pull_requests' => ['items' => [], 'truncated' => false],
            'issues' => ['items' => [], 'truncated' => false],
            'latest_release' => null,
            'latest_workflow' => null,
        ]],
    ];
}

function projection(array $projects): string
{
    $path = tempnam(sys_get_temp_dir(), 'controlbot-github-');
    if (!is_string($path)) throw new RuntimeException('temp file failed');
    file_put_contents($path, json_encode(['version' => 1, 'projects' => $projects], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    return $path;
}

function handle(string $path, string $method = 'GET', string $user = 'owner', int $now = 1000): array
{
    return GitHubPublicEntrypoint::handle(
        ['method' => $method, 'path' => '/github', 'remote_user' => $user],
        ['owner_login' => 'owner', 'max_age_seconds' => 300],
        $now,
        $path,
    );
}

$scenario = $argv[1] ?? '';
if ($scenario === 'owner') {
    $path = projection([snapshot()]);
    try {
        echo json_encode([
            'owner' => handle($path),
            'wrong_owner' => handle($path, 'GET', 'intruder'),
            'wrong_method' => handle($path, 'POST'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } finally {
        @unlink($path);
    }
    exit;
}

if ($scenario === 'unknown') {
    $missing = sys_get_temp_dir() . '/controlbot-github-missing-' . bin2hex(random_bytes(4));
    $invalid = tempnam(sys_get_temp_dir(), 'controlbot-github-invalid-');
    $stale = projection([snapshot(500)]);
    if (!is_string($invalid)) throw new RuntimeException('temp file failed');
    file_put_contents($invalid, '{invalid');
    try {
        echo json_encode([
            'missing' => handle($missing),
            'invalid' => handle($invalid),
            'stale' => handle($stale),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } finally {
        @unlink($invalid);
        @unlink($stale);
    }
    exit;
}

fwrite(STDERR, "unknown scenario\n");
exit(2);
