<?php
declare(strict_types=1);

require __DIR__ . '/../src/GlobalSearchCore.php';
use ControlBot\Search\GlobalSearchCore;

function rejected(callable $fn): bool { try { $fn(); return false; } catch (InvalidArgumentException) { return true; } }

function docFixture(int|string $id, string $title, string $repo, string $fresh = 'fresh', string $access = 'allow', array $roles = ['sre'], string $snippet = 'incident evidence', int $updated = 100): array
{
    $repoName = explode('/', $repo)[1];
    $issue = is_int($id) ? $id : 78;
    return ['version' => 1, 'type' => 'incident', 'title' => $title, 'project' => 'controlbot', 'repo' => $repo,
        'number_or_id' => $id, 'state' => 'closed', 'updated_at' => $updated, 'snippet' => $snippet, 'source' => 'github',
        'canonical_url' => "https://github.com/pl0n3r/{$repoName}/issues/{$issue}", 'freshness' => $fresh, 'roles' => $roles, 'access' => $access];
}

function queryFixture(string $text, array $extra = []): array
{
    return array_replace(['version' => 1, 'text' => $text, 'project' => null, 'type' => null, 'state' => null,
        'role' => null, 'page' => 1, 'per_page' => 10], $extra);
}

function searchFixture(string $text, array $documents, array $extra = []): array
{
    return GlobalSearchCore::search(queryFixture($text, $extra), $documents);
}

function fixtures(): array
{
    return [
        docFixture(78, 'GitHub Actions capacity incident', 'pl0n3r/ControlBot', 'fresh', 'allow', ['sre', 'seguridad'], 'runner capacity exhausted', 300),
        docFixture(78, 'duplicate cached incident', 'pl0n3r/ControlBot', 'stale', 'allow', ['sre'], 'cached copy', 200),
        docFixture(88, 'Capacity review', 'pl0n3r/factory', 'stale', 'allow', ['sre'], 'capacity incident follow-up', 250),
        docFixture(99, 'Other incident', 'pl0n3r/FactoryRunner', 'unavailable', 'allow', ['sre'], 'capacity unavailable', 240),
    ];
}

$case = $argv[1] ?? '';
if ($case === 'number') {
    $out = searchFixture('#78', fixtures());
} elseif ($case === 'stable') {
    $docs = fixtures();
    $docs[] = docFixture(130, 'Capacity tie B', 'pl0n3r/ControlBot', 'fresh', 'allow', ['sre'], 'tie B', 400);
    $docs[] = docFixture(130, 'Capacity tie A', 'pl0n3r/ControlBot', 'fresh', 'allow', ['sre'], 'tie A', 400);
    $out = ['a' => searchFixture('capacity', $docs), 'b' => searchFixture('capacity', array_reverse($docs)),
        'unicode' => searchFixture('DECISIÓN', [docFixture(131, 'Decisión', 'pl0n3r/ControlBot')])];
} elseif ($case === 'filters') {
    $docs = fixtures();
    $docs[] = array_replace(docFixture(101, 'Capacity security', 'pl0n3r/ControlBot', 'fresh', 'allow', ['seguridad'], 'capacity signal', 290),
        ['type' => 'issue', 'state' => 'open', 'canonical_url' => 'https://github.com/pl0n3r/ControlBot/issues/101']);
    $query = ['project' => 'controlbot', 'role' => 'sre', 'page' => 2, 'per_page' => 1];
    $out = ['a' => searchFixture('capacity', $docs, $query), 'b' => searchFixture('capacity', array_reverse($docs), $query),
        'typed' => searchFixture('capacity', $docs, ['type' => 'issue', 'state' => 'open'])];
} elseif ($case === 'freshness') {
    $out = searchFixture('capacity', fixtures());
} elseif ($case === 'permissions') {
    $docs = fixtures();
    $docs[] = docFixture(120, 'Capacity denied', 'pl0n3r/ControlBot', 'fresh', 'deny', ['sre'], 'password=hunter2', 500);
    $docs[] = docFixture(121, 'Capacity unknown', 'pl0n3r/ControlBot', 'fresh', 'unknown', ['sre'], 'token=abc', 600);
    $out = searchFixture('capacity', $docs);
} elseif ($case === 'sanitize') {
    $docs = [docFixture(122, 'Capacity token=abc alice@example.com', 'pl0n3r/ControlBot', 'fresh', 'allow', ['sre'],
        'Bearer xyz {"password":"hunter two words","token":"jsonabc"} +57 300 123 4567', 700)];
    $network = array_replace($docs[0], ['canonical_url' => '//evil.example/path']);
    $dots = array_replace($docs[0], ['canonical_url' => 'https://github.com/pl0n3r/ControlBot/../other']);
    $out = ['search' => searchFixture('capacity', $docs),
        'network_rejected' => rejected(fn() => searchFixture('capacity', [$network])),
        'dot_rejected' => rejected(fn() => searchFixture('capacity', [$dots]))];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
