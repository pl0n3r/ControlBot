<?php
declare(strict_types=1);

foreach ([
    'BudgetGuard', 'VentureIdentity', 'DecisionRights', 'CapitalPolicy',
    'CapabilityPolicy', 'RunnerGateway', 'InfrastructureIntent',
] as $file) {
    require __DIR__ . '/../src/' . $file . '.php';
}

use ControlBot\Infrastructure\InfrastructureIntent;
use InvalidArgumentException;

const NOW = 2050;

function rejected(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

function noEvidence(): array
{
    return [
        'plan_ref' => null,
        'impact_ref' => null,
        'rollback_ref' => null,
        'safe_point_ref' => null,
        'verify_ref' => null,
        'irreversible' => false,
    ];
}

function mutationEvidence(array $overrides = []): array
{
    return array_replace([
        'plan_ref' => 'controlbot:plan/rollback-189',
        'impact_ref' => 'controlbot:impact/rollback-189',
        'rollback_ref' => 'controlbot:rollback/artifact-previous',
        'safe_point_ref' => 'controlbot:backup/rollback-189',
        'verify_ref' => 'controlbot:verify/post-write-189',
        'irreversible' => false,
    ], $overrides);
}

function intent(array $replace = []): array
{
    return array_replace([
        'version' => 1,
        'intent_id' => 'intent-189',
        'origin_mode' => 'directed',
        'group_id' => 'pl0n3r',
        'venture_id' => 'platform',
        'project_id' => 'controlbot',
        'repository_ref' => 'pl0n3r/ControlBot',
        'intent_type' => 'read',
        'priority' => 'high',
        'scope' => 'project:controlbot',
        'blast_radius' => 'low',
        'capability' => 'hostinger.read',
        'depends_on' => ['pl0n3r/ControlBot#188'],
        'claims' => ['infra:controlbot-main'],
        'policy_ref' => 'controlbot:policy/infrastructure-v1',
        'evidence_refs' => ['pl0n3r/ControlBot#189'],
        'idempotency_key' => 'infra-intent-189',
        'authority_level' => 'l2_venture_admin',
        'budget_ref' => null,
        'approval_ref' => null,
        'instruction_ref' => 'controlbot:infrastructure/intent-189',
        'cost_applicable' => false,
        'cost_ref' => null,
        'evidence' => noEvidence(),
    ], $replace);
}

function authority(string $decision = 'allow', string $scope = 'project:controlbot'): array
{
    return [
        'decision' => $decision,
        'reason_code' => $decision === 'allow'
            ? 'venture_access_allow'
            : 'venture_access_owner_required',
        'scope' => $scope,
        'evidence_ref' => 'controlbot:venture-access/issue-189',
        'policy_restrictions' => [],
    ];
}

function capital(bool $over = false): array
{
    $scope = 'venture:platform';
    $policy = 'controlbot:policy/business-os-v1';
    return [
        'version' => 1,
        'scope' => $scope,
        'currency' => 'COP',
        'budget' => [
            'scope' => $scope,
            'currency' => 'COP',
            'state' => 'known',
            'limit_minor' => 100,
            'spent_minor' => $over ? 90 : 10,
            'committed_minor' => 0,
            'source_ref' => 'controlbot:finance/budget-infra',
            'observed_at' => 1900,
        ],
        'reserve' => [
            'scope' => $scope,
            'currency' => 'COP',
            'state' => 'known',
            'cash_available_minor' => 1000,
            'minimum_reserve_minor' => 100,
            'runway_months' => 6,
            'source_ref' => 'controlbot:finance/reserve-infra',
            'observed_at' => 1900,
        ],
        'proposal' => [
            'proposal_id' => 'proposal-infra',
            'scope' => $scope,
            'currency' => 'COP',
            'amount_minor' => 20,
            'required_authority_level' => 'L2_VENTURE_ADMIN',
            'irreversible' => false,
            'shared_costs' => [],
        ],
        'budget_guard_input' => [
            'account' => [
                'provider' => 'github_actions',
                'account_scope' => 'pl0n3r',
                'resource' => 'private_minutes',
                'included' => 1000,
                'used' => 100,
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
                'requires_private_runner' => true,
                'pr_open' => false,
            ],
        ],
        'decision_rights_input' => [
            'context' => [
                'identity' => [
                    'version' => 1,
                    'identity_id' => 'identity-infra',
                    'kind' => 'human',
                    'display_name' => 'Infra Admin',
                    'state' => 'active',
                    'source_ref' => 'controlbot:identity/identity-infra',
                    'observed_at' => 1900,
                ],
                'scope' => $scope,
                'active_policy_refs' => [$policy],
            ],
            'grant' => [
                'version' => 1,
                'grant_id' => 'grant-infra',
                'identity_id' => 'identity-infra',
                'role' => 'venture_admin',
                'capability' => 'capital.propose',
                'scope' => $scope,
                'authority_level' => 'L2_VENTURE_ADMIN',
                'policy_ref' => $policy,
                'budget_limit' => 1000.0,
                'granted_at' => 1800,
                'expires_at' => 3000,
            ],
            'action' => [
                'capability' => 'capital.propose',
                'required_authority_level' => 'L2_VENTURE_ADMIN',
                'budget_amount' => 20.0,
            ],
        ],
    ];
}

$name = $argv[1] ?? '';

if ($name === 'safe') {
    $out = InfrastructureIntent::plan(intent(), authority(), null, NOW);
} elseif ($name === 'gates') {
    $costIntent = intent([
        'intent_id' => 'intent-budget',
        'intent_type' => 'capacity',
        'scope' => 'venture:platform',
        'capability' => 'config.write',
        'budget_ref' => 'controlbot:finance/budget-infra',
        'cost_applicable' => true,
        'cost_ref' => 'controlbot:finance/cost-capacity',
        'evidence' => mutationEvidence(),
    ]);
    $out = [
        'high' => InfrastructureIntent::plan(
            intent(['intent_id' => 'intent-high', 'blast_radius' => 'high']),
            authority(),
            null,
            NOW,
        ),
        'authority' => InfrastructureIntent::plan(
            intent(['intent_id' => 'intent-authority']),
            authority('owner_decision_required'),
            null,
            NOW,
        ),
        'over_budget' => InfrastructureIntent::plan(
            $costIntent,
            authority('allow', 'venture:platform'),
            capital(true),
            NOW,
        ),
        'unknown' => InfrastructureIntent::plan(
            intent(['intent_id' => 'intent-unknown', 'blast_radius' => 'unknown']),
            authority(),
            null,
            NOW,
        ),
        'scope_mismatch' => InfrastructureIntent::plan(
            intent(['intent_id' => 'intent-scope']),
            authority('allow', 'venture:other'),
            null,
            NOW,
        ),
        'deny_owner' => InfrastructureIntent::plan(
            intent(['intent_id' => 'intent-deny-owner', 'blast_radius' => 'high']),
            authority('allow', 'venture:other'),
            null,
            NOW,
        ),
    ];
} elseif ($name === 'runner') {
    $safe = InfrastructureIntent::plan(intent(), authority(), null, NOW);
    $order = InfrastructureIntent::toRunnerOrder(
        $safe['runner_request'],
        [
            'order_id' => '11111111-1111-7111-8111-111111111111',
            'attempt_id' => '22222222-2222-7222-8222-222222222222',
            'generation' => 1,
            'runner_id' => '33333333-3333-7333-8333-333333333333',
            'attempt' => 1,
            'expires_at' => 2300,
        ],
        NOW,
    );
    $secret = intent(['instruction_ref' => 'controlbot:token:supersecret']);
    $authSecret = authority();
    $authSecret['evidence_ref'] = 'controlbot:secret:value';
    $out = [
        'safe' => $safe,
        'order' => $order,
        'instruction_secret_rejected' => rejected(
            fn() => InfrastructureIntent::plan($secret, authority(), null, NOW)
        ),
        'authority_secret_rejected' => rejected(
            fn() => InfrastructureIntent::plan(intent(), $authSecret, null, NOW)
        ),
    ];
} elseif ($name === 'mutation') {
    $base = intent([
        'intent_id' => 'intent-rollback',
        'intent_type' => 'rollback',
        'capability' => 'deploy.rollback_artifact',
        'evidence' => mutationEvidence(),
    ]);
    $missingPlan = $base;
    $missingPlan['evidence']['plan_ref'] = null;
    $missingVerify = $base;
    $missingVerify['evidence']['verify_ref'] = null;
    $missingRecovery = $base;
    $missingRecovery['evidence']['rollback_ref'] = null;
    $missingRecovery['evidence']['safe_point_ref'] = null;
    $missingBackup = $base;
    $missingBackup['evidence']['safe_point_ref'] = null;
    $readMutation = intent(['evidence' => mutationEvidence()]);
    $weakCapability = $base;
    $weakCapability['intent_id'] = 'intent-weak-capability';
    $weakCapability['capability'] = 'hostinger.read';
    $readonlyWrite = intent([
        'intent_id' => 'intent-read-write',
        'capability' => 'config.write',
    ]);
    $costUnknown = intent([
        'intent_id' => 'intent-cost-unknown',
        'cost_applicable' => true,
        'budget_ref' => 'controlbot:finance/budget-infra',
        'cost_ref' => 'controlbot:finance/cost-read',
    ]);
    $out = [
        'valid' => InfrastructureIntent::plan($base, authority(), null, NOW),
        'missing_plan_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingPlan, authority(), null, NOW)
        ),
        'missing_verify_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingVerify, authority(), null, NOW)
        ),
        'missing_recovery_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingRecovery, authority(), null, NOW)
        ),
        'missing_backup' => InfrastructureIntent::plan($missingBackup, authority(), null, NOW),
        'read_mutation_rejected' => rejected(
            fn() => InfrastructureIntent::plan($readMutation, authority(), null, NOW)
        ),
        'weak_capability' => InfrastructureIntent::plan($weakCapability, authority(), null, NOW),
        'readonly_write' => InfrastructureIntent::plan($readonlyWrite, authority(), null, NOW),
        'cost_unknown' => InfrastructureIntent::plan($costUnknown, authority(), null, NOW),
    ];
} else {
    fwrite(STDERR, "Unknown infrastructure intent scenario\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
