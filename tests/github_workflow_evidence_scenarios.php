<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GitHubWorkflowEvidence.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\GitHubWorkflowEvidence;

$scenario = $argv[1] ?? '';
$mainSha = str_repeat('a', 40);
$oldSha = str_repeat('b', 40);

function workflowRow(int $id): array
{
    return [
        'id' => $id,
        'name' => 'Workflow ' . $id,
        'path' => '.github/workflows/workflow-' . $id . '.yml',
        'state' => 'active',
    ];
}

function runRow(int $workflowId, int $id, int $runNumber, string $sha, string $status = 'completed', ?string $conclusion = 'success'): array
{
    return [
        'id' => $id,
        'workflow_id' => $workflowId,
        'run_number' => $runNumber,
        'head_sha' => $sha,
        'head_branch' => 'main',
        'status' => $status,
        'conclusion' => $conclusion,
        'event' => 'push',
    ];
}

function responseFor(string $url, string $mode, string $mainSha, string $oldSha): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);

    if (str_ends_with($path, '/branches/main')) {
        return ['status' => 200, 'body' => json_encode(['commit' => ['sha' => $mainSha]])];
    }

    if (str_ends_with($path, '/actions/workflows')) {
        if ($mode === 'bounded') {
            return [
                'status' => 200,
                'body' => json_encode([
                    'total_count' => 26,
                    'workflows' => array_map(static fn (int $id): array => workflowRow($id), range(1, 25)),
                ]),
            ];
        }
        return [
            'status' => 200,
            'body' => json_encode([
                'total_count' => 2,
                'workflows' => [workflowRow(11), workflowRow(12)],
            ]),
        ];
    }

    if (preg_match('#/actions/workflows/(\d+)/runs$#', $path, $match) === 1) {
        $workflowId = (int) $match[1];

        if ($mode === 'bounded') {
            return [
                'status' => 200,
                'body' => json_encode([
                    'total_count' => 1,
                    'workflow_runs' => [runRow($workflowId, 1000 + $workflowId, 1, $mainSha)],
                ]),
            ];
        }

        if ($mode === 'missing-ambiguous') {
            if ($workflowId === 11) {
                return ['status' => 200, 'body' => json_encode(['total_count' => 0, 'workflow_runs' => []])];
            }
            return [
                'status' => 200,
                'body' => json_encode([
                    'total_count' => 1,
                    'workflow_runs' => [runRow(999, 1200, 9, $mainSha)],
                ]),
            ];
        }

        if ($mode === 'stale') {
            return [
                'status' => 200,
                'body' => json_encode([
                    'total_count' => 1,
                    'workflow_runs' => [runRow($workflowId, 1300 + $workflowId, 10, $oldSha)],
                ]),
            ];
        }

        if ($workflowId === 11) {
            return [
                'status' => 200,
                'body' => json_encode([
                    'total_count' => 3,
                    'workflow_runs' => [
                        runRow(11, 1111, 20, $mainSha),
                        runRow(11, 1110, 19, $oldSha),
                    ],
                ]),
            ];
        }

        return [
            'status' => 200,
            'body' => json_encode([
                'total_count' => 1,
                'workflow_runs' => [runRow(12, 1212, 7, $mainSha, 'in_progress', null)],
            ]),
        ];
    }

    return ['status' => 404, 'body' => '{}'];
}

function collect(string $mode, string $mainSha, string $oldSha): array
{
    $calls = [];
    $sender = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $mode, $mainSha, $oldSha): array {
        $calls[] = [$method, $url, $headers, $body];
        return responseFor($url, $mode, $mainSha, $oldSha);
    };

    $client = new ApiClient('ghp_test_only_token', new ApiTransport($sender));
    $evidence = new GitHubWorkflowEvidence($client);

    return [
        'evidence' => $evidence->collect('pl0n3r/ControlBot', 500),
        'calls' => $calls,
    ];
}

if (in_array($scenario, ['normal', 'bounded', 'missing-ambiguous', 'stale'], true)) {
    echo json_encode(
        collect($scenario, $mainSha, $oldSha),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
