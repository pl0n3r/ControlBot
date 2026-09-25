<?php
declare(strict_types=1);

require __DIR__ . '/../src/DailyBriefing.php';

use ControlBot\Briefing\DailyBriefing;

$scenario = $argv[1] ?? '';
$briefing = new DailyBriefing(['github.com', 'control.condorapp.com.co']);

$facts = [];
if ($scenario === 'full') {
    $facts = [
        'delivered' => [[
            'text' => 'Se fusionó el PR #42.',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/pull/42',
        ]],
        'today' => [[
            'text' => 'Continúa el centro de decisiones.',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/issues/4',
        ]],
        'broken' => [[
            'text' => 'CI de main está bloqueado.',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/actions',
            'requires_attention' => true,
        ]],
        'decisions' => [[
            'text' => 'Hay una decisión de privacidad pendiente.',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/issues/20',
        ]],
        'spend' => [[
            'text' => 'Costo registrado: 12.34 USD.',
            'evidence' => 'https://control.condorapp.com.co/evidence/costs/2026-09-25',
        ]],
    ];
} elseif ($scenario === 'text-says-urgent') {
    $facts = [
        'today' => [[
            'text' => 'URGENTE: palabras alarmantes sin flag estructurado.',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/issues/5',
        ]],
    ];
} elseif ($scenario === 'escape') {
    $facts = [
        'today' => [[
            'text' => '<script>alert(1)</script>',
            'evidence' => 'https://github.com/pl0n3r/ControlBot/issues/5?x=%22%3E%3Cscript%3E',
        ]],
    ];
} elseif ($scenario === 'invalid-http') {
    $facts = [
        'today' => [[
            'text' => 'Dato inválido',
            'evidence' => 'http://github.com/pl0n3r/ControlBot/issues/5',
        ]],
    ];
} elseif ($scenario === 'invalid-host') {
    $facts = [
        'today' => [[
            'text' => 'Dato inválido',
            'evidence' => 'https://evil.example/evidence',
        ]],
    ];
} elseif ($scenario === 'too-many') {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = [
            'text' => "Línea {$i}",
            'evidence' => 'https://github.com/pl0n3r/ControlBot/issues/5',
        ];
    }
    $facts = ['today' => $items];
} elseif ($scenario !== 'empty') {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

try {
    $built = $briefing->build($facts);
    echo json_encode([
        'built' => $built,
        'html' => $briefing->renderHtml($built),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
