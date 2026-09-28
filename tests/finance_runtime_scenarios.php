<?php
declare(strict_types=1);

require __DIR__ . '/../src/BudgetGuard.php';
require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/CapitalPolicy.php';
require __DIR__ . '/../src/VentureFinancialSnapshot.php';
require __DIR__ . '/../src/FinancialPlanning.php';
require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/FinanceRuntime.php';

use ControlBot\Approvals\HumanGate;
use ControlBot\Business\FinanceRuntime;

const NOW = 2000;
const POLICY = 'controlbot:policy/business-os-v1';

function identity(): array
{
    return [
        'version' => 1,
        'identity_id' => 'identity-finance',
        'kind' => 'human',
        'display_name' => 'Finance Operator',
        'state' => 'active',
        'source_ref' => 'controlbot:identity/identity-finance',
        'observed_at' => 1900,
    ];
}

function grant(
    string $scope = 'venture:condor',
    string $capability = 'finance.read',
    string $authority = 'L1_OPERATOR',
    ?float $budget = null,
): array {
    return [
        'version' => 1,
        'grant_id' => 'grant-finance',
        'identity_id' => 'identity-finance',
        'role' => $authority === 'L1_OPERATOR' ? 'finance' : 'venture_admin',
        'capability' => $capability,
        'scope' => $scope,
        'authority_level' => $authority,
        'policy_ref' => POLICY,
        'budget_limit' => $budget,
        'granted_at' => 1800,
        'expires_at' => 3000,
    ];
}

function readRequest(array $overrides = []): array
{
    return array_replace([
        'scope' => 'venture:condor',
        'context' => [
            'identity' => identity(),
            'scope' => 'venture:condor',
            'active_policy_refs' => [POLICY],
        ],
        'grant' => grant(),
        'snapshot' => snapshot(),
        'planning' => planning(),
    ], $overrides);
}

function snapshot(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'revenue' => 12_000_000,
        'refunds' => 1_000_000,
        'direct_costs' => 3_000_000,
        'operating_costs' => 2_000_000,
        'infrastructure_costs' => 1_000_000,
        'ai_costs' => 500_000,
        'cash_in' => 11_500_000,
        'cash_out' => 6_500_000,
        'customers' => 120,
        'transactions' => 180,
        'revenue_streams' => [[
            'stream_id' => 'subscription',
            'revenue' => 12_000_000,
            'refunds' => 1_000_000,
            'customers' => 120,
            'transactions' => 180,
        ]],
        'source_ref' => 'controlbot:finance/snapshot-condor',
        'observed_at' => '2026-09-28T15:00:00Z',
        'freshness' => 'fresh',
        'confidence' => 'verified',
    ], $overrides);
}

function planningSeries(string $kind, int $amount, array $overrides = []): array
{
    return array_replace([
        'kind' => $kind,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'amount_minor' => $amount,
        'source_ref' => 'controlbot:finance/' . $kind . '-condor',
        'observed_at' => 2000,
        'as_of' => 1900,
        'freshness' => 'fresh',
        'confidence' => $kind === 'actual' ? 'verified' : 'estimated',
    ], $overrides);
}

function planning(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'venture_id' => 'condor',
        'period' => '2026-09',
        'currency' => 'COP',
        'actual' => planningSeries('actual', 11_000_000),
        'budget' => planningSeries('budget', 10_000_000),
        'target' => planningSeries('target', 13_000_000),
        'forecast' => planningSeries('forecast', 12_500_000),
        'attributions' => [],
    ], $overrides);
}

function budgetGuardInput(): array
{
    return [
        'account' => [
            'provider' => 'github_actions',
            'account_scope' => 'pl0n3r',
            'resource' => 'private_minutes',
            'included' => 100,
            'used' => 10,
            'billable_used' => 0,
            'reset_at' => '2026-10-01',
            'budget_limit' => 0,
            'budget_mode' => 'alert_only',
            'capacity' => 'available',
            'observed_at' => 1900,
            'source' => 'github_api',
        ],
        'projects' => [],
        'work' => [
            'critical' => false,
            'requires_private_runner' => false,
            'pr_open' => false,
        ],
    ];
}

function capitalInput(bool $ownerRequired = true): array
{
    return [
        'version' => 1,
        'scope' => 'venture:condor',
        'currency' => 'COP',
        'budget' => [
            'scope' => 'venture:condor',
            'currency' => 'COP',
            'state' => 'known',
            'limit_minor' => $ownerRequired ? 10_000_000 : 20_000_000,
            'spent_minor' => 8_000_000,
            'committed_minor' => 1_000_000,
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
            'proposal_id' => 'proposal-finance',
            'scope' => 'venture:condor',
            'currency' => 'COP',
            'amount_minor' => 5_000_000,
            'required_authority_level' => 'L2_VENTURE_ADMIN',
            'irreversible' => false,
            'shared_costs' => [],
        ],
        'budget_guard_input' => budgetGuardInput(),
        'decision_rights_input' => [
            'context' => [
                'identity' => identity(),
                'scope' => 'venture:condor',
                'active_policy_refs' => [POLICY],
            ],
            'grant' => grant('venture:condor', 'capital.propose', 'L2_VENTURE_ADMIN', 20_000_000.0),
            'action' => [
                'capability' => 'capital.propose',
                'required_authority_level' => 'L2_VENTURE_ADMIN',
                'budget_amount' => 5_000_000.0,
            ],
        ],
    ];
}

function workRequest(): array
{
    return [
        'work_id' => 'finance-condor-cost-review',
        'origin_mode' => 'automatic',
        'group_id' => 'group-pl0n3r',
        'venture_id' => 'condor',
        'repository_ref' => 'pl0n3r/Condor',
        'work_type' => 'finance_analysis',
        'requested_capabilities' => ['finance.analyze'],
        'required_roles' => ['datos-analitica'],
        'authority_level' => 'L1_OPERATOR',
        'priority_class' => 'medium',
        'depends_on' => [],
        'claims' => ['finance:condor:2026-09'],
        'policy_ref' => POLICY,
        'budget_ref' => 'controlbot:finance/budget-condor',
        'approval_ref' => null,
        'idempotency_key' => 'finance-condor-2026-09-cost-review',
    ];
}

$name = $argv[1] ?? '';

if ($name === 'scope') {
    $allowed = FinanceRuntime::read(readRequest(), NOW);
    $cross = readRequest([
        'scope' => 'venture:grindflow',
        'context' => [
            'identity' => identity(),
            'scope' => 'venture:grindflow',
            'active_policy_refs' => [POLICY],
        ],
    ]);
    $out = [
        'allowed' => $allowed,
        'cross' => FinanceRuntime::read($cross, NOW),
    ];
} elseif ($name === 'owner') {
    $result = FinanceRuntime::routeAction([
        'scope' => 'venture:condor',
        'capital' => capitalInput(true),
    ], NOW);
    $gate = HumanGate::fromIssueBody($result['owner_decision_gate']);
    $out = [
        'result' => $result,
        'gate' => [
            'category' => $gate->category,
            'recommendation' => $gate->recommendation,
            'safe_default' => $gate->safeDefault,
        ],
    ];
} elseif ($name === 'factory') {
    $out = FinanceRuntime::factoryHandoff([
        'read' => readRequest(),
        'work' => workRequest(),
    ], NOW);
} elseif ($name === 'authorities') {
    $allowed = FinanceRuntime::routeAction([
        'scope' => 'venture:condor',
        'capital' => capitalInput(false),
    ], NOW);
    $deniedCapital = capitalInput(false);
    $deniedCapital['decision_rights_input']['action']['capability'] = 'capital.delete';
    $out = [
        'allowed' => $allowed,
        'denied' => FinanceRuntime::routeAction([
            'scope' => 'venture:condor',
            'capital' => $deniedCapital,
        ], NOW),
    ];
} elseif ($name === 'freshness') {
    $stale = readRequest([
        'snapshot' => snapshot([
            'freshness' => 'stale',
            'confidence' => 'estimated',
        ]),
    ]);
    $unknownPlanning = planning();
    $unknownPlanning['forecast']['freshness'] = 'unknown';
    $unknownPlanning['forecast']['confidence'] = 'unknown';
    $unknown = readRequest(['planning' => $unknownPlanning]);
    $out = [
        'stale' => FinanceRuntime::read($stale, NOW),
        'unknown' => FinanceRuntime::read($unknown, NOW),
        'handoff' => FinanceRuntime::factoryHandoff([
            'read' => $stale,
            'work' => workRequest(),
        ], NOW),
    ];
} elseif ($name === 'e2e') {
    $read = FinanceRuntime::read(readRequest(), NOW);
    $action = FinanceRuntime::routeAction([
        'scope' => 'venture:condor',
        'capital' => capitalInput(true),
    ], NOW);
    $handoff = FinanceRuntime::factoryHandoff([
        'read' => readRequest(),
        'work' => workRequest(),
    ], NOW);
    $out = [
        'read' => $read,
        'action' => $action,
        'handoff' => $handoff,
        'same_read' => $read === FinanceRuntime::read(readRequest(), NOW),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
