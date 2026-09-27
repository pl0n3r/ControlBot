<?php
declare(strict_types=1);

require __DIR__ . '/../src/BudgetDashboardUi.php';

use ControlBot\Budget\BudgetDashboardUi;

$name = $argv[1] ?? '';

function budget_account(array $overrides = []): array {
    return array_replace([
        'provider' => 'github_actions',
        'account_scope' => 'pl0n3r',
        'resource' => 'private_minutes',
        'included' => 2000,
        'used' => 1800,
        'billable_used' => 0,
        'reset_at' => '2026-10-01',
        'budget_limit' => 0,
        'budget_mode' => 'alert_only',
        'capacity' => 'available',
        'observed_at' => 1790467200,
        'source' => 'github_api',
    ], $overrides);
}

function budget_projects(string $project = 'FactoryRunner'): array {
    return [
        [
            'project' => $project,
            'repository' => 'pl0n3r/FactoryRunner',
            'repository_visibility' => 'private',
            'billing_scope_observed_at' => 1790467200,
            'attributed_used' => 1000,
            'projected_baseline_monthly' => 1440,
        ],
        [
            'project' => 'ControlBot',
            'repository' => 'pl0n3r/ControlBot',
            'repository_visibility' => 'public',
            'billing_scope_observed_at' => 1790467200,
            'attributed_used' => 800,
            'projected_baseline_monthly' => 275,
        ],
    ];
}

if ($name === 'real') {
    echo BudgetDashboardUi::render(budget_account(), budget_projects());
} elseif ($name === 'unknown') {
    echo BudgetDashboardUi::render(
        budget_account(['included' => null, 'used' => null, 'reset_at' => null, 'capacity' => 'unknown']),
        []
    );
} elseif ($name === 'escape') {
    echo BudgetDashboardUi::render(
        budget_account(),
        budget_projects('<script>alert("budget")</script>')
    );
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}
