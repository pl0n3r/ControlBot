<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GitHubPrEvidence.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\GitHubPrEvidence;

$scenario = $argv[1] ?? '';

function pullRow(int $number): array
{
    return [
        'number' => $number,
        'title' => 'PR ' . $number,
        'draft' => false,
        'head' => ['sha' => str_repeat(dechex(($number % 15) + 1), 40)],
    ];
}

function responseFor(string $url, string $mode): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (str_ends_with($path, '/pulls')) {
        $rows = $mode === 'bounded'
            ? array_map(static fn (int $n): array => pullRow($n), range(1, 25))
            : [pullRow(7), pullRow(8)];
        return ['status' => 200, 'body' => json_encode($rows)];
    }

    if (preg_match('#/pulls/(\d+)/reviews$#', $path, $match) === 1) {
        $number = (int) $match[1];
        $headSha = pullRow($number)['head']['sha'];
        $commitId = $mode === 'ambiguous' && $number === 7 ? null : $headSha;
        return [
            'status' => 200,
            'body' => json_encode([[
                'user' => ['login' => 'reviewer-' . $number],
                'state' => $number % 2 === 0 ? 'COMMENTED' : 'APPROVED',
                'commit_id' => $commitId,
            ]]),
        ];
    }

    if (preg_match('#/pulls/(\d+)$#', $path, $match) === 1) {
        $number = (int) $match[1];
        $mergeable = $mode === 'ambiguous' && $number === 7
            ? null
            : $number % 2 === 1;
        return [
            'status' => 200,
            'body' => json_encode([
                'number' => $number,
                'head' => ['sha' => pullRow($number)['head']['sha']],
                'mergeable' => $mergeable,
                'mergeable_state' => $mergeable === null ? 'unknown' : ($mergeable ? 'clean' : 'dirty'),
            ]),
        ];
    }

    return ['status' => 404, 'body' => '{}'];
}

function collect(string $mode): array
{
    $calls = [];
    $sender = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $mode): array {
        $calls[] = [$method, $url, $headers, $body];
        return responseFor($url, $mode);
    };
    $client = new ApiClient('ghp_test_only_token', new ApiTransport($sender));
    $evidence = new GitHubPrEvidence($client);
    return [
        'evidence' => $evidence->collect('pl0n3r/ControlBot', 200),
        'calls' => $calls,
    ];
}

if ($scenario === 'normal') {
    echo json_encode(collect('normal'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
if ($scenario === 'ambiguous') {
    echo json_encode(collect('ambiguous'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
if ($scenario === 'bounded') {
    echo json_encode(collect('bounded'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
