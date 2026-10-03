<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GitHubReleaseProvenance.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\GitHubReleaseProvenance;

$scenario = $argv[1] ?? '';

$TAG_SHA = str_repeat('b', 40);
$COMMIT_SHA = str_repeat('c', 40);
$LIGHT_SHA = str_repeat('d', 40);

function releaseRow(int $id, string $tag, bool $draft = false, bool $prerelease = false): array
{
    return [
        'id' => $id,
        'tag_name' => $tag,
        'draft' => $draft,
        'prerelease' => $prerelease,
    ];
}

function responseFor(string $url, string $mode): array
{
    global $TAG_SHA, $COMMIT_SHA, $LIGHT_SHA;

    $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (str_ends_with($path, '/releases')) {
        if (($query['per_page'] ?? null) !== '10') {
            return ['status' => 400, 'body' => '{}'];
        }
        if ($mode === 'no-release') {
            return ['status' => 200, 'body' => '[]'];
        }
        if ($mode === 'bounded-drafts') {
            $rows = [];
            for ($index = 1; $index <= 10; ++$index) {
                $rows[] = releaseRow($index, 'draft-' . $index, true, false);
            }
            return ['status' => 200, 'body' => json_encode($rows)];
        }

        $tag = $mode === 'lightweight' ? 'v2.0.0' : 'v1.2.3';
        return [
            'status' => 200,
            'body' => json_encode([
                releaseRow(99, 'v9.9.9-rc1', false, true),
                releaseRow(42, $tag),
                releaseRow(41, 'v1.2.2'),
            ]),
        ];
    }

    if (str_contains($path, '/git/matching-refs/tags/')) {
        $tag = substr($path, strrpos($path, '/') + 1);
        if ($mode === 'missing-tag') {
            return ['status' => 200, 'body' => '[]'];
        }
        if ($mode === 'lightweight') {
            return [
                'status' => 200,
                'body' => json_encode([[
                    'ref' => 'refs/tags/v2.0.0',
                    'object' => ['type' => 'commit', 'sha' => $LIGHT_SHA],
                ]]),
            ];
        }
        return [
            'status' => 200,
            'body' => json_encode([
                [
                    'ref' => 'refs/tags/' . $tag,
                    'object' => ['type' => 'tag', 'sha' => $TAG_SHA],
                ],
                [
                    'ref' => 'refs/tags/' . $tag . '-notes',
                    'object' => ['type' => 'commit', 'sha' => str_repeat('e', 40)],
                ],
            ]),
        ];
    }

    if ($path === '/repos/pl0n3r/ControlBot/git/tags/' . $TAG_SHA) {
        return [
            'status' => 200,
            'body' => json_encode([
                'sha' => $TAG_SHA,
                'tag' => 'v1.2.3',
                'object' => ['type' => 'commit', 'sha' => $COMMIT_SHA],
            ]),
        ];
    }

    if ($path === '/repos/pl0n3r/ControlBot/commits/' . $COMMIT_SHA) {
        return ['status' => 200, 'body' => json_encode(['sha' => $COMMIT_SHA])];
    }

    if ($path === '/repos/pl0n3r/ControlBot/commits/' . $LIGHT_SHA) {
        return ['status' => 200, 'body' => json_encode(['sha' => $LIGHT_SHA])];
    }

    return ['status' => 404, 'body' => '{}'];
}

function collect(string $mode): array
{
    $calls = [];
    $sender = static function (
        string $method,
        string $url,
        array $headers,
        ?string $body,
    ) use (&$calls, $mode): array {
        $calls[] = [$method, $url, $headers, $body];
        return responseFor($url, $mode);
    };

    $client = new ApiClient('ghp_test_only_token', new ApiTransport($sender));
    $evidence = new GitHubReleaseProvenance($client);

    return [
        'evidence' => $evidence->collect('pl0n3r/ControlBot', 300),
        'calls' => $calls,
    ];
}

if (in_array($scenario, ['annotated', 'lightweight', 'missing-tag', 'no-release', 'bounded-drafts'], true)) {
    echo json_encode(collect($scenario), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
