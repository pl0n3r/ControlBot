<?php
declare(strict_types=1);

foreach ([
    'Approvals', 'OwnerSession', 'GitHub', 'ApprovalEndpoint', 'BudgetGuard',
    'VentureIdentity', 'VentureAccessSource', 'DecisionRights', 'DecisionRuntime',
    'VerifiedAccessContext', 'IdentityCenter', 'CapitalPolicy', 'CapabilityPolicy',
    'RunnerGateway', 'VentureAccessRuntime', 'InfrastructureIntent',
] as $file) {
    require __DIR__ . '/../src/' . $file . '.php';
}

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessRuntime;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Infrastructure\InfrastructureIntent;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const NOW = 2050;

final class InfrastructureAuthoritySource implements VentureAccessSource
{
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array { return $this->row; }
}

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

function accessIdentity(string $id = 'identity-admin'): array
{
    return [
        'version'=>1, 'identity_id'=>$id, 'kind'=>'human', 'display_name'=>strtoupper($id),
        'state'=>'active', 'source_ref'=>'controlbot:identity/'.$id, 'observed_at'=>1900,
    ];
}

function accessGrant(string $scope, string $capability = 'hostinger.read', string $authorityLevel = 'L2_VENTURE_ADMIN'): array
{
    return [
        'version'=>1, 'grant_id'=>'grant-authority', 'identity_id'=>'identity-admin',
        'role'=>$authorityLevel === 'L1_OPERATOR' ? 'operator' : 'venture_admin',
        'capability'=>$capability, 'scope'=>$scope,
        'authority_level'=>$authorityLevel, 'policy_ref'=>'controlbot:policy/business-os-v1',
        'budget_limit'=>null, 'granted_at'=>1800, 'expires_at'=>2600,
    ];
}

function verifiedContext(string $scope,string $capability,string $authorityLevel): VerifiedAccessContext
{
    $source=new InfrastructureAuthoritySource([
        'identity'=>accessIdentity(),'scope'=>$scope,
        'active_policy_refs'=>['controlbot:policy/business-os-v1'],
        'grant'=>accessGrant($scope,$capability,$authorityLevel),
    ]);
    $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $sessions=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'infra-authority-'); $audit=new AppendOnlyAuditLog($path);
    try {
        $runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/controlbot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/controlbot',
            ['identity_id'=>'identity-admin','scope'=>$scope,'capability'=>$capability],NOW
        );
    } finally { @unlink($path); }
}

function authority(
    string $decision = 'allow',
    string $scope = 'project:controlbot',
    string $capability = 'hostinger.read',
    string $authorityLevel = 'L2_VENTURE_ADMIN',
): object {
    $grantCapability=$decision === 'deny'
        ? ($capability === 'hostinger.read' ? 'venture.read' : 'hostinger.read')
        : $capability;
    $grantLevel=$decision === 'owner_decision_required' ? 'L1_OPERATOR' : $authorityLevel;
    return VentureAccessRuntime::projectInfrastructureAuthority(
        verifiedContext($scope,$grantCapability,$grantLevel),
        $capability,$authorityLevel,NOW,
    );
}

function authorityFor(array $intent, string $decision = 'allow', ?string $scope = null): object
{
    return authority($decision, $scope ?? $intent['scope'], $intent['capability'], strtoupper($intent['authority_level']));
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
            intent([
                'intent_id' => 'intent-high',
                'blast_radius' => 'high',
                'approval_ref' => 'controlbot:approval/caller-supplied',
            ]),
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
            authorityFor($costIntent),
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
    $authSecret = [
        'decision' => 'allow',
        'reason_code' => 'venture_access_allow',
        'scope' => 'project:controlbot',
        'evidence_ref' => 'controlbot:secret:value',
        'policy_restrictions' => [],
    ];
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
} elseif ($name === 'provenance') {
    $fabricated = [
        'decision' => 'allow',
        'reason_code' => 'venture_access_allow',
        'scope' => 'project:controlbot',
        'evidence_ref' => 'controlbot:venture-access/fabricated',
        'policy_restrictions' => [],
    ];
    $copiedObject = (object) $fabricated;
    $allow = InfrastructureIntent::plan(
        intent(['intent_id' => 'intent-provenance-allow']),
        authority(),
        null,
        NOW,
    );
    $owner = InfrastructureIntent::plan(
        intent(['intent_id' => 'intent-provenance-owner']),
        authority('owner_decision_required'),
        null,
        NOW,
    );
    $deny = InfrastructureIntent::plan(
        intent(['intent_id' => 'intent-provenance-deny']),
        authority('deny'),
        null,
        NOW,
    );
    $scopeMismatch = InfrastructureIntent::plan(
        intent(['intent_id' => 'intent-provenance-scope']),
        authority('allow', 'venture:other'),
        null,
        NOW,
    );
    $projection = authority();
    $context = verifiedContext('project:controlbot','hostinger.read','L2_VENTURE_ADMIN');
    $contextCopy = (object)['decisionContext'=>$context->decisionContext(),'grant'=>$context->grant()];
    VentureAccessRuntime::projectInfrastructureAuthority($context, 'hostinger.read', 'L2_VENTURE_ADMIN', NOW);
    $singleUseIntent = intent(['intent_id' => 'intent-provenance-single-use']);
    $singleUseProjection = authority();
    $firstUse = InfrastructureIntent::plan($singleUseIntent, $singleUseProjection, null, NOW);
    $crossCapability = intent([
        'intent_id' => 'intent-provenance-cross-capability',
        'intent_type' => 'rollback',
        'capability' => 'deploy.rollback_artifact',
        'evidence' => mutationEvidence(),
    ]);
    $freshDenied = InfrastructureIntent::plan(
        intent(['intent_id' => 'intent-provenance-current-deny']),
        authority('deny'),
        null,
        NOW,
    );
    $out = [
        'fabricated_array_rejected' => rejected(
            fn() => InfrastructureIntent::plan(intent(), $fabricated, null, NOW)
        ),
        'copied_object_rejected' => rejected(
            fn() => InfrastructureIntent::plan(intent(), $copiedObject, null, NOW)
        ),
        'trusted_allow' => $allow,
        'trusted_owner' => $owner,
        'trusted_deny' => $deny,
        'scope_mismatch' => $scopeMismatch,
        'projection_json' => json_encode($projection, JSON_THROW_ON_ERROR),
        'context_copy_rejected' => rejected(fn() => VentureAccessRuntime::projectInfrastructureAuthority(
            $contextCopy, 'hostinger.read', 'L2_VENTURE_ADMIN', NOW
        )),
        'context_replay_rejected' => rejected(fn() => VentureAccessRuntime::projectInfrastructureAuthority(
            $context, 'hostinger.read', 'L2_VENTURE_ADMIN', NOW
        )),
        'cross_capability_rejected' => rejected(
            fn() => InfrastructureIntent::plan($crossCapability, authority(), null, NOW)
        ),
        'first_use_status' => $firstUse['status'],
        'projection_replay_rejected' => rejected(
            fn() => InfrastructureIntent::plan($singleUseIntent, $singleUseProjection, null, NOW)
        ),
        'current_deny_status' => $freshDenied['status'],
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
        'valid' => InfrastructureIntent::plan($base, authorityFor($base), null, NOW),
        'missing_plan_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingPlan, authorityFor($missingPlan), null, NOW)
        ),
        'missing_verify_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingVerify, authorityFor($missingVerify), null, NOW)
        ),
        'missing_recovery_rejected' => rejected(
            fn() => InfrastructureIntent::plan($missingRecovery, authorityFor($missingRecovery), null, NOW)
        ),
        'missing_backup' => InfrastructureIntent::plan($missingBackup, authorityFor($missingBackup), null, NOW),
        'read_mutation_rejected' => rejected(
            fn() => InfrastructureIntent::plan($readMutation, authorityFor($readMutation), null, NOW)
        ),
        'weak_capability' => InfrastructureIntent::plan($weakCapability, authorityFor($weakCapability), null, NOW),
        'readonly_write' => InfrastructureIntent::plan($readonlyWrite, authorityFor($readonlyWrite), null, NOW),
        'cost_unknown' => InfrastructureIntent::plan($costUnknown, authorityFor($costUnknown), null, NOW),
    ];
} else {
    fwrite(STDERR, "Unknown infrastructure intent scenario\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
