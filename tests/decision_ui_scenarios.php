<?php
declare(strict_types=1);

require __DIR__ . '/../src/DecisionUi.php';

use ControlBot\Decisions\DecisionUi;

$scenario = $argv[1] ?? '';
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
    echo DecisionUi::render([], true);
} elseif ($scenario === 'reauth') {
    echo DecisionUi::render($decision, false);
} elseif ($scenario === 'ready') {
    echo DecisionUi::render($decision, true);
} elseif ($scenario === 'enriched' || $scenario === 'enriched-escape' || $scenario === 'invalid-risk') {
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
    echo DecisionUi::render($decision, true);
} elseif ($scenario === 'escape') {
    $decision[0]['title'] = '<script>alert(1)</script>';
    echo DecisionUi::render($decision, true);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}
