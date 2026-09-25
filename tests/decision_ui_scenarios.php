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
} elseif ($scenario === 'escape') {
    $decision[0]['title'] = '<script>alert(1)</script>';
    echo DecisionUi::render($decision, true);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}
