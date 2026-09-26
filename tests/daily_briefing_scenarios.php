<?php
declare(strict_types=1);

require __DIR__ . '/../src/DailyBriefing.php';

use ControlBot\Briefing\DailyBriefing;

function item(string $text, string $evidence = 'https://github.com/pl0n3r/ControlBot/issues/5', bool $attention = false): array
{
    $item = ['text' => $text, 'evidence' => $evidence];
    if ($attention) {
        $item['requires_attention'] = true;
    }
    return $item;
}

$scenario = $argv[1] ?? '';
$briefing = new DailyBriefing(['github.com', 'control.condorapp.com.co']);

$facts = [];
$tamper = null;
if ($scenario === 'full') {
    $facts = [
        'delivered' => [item('Se fusionó el PR #42.', 'https://github.com/pl0n3r/ControlBot/pull/42')],
        'today' => [item('Continúa el centro de decisiones.', 'https://github.com/pl0n3r/ControlBot/issues/4')],
        'broken' => [item('CI de main está bloqueado.', 'https://github.com/pl0n3r/ControlBot/actions', true)],
        'decisions' => [item('Hay una decisión de privacidad pendiente.', 'https://github.com/pl0n3r/ControlBot/issues/20')],
        'spend' => [item('Costo registrado: 12.34 USD.', 'https://control.condorapp.com.co/evidence/costs/2026-09-25')],
    ];
} elseif ($scenario === 'text-says-urgent') {
    $facts = [
        'today' => [item('URGENTE: palabras alarmantes sin flag estructurado.')],
    ];
} elseif ($scenario === 'escape') {
    $facts = [
        'today' => [item('<script>alert(1)</script>', 'https://github.com/pl0n3r/ControlBot/issues/5?x=%22%3E%3Cscript%3E')],
    ];
} elseif ($scenario === 'invalid-http') {
    $facts = [
        'today' => [item('Dato inválido', 'http://github.com/pl0n3r/ControlBot/issues/5')],
    ];
} elseif ($scenario === 'invalid-host') {
    $facts = [
        'today' => [item('Dato inválido', 'https://evil.example/evidence')],
    ];
} elseif ($scenario === 'tampered-render-url') {
    $facts = ['today' => [item('Dato inicialmente seguro')]];
    $tamper = 'url';
} elseif ($scenario === 'tampered-render-empty') {
    $facts = ['today' => [item('Dato inicialmente seguro')]];
    $tamper = 'empty';
} elseif ($scenario === 'tampered-render-attention') {
    $facts = ['today' => [item('Dato inicialmente seguro')]];
    $tamper = 'attention';
} elseif ($scenario === 'too-many') {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = item("Línea {$i}");
    }
    $facts = ['today' => $items];
} elseif ($scenario !== 'empty') {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

try {
    $built = $briefing->build($facts);
    if ($tamper === 'url') {
        $built['sections']['today']['items'][0]['evidence'] = 'javascript:alert(1)';
    } elseif ($tamper === 'empty') {
        $built['sections']['today']['empty'] = true;
    } elseif ($tamper === 'attention') {
        $built['sections']['today']['items'][0]['requires_attention'] = true;
    }

    echo json_encode([
        'built' => $built,
        'html' => $briefing->renderHtml($built),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
