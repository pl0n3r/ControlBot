<?php
declare(strict_types=1);

require __DIR__ . '/../src/UiTheme.php';
require __DIR__ . '/../src/DashboardUi.php';

use ControlBot\Dashboard\DashboardUi;

$scenario = $argv[1] ?? '';
$state = [];

if ($scenario === 'real') {
    $state = [
        'health' => 'warning',
        'domains' => [
            'production' => ['sitio' => 'degradado', 'sha' => 'abc123'],
            'work' => ['issues activos' => 7],
            'security' => ['alertas' => 0],
            'costs' => ['mes' => '$12.34'],
        ],
    ];
} elseif ($scenario === 'invalid-health') {
    $state = ['health' => 'excellent'];
} elseif ($scenario === 'escape') {
    $state = [
        'health' => 'healthy',
        'domains' => [
            'production' => ['<script>alert(1)</script>' => '<img src=x onerror=alert(1)>'],
        ],
    ];
} elseif ($scenario !== 'empty') {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo DashboardUi::render($state), PHP_EOL;
