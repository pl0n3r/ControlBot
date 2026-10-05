<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ControlCenterShell.php';
require_once __DIR__ . '/../src/DashboardUi.php';

use ControlBot\Dashboard\DashboardUi;
use ControlBot\Ui\ControlCenterShell;

$scenario = $argv[1] ?? '';
$routes = [
    'overview' => '/overview',
    'projects' => '/projects',
    'accounts' => '/accounts',
    'agents' => '/agents',
    'work' => '/work',
    'factory-live' => '/factory-live',
    'github' => '/github',
    'decisions' => '/decisions',
];

if ($scenario === 'canonical') {
    echo ControlCenterShell::render('Trabajo', 'work', $routes, '<section><h1>Trabajo</h1><p>Solo lectura.</p></section>');
    exit;
}

if ($scenario === 'invalid-routes') {
    echo ControlCenterShell::render('Resumen', 'overview', [
        'overview' => '/overview',
        'projects' => 'https://evil.example/projects',
        'accounts' => 'javascript:alert(1)',
        'agents' => null,
        'work' => '/work',
        'extra' => '/should-never-render',
    ], '<section><h1>Resumen</h1></section>');
    exit;
}

if ($scenario === 'dashboard') {
    echo DashboardUi::render([
        'health' => 'not-a-health-state',
        'domains' => [
            'production' => ['sha' => 'abc123'],
            'work' => ['issues activos' => 2],
            'security' => [],
            'costs' => [],
        ],
    ], $routes);
    exit;
}

if ($scenario === 'escape') {
    echo ControlCenterShell::render('<script>alert(1)</script>', 'overview', $routes, '<section><h1>Contenido seguro</h1></section>');
    exit;
}

fwrite(STDERR, "unknown scenario\n");
exit(2);
