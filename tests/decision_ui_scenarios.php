<?php
declare(strict_types=1);

require __DIR__ . '/../src/DecisionUi.php';

use ControlBot\Decisions\DecisionUi;

$scenario = $argv[1] ?? '';
$csrf = str_repeat('c', 40);
$decision = [[
    'repository' => 'pl0n3r/factory',
    'issue' => 114,
    'title' => '¿Publicamos la nueva versión del kit?',
    'context' => 'Factory espera tu aprobación explícita para continuar.',
    'options' => [
        ['id' => 'A', 'label' => 'Aprobar publicación'],
        ['id' => 'B', 'label' => 'Mantener sin publicar'],
    ],
    'recommendation' => 'A',
    'sha' => str_repeat('a', 40),
]];

if ($scenario === 'empty') {
    echo DecisionUi::render([], true, $csrf);
} elseif ($scenario === 'reauth') {
    echo DecisionUi::render($decision, false, $csrf);
} elseif ($scenario === 'ready') {
    echo DecisionUi::render($decision, true, $csrf);
} elseif ($scenario === 'no-csrf') {
    echo DecisionUi::render($decision, true);
} elseif ($scenario === 'snooze') {
    echo DecisionUi::render($decision, true, $csrf);
} elseif ($scenario === 'batch') {
    $decision[0]['category'] = 'brand';
    $decision[0]['title_simple'] = '¿Aplicar cambio seguro?';
    $decision[0]['options'][0]['risk'] = 'low';
    echo DecisionUi::render($decision, true, $csrf, [[
        'repository' => 'pl0n3r/factory',
        'issue' => 114,
        'category' => 'brand',
        'option' => 'A',
        'displayed_sha' => '',
        'title' => '¿Aplicar cambio seguro?',
    ]]);
} elseif ($scenario === 'enriched' || $scenario === 'enriched-escape' || $scenario === 'invalid-risk' || $scenario === 'default-id' || $scenario === 'zero-copy') {
    $decision[0]['title_simple'] = '¿Publicar la versión 1.0.4?';
    $decision[0]['summary_simple'] = 'El kit espera tu decisión. La versión actual sigue disponible.';
    $decision[0]['why_recommended'] = 'Los controles de calidad pasaron.';
    $decision[0]['safe_default'] = 'Mantener la versión actual';
    $decision[0]['blocks'] = 'Adopción en tres repositorios';
    $decision[0]['options'][0] += [
        'effect' => 'Publica una versión nueva',
        'pros' => ['Permite continuar'],
        'cons' => ['Verificar el despliegue'],
        'risk' => 'medium',
        'cost' => 'Sin costo adicional',
        'reversible' => true,
    ];
    if ($scenario === 'enriched-escape') {
        $decision[0]['options'][0]['effect'] = '<img src=x onerror=alert(1)>';
        $decision[0]['options'][0]['pros'] = ['<script>alert(1)</script>'];
    }
    if ($scenario === 'invalid-risk') {
        $decision[0]['options'][0]['risk'] = ['high'];
    }
    if ($scenario === 'default-id') {
        $decision[0]['safe_default'] = 'B';
    }
    if ($scenario === 'zero-copy') {
        $decision[0]['title_simple'] = '0';
        $decision[0]['summary_simple'] = '0';
    }
    echo DecisionUi::render($decision, true, $csrf);
} elseif ($scenario === 'escape') {
    $decision[0]['title'] = '<script>alert(1)</script>';
    echo DecisionUi::render($decision, true, $csrf);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}
