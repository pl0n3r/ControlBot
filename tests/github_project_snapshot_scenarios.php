<?php
declare(strict_types=1);

require __DIR__ . '/../src/ProjectModel.php';
require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GitHubProjectSnapshot.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\GitHubProjectSnapshot;

$scenario = $argv[1] ?? '';

function project(array $repositories): array
{
    return [
        'version' => 1,
        'project_id' => 'project-controlbot',
        'slug' => 'controlbot',
        'title' => 'ControlBot',
        'phase' => 'building',
        'priority' => 'high',
        'repositories' => array_map(
            static fn (string $repository, int $index): array => [
                'repository_id' => 'repo-' . ($index + 1),
                'repository' => $repository,
                'source_ref' => 'https://github.com/' . $repository,
                'observed_at' => 100,
            ],
            $repositories,
            array_keys($repositories),
        ),
        'environments' => [],
        'aggregate_refs' => [
            'roadmap' => null,
            'agents' => null,
            'decisions' => null,
            'health' => null,
            'incidents' => null,
            'costs' => null,
        ],
        'history_refs' => [],
    ];
}

function validSender(array &$calls, string $mode = 'normal'): Closure
{
    return static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $mode): array {
        $calls[] = [$method, $url, $headers, $body];
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($path, '/branches/main')) {
            $sha = $mode === 'bad-sha' ? 'nope' : str_repeat('a', 40);
            return ['status' => 200, 'body' => json_encode(['commit' => ['sha' => $sha]])];
        }
        if (str_contains($path, '/check-runs')) {
            $rows = $mode === 'bounded'
                ? array_fill(0, 100, ['name' => 'CI', 'status' => 'completed', 'conclusion' => 'success'])
                : [
                    ['name' => 'CI ControlBot', 'status' => 'completed', 'conclusion' => 'success'],
                    ['name' => 'Security', 'status' => 'in_progress', 'conclusion' => null],
                ];
            if ($mode === 'bad-check') {
                $rows = [['name' => 'CI', 'status' => 'completed', 'conclusion' => null]];
            }
            return ['status' => 200, 'body' => json_encode(['total_count' => count($rows), 'check_runs' => $rows])];
        }
        if (str_ends_with($path, '/pulls')) {
            $row = [
                'number' => 7,
                'title' => 'Read model',
                'draft' => false,
                'head' => ['sha' => str_repeat('b', 40)],
                'base' => ['ref' => 'main'],
            ];
            $rows = $mode === 'bounded' ? array_fill(0, 100, $row) : [$row];
            return ['status' => 200, 'body' => json_encode($rows)];
        }
        if (str_ends_with($path, '/issues')) {
            $issue = ['number' => 9, 'title' => 'Visible issue', 'labels' => [['name' => 'prioridad: alta']]];
            $prAsIssue = ['number' => 7, 'title' => 'Read model', 'labels' => [], 'pull_request' => ['url' => 'opaque']];
            $rows = $mode === 'bounded' ? array_fill(0, 100, $issue) : [$issue, $prAsIssue];
            return ['status' => 200, 'body' => json_encode($rows)];
        }
        if (str_ends_with($path, '/releases')) {
            $rows = $mode === 'no-release' ? [] : [['tag_name' => 'v0.1.20', 'draft' => false, 'prerelease' => false]];
            return ['status' => 200, 'body' => json_encode($rows)];
        }
        if (str_ends_with($path, '/actions/runs')) {
            $branch = $mode === 'bad-workflow' ? 'other' : 'main';
            return ['status' => 200, 'body' => json_encode([
                'total_count' => 1,
                'workflow_runs' => [[
                    'name' => 'CI ControlBot',
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'head_branch' => $branch,
                    'head_sha' => str_repeat('a', 40),
                    'run_number' => 123,
                ]],
            ])];
        }

        return ['status' => 404, 'body' => '{}'];
    };
}

function snapshot(array $repositories, string $mode = 'normal'): array
{
    $calls = [];
    $transport = new ApiTransport(validSender($calls, $mode));
    $api = new ApiClient('ghp_test_only_token', $transport);
    $projection = new GitHubProjectSnapshot($api);
    return ['snapshot' => $projection->project(project($repositories), 200), 'calls' => $calls];
}

if ($scenario === 'routes') {
    echo json_encode(snapshot(['pl0n3r/ControlBot', 'pl0n3r/Factory']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'normalize') {
    echo json_encode(snapshot(['pl0n3r/ControlBot']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'invalid') {
    $blocked = [];
    foreach (['bad-sha', 'bad-check', 'bad-workflow'] as $mode) {
        try {
            snapshot(['pl0n3r/ControlBot'], $mode);
            $blocked[$mode] = false;
        } catch (Throwable) {
            $blocked[$mode] = true;
        }
    }
    try {
        snapshot(['bad repo']);
        $blocked['project'] = false;
    } catch (Throwable) {
        $blocked['project'] = true;
    }
    echo json_encode(['blocked' => $blocked], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'bounded') {
    $bounded = snapshot(['pl0n3r/ControlBot'], 'bounded')['snapshot'];
    $noRelease = snapshot(['pl0n3r/ControlBot'], 'no-release')['snapshot'];
    echo json_encode([
        'bounded' => $bounded['repositories'][0],
        'no_release' => $noRelease['repositories'][0]['latest_release'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
