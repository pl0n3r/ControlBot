<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/GateInbox.php';

use ControlBot\Decisions\GateInbox;
use ControlBot\Decisions\ReleaseRunTracker;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;

function gateBody(string $category, string $context = 'Necesita decisión.', ?string $blocks = null): string
{
    $payload = [
        'category' => $category,
        'context' => $context,
        'options' => [
            ['id' => 'A', 'label' => 'Aprobar', 'effect' => 'Continúa el flujo.', 'pros' => ['Desbloquea'], 'cons' => ['Cambia estado'], 'risk' => 'low', 'cost' => '', 'reversible' => true],
            ['id' => 'B', 'label' => 'No aprobar', 'effect' => 'Mantiene el estado.', 'pros' => ['Sin cambio'], 'cons' => ['Sigue pendiente'], 'risk' => 'low', 'cost' => '', 'reversible' => true],
        ],
        'recommendation' => 'A',
        'safe_default' => 'B',
        'title_simple' => '¿Continuamos?',
        'summary_simple' => 'Hay una decisión pendiente.',
        'why_recommended' => 'Los controles requeridos pasaron.',
    ];
    if ($blocks !== null) {
        $payload['blocks'] = $blocks;
    }
    return '<!-- factory-human-gate ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . ' -->';
}

function issue(int $number, string $association, string $body, string $title, string $createdAt, array $extra = []): array
{
    return [
        'number' => $number,
        'state' => 'open',
        'author_association' => $association,
        'body' => $body,
        'title' => $title,
        'created_at' => $createdAt,
        'html_url' => "https://github.com/pl0n3r/demo/issues/{$number}",
    ] + $extra;
}

$scenario = $argv[1] ?? '';
$responses = [];
if ($scenario === 'trusted') {
    $responses = [
        'https://api.github.com/repos/pl0n3r/ControlBot/issues' => ['status' => 200, 'body' => json_encode([
            issue(5, 'MEMBER', gateBody('legal', 'Puerta confiable.', 'Bloquea un flujo.'), 'Confiable', '2026-09-25T10:00:00Z'),
            issue(6, 'NONE', gateBody('legal'), 'No confiable', '2026-09-25T09:00:00Z'),
            issue(7, 'OWNER', 'Issue normal sin marker.', 'Normal', '2026-09-25T08:00:00Z'),
            issue(8, 'OWNER', gateBody('legal'), 'PR', '2026-09-25T07:00:00Z', ['pull_request' => ['url' => 'x']]),
            issue(9, 'OWNER', gateBody('legal'), 'Cerrada', '2026-09-25T06:00:00Z', ['state' => 'closed']),
        ], JSON_THROW_ON_ERROR)],
        'https://api.github.com/repos/pl0n3r/factory/issues' => ['status' => 200, 'body' => json_encode([
            issue(10, 'OWNER', gateBody('brand'), 'Segunda puerta', '2026-09-25T05:00:00Z'),
        ], JSON_THROW_ON_ERROR)],
    ];
} elseif ($scenario === 'release') {
    $sha = str_repeat('a', 40);
    $responses = [
        'https://api.github.com/repos/pl0n3r/factory/issues' => ['status' => 200, 'body' => json_encode([
            issue(137, 'OWNER', gateBody('factory-release', 'Publicar Factory.', 'Desbloquea adopciones.'), 'Release Factory', '2026-09-25T10:00:00Z'),
        ], JSON_THROW_ON_ERROR)],
        'https://api.github.com/repos/pl0n3r/factory/branches/main' => ['status' => 200, 'body' => json_encode(['commit' => ['sha' => $sha]], JSON_THROW_ON_ERROR)],
        "https://api.github.com/repos/pl0n3r/factory/commits/{$sha}/check-runs" => ['status' => 200, 'body' => json_encode([
            'total_count' => 2,
            'check_runs' => [
                ['status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/pl0n3r/factory/actions/runs/1'],
                ['status' => 'completed', 'conclusion' => 'skipped', 'html_url' => 'https://github.com/pl0n3r/factory/actions/runs/2'],
            ],
        ], JSON_THROW_ON_ERROR)],
        "https://api.github.com/repos/pl0n3r/factory/commits/{$sha}" => ['status' => 200, 'body' => json_encode([
            'sha' => $sha,
            'html_url' => "https://github.com/pl0n3r/factory/commit/{$sha}",
        ], JSON_THROW_ON_ERROR)],
    ];
} elseif ($scenario === 'empty') {
    $responses = [
        'https://api.github.com/repos/pl0n3r/ControlBot/issues' => ['status' => 200, 'body' => '[]'],
        'https://api.github.com/repos/pl0n3r/factory/issues' => ['status' => 200, 'body' => '[]'],
    ];
} elseif ($scenario === 'tracker-success' || $scenario === 'tracker-failure') {
    $sha = str_repeat('b', 40);
    $conclusion = $scenario === 'tracker-success' ? 'success' : 'failure';
    $responses = [
        'https://api.github.com/repos/pl0n3r/factory/actions/workflows/release-bootstrap.yml/runs' => [
            'status' => 200,
            'body' => json_encode(['workflow_runs' => [
                ['id' => 1, 'event' => 'push', 'head_sha' => $sha, 'created_at' => '2026-09-25T10:01:00Z', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/run/1'],
                ['id' => 2, 'event' => 'workflow_dispatch', 'head_sha' => str_repeat('c', 40), 'created_at' => '2026-09-25T10:02:00Z', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/run/2'],
                ['id' => 3, 'event' => 'workflow_dispatch', 'head_sha' => $sha, 'created_at' => '2026-09-25T09:59:00Z', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/run/3'],
                ['id' => 4, 'event' => 'workflow_dispatch', 'head_sha' => $sha, 'created_at' => '2026-09-25T10:03:00Z', 'status' => 'completed', 'conclusion' => $conclusion, 'html_url' => 'https://github.com/run/4'],
            ]], JSON_THROW_ON_ERROR),
        ],
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

$seen = [];
$transport = new ApiTransport(static function (string $method, string $url, array $headers, ?string $body) use (&$responses, &$seen): array {
    $seen[] = [$method, $url];
    if (!isset($responses[$url])) {
        throw new RuntimeException("fixture ausente: {$url}");
    }
    return $responses[$url];
});
$api = new ApiClient('ghp_fixture', $transport);
$gateway = new Gateway($api);

if (str_starts_with($scenario, 'tracker-')) {
    $tracker = new ReleaseRunTracker($api);
    $result = $tracker->status(
        'pl0n3r/factory',
        'release-bootstrap.yml',
        str_repeat('b', 40),
        strtotime('2026-09-25T10:00:00Z'),
    );
    echo json_encode(['result' => $result, 'seen' => $seen], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$inbox = new GateInbox($api, $gateway);
$repos = $scenario === 'release' ? ['pl0n3r/factory'] : ['pl0n3r/ControlBot', 'pl0n3r/factory'];
$result = $inbox->load($repos);
echo json_encode(['decisions' => $result, 'seen' => $seen], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
