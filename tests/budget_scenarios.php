<?php
declare(strict_types=1);

require __DIR__ . '/../src/BudgetGuard.php';

use ControlBot\Budget\BudgetGuard;

$guard = new BudgetGuard();
$name = $argv[1] ?? '';

function account(array $overrides = []): array {
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

function projects(): array {
    return [
        [
            'project' => 'FactoryRunner',
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

if ($name === 'summary') {
    $out = $guard->summarize(account(), projects());
} elseif ($name === 'missing') {
    $raw = account();
    unset($raw['included']);
    $out = $guard->summarize($raw, projects());
} elseif ($name === 'baseline') {
    $out = $guard->summarize(account(['used' => 100]), [[
        'project' => 'Private automation',
        'repository' => 'pl0n3r/PrivateAutomation',
        'repository_visibility' => 'private',
        'billing_scope_observed_at' => 1790467200,
        'attributed_used' => 50,
        'projected_baseline_monthly' => 1700,
    ]]);
} elseif ($name === 'thresholds') {
    $out = [
        'critical' => $guard->summarize(account(['used' => 1800]), []),
        'exhausted' => $guard->summarize(account(['used' => 2000]), []),
        'blocked' => $guard->summarize(account(['used' => 2000, 'budget_mode' => 'hard_stop']), []),
    ];
} elseif ($name === 'policy') {
    $blocked = $guard->summarize(account(['used' => 2000, 'budget_mode' => 'hard_stop']), []);
    $out = [
        'noncritical' => $guard->workPolicy($blocked, ['critical' => false, 'requires_private_runner' => true, 'pr_open' => true]),
        'unknown' => $guard->workPolicy($guard->summarize(account(['included' => null]), []), ['critical' => false, 'requires_private_runner' => true, 'pr_open' => true]),
    ];
} elseif ($name === 'recovery') {
    $before = $guard->summarize(account(['used' => 2000, 'budget_mode' => 'hard_stop']), []);
    $after = $guard->summarize(account(['used' => 1000]), []);
    $sha = str_repeat('a', 40);
    $out = [
        'pending' => $guard->recovery($before, $after, $sha),
        'success' => $guard->recovery($before, $after, $sha, ['sha' => $sha, 'conclusion' => 'success']),
        'stale' => $guard->recovery($before, $after, $sha, ['sha' => str_repeat('b', 40), 'conclusion' => 'success']),
    ];
} elseif ($name === 'mutation') {
    try {
        $guard->mutateFinancial('change_spending_limit');
        $out = ['blocked' => false];
    } catch (LogicException $e) {
        $out = ['blocked' => true, 'message' => $e->getMessage()];
    }
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
