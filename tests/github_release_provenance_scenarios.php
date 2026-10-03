<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GitHubReleaseProvenance.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\GitHubReleaseProvenance;

$mode = $argv[1] ?? '';
$tagSha = str_repeat('b', 40);
$commitSha = str_repeat('c', 40);
$lightSha = str_repeat('d', 40);

function releaseRow(int $id, string $tag, bool $draft = false, bool $prerelease = false): array
{
    return ['id' => $id, 'tag_name' => $tag, 'draft' => $draft, 'prerelease' => $prerelease];
}

function reply(string $url, string $mode, string $tagSha, string $commitSha, string $lightSha): array
{
    $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    if (str_ends_with($path, '/releases')) {
        if (($query['per_page'] ?? null) !== '10') return ['status' => 400, 'body' => '{}'];
        if ($mode === 'no-release') return ['status' => 200, 'body' => '[]'];
        if ($mode === 'bounded-drafts') {
            $rows = array_map(
                static fn (int $id): array => releaseRow($id, 'draft-' . $id, true),
                range(1, 10),
            );
            return ['status' => 200, 'body' => json_encode($rows)];
        }
        $tag = $mode === 'lightweight' ? 'v2.0.0' : 'v1.2.3';
        return ['status' => 200, 'body' => json_encode([
            releaseRow(99, 'v9.9.9-rc1', false, true), releaseRow(42, $tag), releaseRow(41, 'v1.2.2'),
        ])];
    }
    if (str_contains($path, '/git/matching-refs/tags/')) {
        if ($mode === 'missing-tag') return ['status' => 200, 'body' => '[]'];
        if ($mode === 'lightweight') {
            return ['status' => 200, 'body' => json_encode([[
                'ref' => 'refs/tags/v2.0.0', 'object' => ['type' => 'commit', 'sha' => $lightSha],
            ]])];
        }
        $tag = substr($path, strrpos($path, '/') + 1);
        return ['status' => 200, 'body' => json_encode([
            ['ref' => 'refs/tags/' . $tag, 'object' => ['type' => 'tag', 'sha' => $tagSha]],
            ['ref' => 'refs/tags/' . $tag . '-notes', 'object' => ['type' => 'commit', 'sha' => str_repeat('e', 40)]],
        ])];
    }
    if ($path === '/repos/pl0n3r/ControlBot/git/tags/' . $tagSha) {
        return ['status' => 200, 'body' => json_encode([
            'sha' => $tagSha, 'object' => ['type' => 'commit', 'sha' => $commitSha],
        ])];
    }
    if ($path === '/repos/pl0n3r/ControlBot/commits/' . $commitSha) {
        return ['status' => 200, 'body' => json_encode(['sha' => $commitSha])];
    }
    if ($path === '/repos/pl0n3r/ControlBot/commits/' . $lightSha) {
        return ['status' => 200, 'body' => json_encode(['sha' => $lightSha])];
    }
    return ['status' => 404, 'body' => '{}'];
}

function collect(string $mode, string $tagSha, string $commitSha, string $lightSha): array
{
    $calls = [];
    $sender = static function (string $method, string $url, array $headers, ?string $body) use (
        &$calls, $mode, $tagSha, $commitSha, $lightSha
    ): array {
        $calls[] = [$method, $url, $headers, $body];
        return reply($url, $mode, $tagSha, $commitSha, $lightSha);
    };
    $collector = new GitHubReleaseProvenance(new ApiClient('ghp_test_only_token', new ApiTransport($sender)));
    return ['evidence' => $collector->collect('pl0n3r/ControlBot', 300), 'calls' => $calls];
}

if (in_array($mode, ['annotated', 'lightweight', 'missing-tag', 'no-release', 'bounded-drafts'], true)) {
    echo json_encode(collect($mode, $tagSha, $commitSha, $lightSha), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}
fwrite(STDERR, "scenario inválido\n");
exit(2);
