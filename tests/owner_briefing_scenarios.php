<?php
declare(strict_types=1);

require __DIR__ . '/../src/OwnerBriefing.php';

use ControlBot\Briefing\OwnerBriefing;

$name = $argv[1] ?? '';

function item(string $summary, string $evidence): array {
    return ['summary' => $summary, 'evidence' => $evidence];
}

function snapshot(array $overrides = []): array {
    return array_replace([
        'delivered' => [],
        'today' => [],
        'broken' => [],
        'decisions' => [],
        'costs' => [],
    ], $overrides);
}

function blocked(callable $build): array {
    try {
        $build();
        return ['blocked' => false];
    } catch (InvalidArgumentException $e) {
        return ['blocked' => true];
    }
}

if ($name === 'fail-closed') {
    $out = OwnerBriefing::build(snapshot(['broken' => null, 'costs' => null]));
} elseif ($name === 'quiet') {
    $out = OwnerBriefing::build(snapshot([
        'delivered' => [item('PR #101 integrado', 'https://github.com/pl0n3r/ControlBot/pull/101')],
        'today' => [item('Preparar briefing', 'controlbot:issue/102')],
    ]));
} elseif ($name === 'decision') {
    $out = OwnerBriefing::build(snapshot([
        'decisions' => [item('Decisión legal pendiente', 'https://github.com/pl0n3r/ControlBot/issues/20')],
    ]));
} elseif ($name === 'overflow') {
    $rows = [];
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = item("Entrega {$i}", "controlbot:delivery/{$i}");
    }
    $out = OwnerBriefing::build(snapshot(['delivered' => $rows]));
} elseif ($name === 'safe-evidence') {
    $out = OwnerBriefing::build(snapshot([
        'delivered' => [item('Entrega segura', 'https://github.com/pl0n3r/ControlBot/pull/101')],
        'today' => [item('Trabajo actual', 'controlbot:work/102')],
    ]));
} elseif ($name === 'reordered-item') {
    $out = OwnerBriefing::build(snapshot([
        'today' => [[
            'evidence' => 'controlbot:work/102',
            'summary' => 'Orden de claves independiente',
        ]],
    ]));
} elseif ($name === 'bad-path-evidence') {
    $out = blocked(fn() => OwnerBriefing::build(snapshot([
        'today' => [item('Fuente inválida', 'https://github.com/pl0n3r/ControlBot/token/secret-value')],
    ])));
} elseif ($name === 'bad-evidence') {
    $out = blocked(fn() => OwnerBriefing::build(snapshot([
        'today' => [item('Fuente inválida', 'https://example.com/report?token=abc')],
    ])));
} elseif ($name === 'secret-summary') {
    $out = blocked(fn() => OwnerBriefing::build(snapshot([
        'today' => [item('token=super-secret-value', 'controlbot:work/102')],
    ])));
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
