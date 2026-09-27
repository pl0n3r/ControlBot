<?php
declare(strict_types=1);

require __DIR__ . '/../src/OwnerBriefing.php';

use ControlBot\Briefing\OwnerBriefing;

$name = $argv[1] ?? '';

function item(string $summary, string $evidence): array {
    return ['summary' => $summary, 'evidence' => $evidence];
}

if ($name === 'fail-closed') {
    $out = OwnerBriefing::build([
        'delivered' => [],
        'today' => [],
        'broken' => null,
        'decisions' => [],
        'costs' => null,
    ]);
} elseif ($name === 'quiet') {
    $out = OwnerBriefing::build([
        'delivered' => [item('PR #101 integrado', 'https://github.com/pl0n3r/ControlBot/pull/101')],
        'today' => [item('Preparar briefing', 'controlbot:issue/102')],
        'broken' => [],
        'decisions' => [],
        'costs' => [],
    ]);
} elseif ($name === 'decision') {
    $out = OwnerBriefing::build([
        'broken' => [],
        'decisions' => [item('Decisión legal pendiente', 'https://github.com/pl0n3r/ControlBot/issues/20')],
    ]);
} elseif ($name === 'overflow') {
    $rows = [];
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = item("Entrega {$i}", "controlbot:delivery/{$i}");
    }
    $out = OwnerBriefing::build(['delivered' => $rows, 'broken' => [], 'decisions' => []]);
} elseif ($name === 'safe-evidence') {
    $out = OwnerBriefing::build([
        'delivered' => [item('Entrega segura', 'https://github.com/pl0n3r/ControlBot/pull/101')],
        'today' => [item('Trabajo actual', 'controlbot:work/102')],
        'broken' => [],
        'decisions' => [],
        'costs' => [],
    ]);
} elseif ($name === 'reordered-item') {
    $out = OwnerBriefing::build([
        'broken' => [],
        'decisions' => [],
        'today' => [[
            'evidence' => 'controlbot:work/102',
            'summary' => 'Orden de claves independiente',
        ]],
    ]);
} elseif ($name === 'bad-path-evidence') {
    try {
        OwnerBriefing::build([
            'broken' => [],
            'decisions' => [],
            'today' => [item('Fuente inválida', 'https://github.com/pl0n3r/ControlBot/token/secret-value')],
        ]);
        $out = ['blocked' => false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked' => true];
    }
} elseif ($name === 'bad-evidence') {
    try {
        OwnerBriefing::build([
            'broken' => [],
            'decisions' => [],
            'today' => [item('Fuente inválida', 'https://example.com/report?token=abc')],
        ]);
        $out = ['blocked' => false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked' => true];
    }
} elseif ($name === 'secret-summary') {
    try {
        OwnerBriefing::build([
            'broken' => [],
            'decisions' => [],
            'today' => [item('token=super-secret-value', 'controlbot:work/102')],
        ]);
        $out = ['blocked' => false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked' => true];
    }
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
