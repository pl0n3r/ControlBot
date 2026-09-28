<?php
declare(strict_types=1);

require __DIR__ . '/../src/BudgetGuard.php';
require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/CapitalPolicy.php';

use ControlBot\Business\CapitalPolicy;

const NOW = 2000;
const POLICY = 'controlbot:policy/business-os-v1';

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

function budgetGuardInput(bool $blocked = false): array
{
    return [
        'account' => [
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
        ],
        'projects' => [],
        'work' => [
            'critical' => false,
            'requires_private_runner' => true,
            'pr_open' => false,
        ],
    ];
}

function decisionRightsInput(
    string $scope = 'venture:condor',
    string $capability = 'capital.propose',
    string $authority = 'L2_VENTURE_ADMIN',
    float $budgetLimit = 20_000_000.0,
): array {
    return [
        'context' => [
            'identity' => identity(),
            'scope' => $scope,
            'active_policy_refs' => [POLICY],
        ],
        'grant' => [
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
        ],
        'action' => [
            'capability' => $capability,
            'required_authority_level' => $authority,
            'budget_amount' => 5_000_000.0,
        ],
    ];
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
        'budget_guard_input' => budgetGuardInput(),
        'decision_rights_input' => decisionRightsInput(),
    ];
}

$name = $argv[1] ?? '';

if ($name === 'base') {
    $out = CapitalPolicy::evaluate(baseCapital(), NOW);
} elseif ($name === 'restrictions') {
    $budgetBlocked = baseCapital();
    $budgetBlocked['budget_guard_input'] = budgetGuardInput(true);

    $rightsDenied = baseCapital();
    $rightsDenied['decision_rights_input']['action']['capability'] = 'capital.delete';

    $out = [
        'baseline' => CapitalPolicy::evaluate(baseCapital(), NOW),
        'budget_guard' => CapitalPolicy::evaluate($budgetBlocked, NOW),
        'decision_rights' => CapitalPolicy::evaluate($rightsDenied, NOW),
    ];
} elseif ($name === 'owner-gates') {
    $over = baseCapital();
    $over['budget']['limit_minor'] = 10_000_000;
    $over['budget']['spent_minor'] = 8_000_000;
    $over['budget']['committed_minor'] = 1_000_000;
    $over['proposal']['amount_minor'] = 2_000_000;

    $l4 = baseCapital();
    $l4['proposal']['required_authority_level'] = 'L4_OWNER';
    $l4['decision_rights_input'] = decisionRightsInput(
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
        'over_limit' => CapitalPolicy::evaluate($over, NOW),
        'l4' => CapitalPolicy::evaluate($l4, NOW),
        'irreversible' => CapitalPolicy::evaluate($irreversible, NOW),
        'reserve_floor' => CapitalPolicy::evaluate($reserve, NOW),
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
    $out = CapitalPolicy::evaluate($input, NOW);
} elseif ($name === 'no-payments') {
    $secret = 'bank-token-super-secret';
    $invalid = baseCapital();
    $invalid['bank_token'] = $secret;
    $evaluated = CapitalPolicy::evaluate($invalid, NOW);

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
    $first = CapitalPolicy::evaluate(baseCapital(), NOW);
    $second = CapitalPolicy::evaluate(baseCapital(), NOW);

    $unknown = baseCapital();
    $unknown['budget']['state'] = 'unknown';

    $mismatch = baseCapital();
    $mismatch['reserve']['currency'] = 'USD';

    $invalidGate = baseCapital();
    $invalidGate['decision_rights_input']['action']['token'] = 'client-secret';

    $out = [
        'first' => $first,
        'second' => $second,
        'same' => $first === $second,
        'unknown' => CapitalPolicy::evaluate($unknown, NOW),
        'mismatch' => CapitalPolicy::evaluate($mismatch, NOW),
        'invalid_gate' => CapitalPolicy::evaluate($invalidGate, NOW),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
