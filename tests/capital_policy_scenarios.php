<?php
declare(strict_types=1);

require __DIR__ . '/../src/BudgetGuard.php';
require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/CapitalPolicy.php';

use ControlBot\Budget\BudgetGuard;
use ControlBot\Business\CapitalPolicy;
use ControlBot\Business\DecisionRights;

const NOW = 2000;
const POLICY = 'controlbot:policy/business-os-v1';

function budgetGuardResult(bool $blocked = false): array
{
    $guard = new BudgetGuard();
    $summary = $guard->summarize([
        'provider' => 'github_actions',
        'account_scope' => 'pl0n3r',
        'resource' => 'private_minutes',
        'included' => 100,
        'used' => $blocked ? 100 : 10,
        'billable_used' => 0,
        'reset_at' => '2026-10-01',
        'budget_limit' => 0,
        'budget_mode' => $blocked ? 'hard_stop' : 'alert_only',
        'capacity' => 'available',
        'observed_at' => 1900,
        'source' => 'github_api',
    ], []);

    return $guard->workPolicy($summary, [
        'critical' => false,
        'requires_private_runner' => true,
        'pr_open' => false,
    ]);
}

function identity(string $id = 'identity-finance'): array
{
    return [
        'version' => 1,
        'identity_id' => $id,
        'kind' => 'human',
        'display_name' => 'Finance Admin',
        'state' => 'active',
        'source_ref' => 'controlbot:identity/' . $id,
        'observed_at' => 1900,
    ];
}

function rightsResult(
    string $scope = 'venture:condor',
    string $capability = 'capital.propose',
    string $authority = 'L2_VENTURE_ADMIN',
    float $budgetLimit = 20_000_000.0,
): array {
    $context = [
        'identity' => identity(),
        'scope' => $scope,
        'active_policy_refs' => [POLICY],
    ];
    $grant = [
        'version' => 1,
        'grant_id' => 'grant-finance',
        'identity_id' => 'identity-finance',
        'role' => $authority === 'L4_OWNER' ? 'owner' : 'venture_admin',
        'capability' => $capability,
        'scope' => $scope,
        'authority_level' => $authority,
        'policy_ref' => POLICY,
        'budget_limit' => $budgetLimit,
        'granted_at' => 1800,
        'expires_at' => 3000,
    ];
    $action = [
        'capability' => $capability,
        'required_authority_level' => $authority,
        'budget_amount' => 5_000_000.0,
    ];

    return DecisionRights::evaluate($context, $grant, $action, NOW);
}

function baseCapital(): array
{
    return [
        'version' => 1,
        'scope' => 'venture:condor',
        'currency' => 'COP',
        'budget' => [
            'scope' => 'venture:condor',
            'currency' => 'COP',
            'state' => 'known',
            'limit_minor' => 20_000_000,
            'spent_minor' => 5_000_000,
            'committed_minor' => 2_000_000,
            'source_ref' => 'controlbot:finance/budget-condor',
            'observed_at' => 1900,
        ],
        'reserve' => [
            'scope' => 'venture:condor',
            'currency' => 'COP',
            'state' => 'known',
            'cash_available_minor' => 50_000_000,
            'minimum_reserve_minor' => 20_000_000,
            'runway_months' => 6,
            'source_ref' => 'controlbot:finance/reserve-condor',
            'observed_at' => 1900,
        ],
        'proposal' => [
            'proposal_id' => 'proposal-growth',
            'scope' => 'venture:condor',
            'currency' => 'COP',
            'amount_minor' => 5_000_000,
            'required_authority_level' => 'L2_VENTURE_ADMIN',
            'irreversible' => false,
            'shared_costs' => [[
                'cost_id' => 'cost-infra',
                'currency' => 'COP',
                'amount_minor' => 1_000_000,
                'target_scope' => 'venture:condor',
                'rule_ref' => 'controlbot:finance/rule-direct',
                'provenance_ref' => 'controlbot:finance/source-invoice-rollup',
            ]],
        ],
        'budget_guard' => budgetGuardResult(),
        'decision_rights' => rightsResult(),
    ];
}

$name = $argv[1] ?? '';

if ($name === 'base') {
    $out = CapitalPolicy::evaluate(baseCapital());
} elseif ($name === 'restrictions') {
    $budgetBlocked = baseCapital();
    $budgetBlocked['budget_guard'] = budgetGuardResult(true);

    $rightsDenied = baseCapital();
    $rightsDenied['decision_rights'] = DecisionRights::evaluate(
        [
            'identity' => identity(),
            'scope' => 'venture:condor',
            'active_policy_refs' => [POLICY],
        ],
        [
            'version' => 1,
            'grant_id' => 'grant-finance',
            'identity_id' => 'identity-finance',
            'role' => 'venture_admin',
            'capability' => 'capital.propose',
            'scope' => 'venture:condor',
            'authority_level' => 'L2_VENTURE_ADMIN',
            'policy_ref' => POLICY,
            'budget_limit' => 20_000_000.0,
            'granted_at' => 1800,
            'expires_at' => 3000,
        ],
        [
            'capability' => 'capital.delete',
            'required_authority_level' => 'L2_VENTURE_ADMIN',
            'budget_amount' => 5_000_000.0,
        ],
        NOW,
    );

    $out = [
        'baseline' => CapitalPolicy::evaluate(baseCapital()),
        'budget_guard' => CapitalPolicy::evaluate($budgetBlocked),
        'decision_rights' => CapitalPolicy::evaluate($rightsDenied),
    ];
} elseif ($name === 'owner-gates') {
    $over = baseCapital();
    $over['budget']['limit_minor'] = 10_000_000;
    $over['budget']['spent_minor'] = 8_000_000;
    $over['budget']['committed_minor'] = 1_000_000;
    $over['proposal']['amount_minor'] = 2_000_000;

    $l4 = baseCapital();
    $l4['proposal']['required_authority_level'] = 'L4_OWNER';
    $l4['decision_rights'] = rightsResult(
        scope: 'venture:condor',
        capability: 'capital.propose',
        authority: 'L4_OWNER',
        budgetLimit: 20_000_000.0,
    );

    $irreversible = baseCapital();
    $irreversible['proposal']['irreversible'] = true;

    $reserve = baseCapital();
    $reserve['reserve']['cash_available_minor'] = 24_000_000;
    $reserve['proposal']['amount_minor'] = 5_000_000;

    $out = [
        'over_limit' => CapitalPolicy::evaluate($over),
        'l4' => CapitalPolicy::evaluate($l4),
        'irreversible' => CapitalPolicy::evaluate($irreversible),
        'reserve_floor' => CapitalPolicy::evaluate($reserve),
    ];
} elseif ($name === 'shared-costs') {
    $input = baseCapital();
    $input['proposal']['shared_costs'][] = [
        'cost_id' => 'cost-shared',
        'currency' => 'COP',
        'amount_minor' => 500_000,
        'target_scope' => 'venture:grindflow',
        'rule_ref' => null,
        'provenance_ref' => null,
    ];
    $out = CapitalPolicy::evaluate($input);
} elseif ($name === 'no-payments') {
    $secret = 'bank-token-super-secret';
    $invalid = baseCapital();
    $invalid['bank_token'] = $secret;
    $evaluated = CapitalPolicy::evaluate($invalid);

    try {
        CapitalPolicy::executePayment(['bank_token' => $secret]);
        $payment = 'executed';
    } catch (Throwable $e) {
        $payment = $e->getMessage();
    }

    $out = [
        'evaluated' => $evaluated,
        'payment' => $payment,
        'leaked' => str_contains(json_encode($evaluated, JSON_THROW_ON_ERROR), $secret),
    ];
} elseif ($name === 'deterministic') {
    $first = CapitalPolicy::evaluate(baseCapital());
    $second = CapitalPolicy::evaluate(baseCapital());

    $unknown = baseCapital();
    $unknown['budget']['state'] = 'unknown';

    $mismatch = baseCapital();
    $mismatch['reserve']['currency'] = 'USD';

    $out = [
        'first' => $first,
        'second' => $second,
        'same' => $first === $second,
        'unknown' => CapitalPolicy::evaluate($unknown),
        'mismatch' => CapitalPolicy::evaluate($mismatch),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
