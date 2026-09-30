<?php
declare(strict_types=1);

foreach ([
    'InfrastructureProvider', 'InfrastructureResource', 'InfrastructureObservation',
    'InfrastructureImpact', 'InfrastructureCenterUi', 'Approvals', 'OwnerSession',
    'GitHub', 'ApprovalEndpoint', 'BudgetGuard', 'VentureIdentity',
    'VentureAccessSource', 'DecisionRights', 'DecisionRuntime', 'VerifiedAccessContext',
    'IdentityCenter', 'CapitalPolicy', 'CapabilityPolicy', 'RunnerGateway',
    'VentureAccessRuntime', 'InfrastructureIntent', 'InfrastructureActionProjection',
] as $file) {
    require __DIR__ . '/../src/' . $file . '.php';
}

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessRuntime;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Infrastructure\InfrastructureActionProjection;
use ControlBot\Infrastructure\InfrastructureCenterUi;
use ControlBot\Infrastructure\InfrastructureImpact;
use ControlBot\Infrastructure\InfrastructureIntent;
use ControlBot\Infrastructure\InfrastructureProvider;
use ControlBot\Runner\RunnerGateway;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const NOW191 = 2050;

final class E2eAuthoritySource191 implements VentureAccessSource
{
    public function __construct(private array $row) {}
    public function resolve(string $identityId, string $scope, string $capability, int $now): array
    {
        return $this->row;
    }
}

function rejected191(callable $fn): bool
{
    try { $fn(); return false; } catch (InvalidArgumentException) { return true; }
}

function inventory191(): array
{
    return InfrastructureProvider::normalizeInventory([[
        'version'=>1,'provider_id'=>'provider-primary','kind'=>'hosting','vendor'=>'vendor-one',
        'adapter_ref'=>'controlbot:adapter/provider-primary',
        'capabilities'=>[
            ['capability'=>'inventory.read','scopes'=>['provider','project']],
            ['capability'=>'health.read','scopes'=>['environment','resource']],
        ],
        'source_ref'=>'controlbot:provider/provider-primary','observed_at'=>2000,
    ]],[[
        'version'=>1,'account_id'=>'account-primary','provider_id'=>'provider-primary',
        'alias'=>'account-primary','source_ref'=>'controlbot:account/account-primary','observed_at'=>2000,
    ]]);
}

function resource191(string $id, string $kind, array $overrides = []): array
{
    return array_replace([
        'version'=>1,'resource_id'=>$id,'kind'=>$kind,
        'provider_id'=>'provider-primary','account_id'=>'account-primary',
        'project_ref'=>'controlbot:project/project-controlbot',
        'venture_ref'=>'controlbot:venture/venture-platform',
        'environment_ref'=>'controlbot:environment/controlbot-prod',
        'service_ref'=>null,'parent_ref'=>null,'release_evidence'=>null,
        'cost_ref'=>'controlbot:cost/project-controlbot','backup_refs'=>[],
        'source_ref'=>'controlbot:resource/'.$id,'observed_at'=>2000,
    ], $overrides);
}

function inventoryOwns191(array $inventory, array $resources): bool
{
    $providers=array_column($inventory['providers'],'provider_id');
    $accounts=[];
    foreach ($inventory['accounts'] as $account) {
        $accounts[$account['account_id']]=$account['provider_id'];
    }
    foreach ($resources as $resource) {
        if (!in_array($resource['provider_id'],$providers,true)
            || ($accounts[$resource['account_id']] ?? null) !== $resource['provider_id']) {
            return false;
        }
    }
    return true;
}

function observation191(string $freshness = 'fresh'): array
{
    $unknown=['state'=>'unknown','source_ref'=>null,'observed_at'=>null];
    return [
        'version'=>1,'resource_id'=>'service-web','state'=>'online',
        'source_ref'=>'controlbot:observation/service-web','observed_at'=>2000,
        'freshness'=>$freshness,'backup_freshness'=>$unknown,
        'restore_verification'=>$unknown,
        'cost_attribution'=>$unknown+['cost_ref'=>null],
        'release_drift'=>$unknown+['expected_sha'=>null,'observed_sha'=>null],
        'incident_refs'=>[],
    ];
}

function intent191(array $overrides = []): array
{
    return array_replace([
        'version'=>1,'intent_id'=>'intent-191','origin_mode'=>'directed',
        'group_id'=>'pl0n3r','venture_id'=>'platform','project_id'=>'controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','intent_type'=>'read',
        'priority'=>'high','scope'=>'project:controlbot','blast_radius'=>'low',
        'capability'=>'hostinger.read','depends_on'=>['pl0n3r/ControlBot#191'],
        'claims'=>['infra:controlbot-main'],'policy_ref'=>'controlbot:policy/infrastructure-v1',
        'evidence_refs'=>['pl0n3r/ControlBot#191'],'idempotency_key'=>'infra-e2e-191',
        'authority_level'=>'l2_venture_admin','budget_ref'=>null,'approval_ref'=>null,
        'instruction_ref'=>'controlbot:infrastructure/e2e-191','cost_applicable'=>false,
        'cost_ref'=>null,'evidence'=>[
            'plan_ref'=>null,'impact_ref'=>null,'rollback_ref'=>null,
            'safe_point_ref'=>null,'verify_ref'=>null,'irreversible'=>false,
        ],
    ], $overrides);
}

function authority191(string $decision = 'allow'): object
{
    $scope='project:controlbot'; $action='hostinger.read';
    $grantCapability=$decision==='deny' ? 'venture.read' : $action;
    $level=$decision==='owner_decision_required' ? 'L1_OPERATOR' : 'L2_VENTURE_ADMIN';
    $source=new E2eAuthoritySource191([
        'identity'=>[
            'version'=>1,'identity_id'=>'identity-admin','kind'=>'human',
            'display_name'=>'Infra Admin','state'=>'active',
            'source_ref'=>'controlbot:identity/identity-admin','observed_at'=>1900,
        ],
        'scope'=>$scope,'active_policy_refs'=>['controlbot:policy/business-os-v1'],
        'grant'=>[
            'version'=>1,'grant_id'=>'grant-e2e-191','identity_id'=>'identity-admin',
            'role'=>$level==='L1_OPERATOR'?'operator':'venture_admin',
            'capability'=>$grantCapability,'scope'=>$scope,'authority_level'=>$level,
            'policy_ref'=>'controlbot:policy/business-os-v1','budget_limit'=>null,
            'granted_at'=>1800,'expires_at'=>2600,
        ],
    ]);
    $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $sessions=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'infra-e2e-'); $audit=new AppendOnlyAuditLog($path);
    try {
        $runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/controlbot'],$source);
        $context=VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/controlbot',
            ['identity_id'=>'identity-admin','scope'=>$scope,'capability'=>$grantCapability],NOW191,
        );
        return VentureAccessRuntime::projectInfrastructureAuthority(
            $context,$action,'L2_VENTURE_ADMIN',NOW191,
        );
    } finally { @unlink($path); }
}

function runnerHealth191(int $active): array
{
    $runnerId='33333333-3333-7333-8333-333333333333';
    return RunnerGateway::health([
        'version'=>1,'runner_id'=>$runnerId,'protocol_version'=>1,'runtime'=>'factoryrunner',
        'runtime_version'=>'1.0.0','platform'=>'linux','placement'=>'shared',
        'capabilities'=>['hostinger.read'],'max_parallel'=>2,
    ],[
        'version'=>1,'runner_id'=>$runnerId,'sequence'=>1,'observed_at'=>NOW191,
        'status'=>'ready','capacity'=>['max'=>2,'active'=>$active],'active_sessions'=>[],
    ],NOW191,60);
}

function route191(string $effectiveState, array $health, string $intentId): array
{
    if ($effectiveState !== 'online') {
        return ['authority_used'=>false,'plan'=>null,'runner_request'=>null,'order'=>null];
    }
    $plan=InfrastructureIntent::plan(
        intent191(['intent_id'=>$intentId]),authority191(),null,NOW191,
    );
    $order=null;
    if ($plan['status']==='planned'
        && is_array($plan['runner_request'])
        && ($health['eligible'] ?? false) === true) {
        $order=InfrastructureIntent::toRunnerOrder($plan['runner_request'],[
            'order_id'=>'11111111-1111-7111-8111-111111111111',
            'attempt_id'=>'22222222-2222-7222-8222-222222222222',
            'generation'=>1,'runner_id'=>$health['runner_id'],'attempt'=>1,
            'expires_at'=>NOW191+300,
        ],NOW191);
    }
    return [
        'authority_used'=>true,'plan'=>$plan,
        'runner_request'=>$plan['runner_request'],'order'=>$order,
    ];
}

function event191(array $order, int $sequence, string $state, string $code): array
{
    return RunnerGateway::event([
        'version'=>1,'event_id'=>sprintf('44444444-4444-7444-8444-%012d',$sequence),
        'order_id'=>$order['order_id'],'attempt_id'=>$order['attempt_id'],
        'runner_id'=>$order['runner_id'],'generation'=>$order['generation'],
        'sequence'=>$sequence,'state'=>$state,'occurred_at'=>NOW191+$sequence,
        'evidence'=>[
            'code'=>$code,'summary'=>'Deterministic E2E evidence',
            'ref'=>'controlbot:evidence/infra-e2e-'.$sequence,
        ],
    ]);
}

$inventory=inventory191();
$resources=[
    resource191('env-prod','environment',[
        'environment_ref'=>null,'source_ref'=>'controlbot:environment/controlbot-prod',
    ]),
    resource191('service-web','service',['parent_ref'=>'controlbot:resource/env-prod']),
];
$bindings=[[
    'capability_ref'=>'controlbot:capability/commerce',
    'project_ref'=>'controlbot:project/project-controlbot',
    'venture_ref'=>'controlbot:venture/venture-platform',
    'source_ref'=>'controlbot:binding/commerce','observed_at'=>2000,
]];
$freshUi=InfrastructureCenterUi::project($resources,[observation191()],$bindings);
$freshEffective=$freshUi['resources'][1]['state']['effective_state'];
$impact=InfrastructureImpact::impactForResource($resources,$bindings,'service-web');
$health=runnerHealth191(0);
$route=route191($freshEffective,$health,'intent-191');
$plan=$route['plan']; $order=$route['order'];
$action=InfrastructureActionProjection::project(
    intent191(['intent_id'=>'intent-action-191']),authority191(),null,NOW191,
);

$accepted=event191($order,1,'accepted','accepted');
$started=event191($order,2,'started','started');
$completed=event191($order,3,'completed','verified');
RunnerGateway::assertEventOwnedByOrder($completed,$order);
RunnerGateway::assertEventTransition($accepted,$started,$order);
RunnerGateway::assertEventTransition($started,$completed,$order);

$owner=InfrastructureIntent::plan(
    intent191(['intent_id'=>'intent-owner-191','blast_radius'=>'high']),
    authority191(),null,NOW191,
);
$authorityDenied=InfrastructureIntent::plan(
    intent191(['intent_id'=>'intent-denied-191']),authority191('deny'),null,NOW191,
);
$budgetDenied=InfrastructureIntent::plan(
    intent191([
        'intent_id'=>'intent-budget-191','cost_applicable'=>true,
        'budget_ref'=>'controlbot:budget/infra-191','cost_ref'=>'controlbot:cost/infra-191',
    ]),
    authority191(),null,NOW191,
);
$nonFresh=[];
foreach (['stale','unknown'] as $freshness) {
    $ui=InfrastructureCenterUi::project($resources,[observation191($freshness)],$bindings);
    $effective=$ui['resources'][1]['state']['effective_state'];
    $nonFresh[$freshness]=['effective'=>$effective,'route'=>route191($effective,$health,'intent-'.$freshness.'-191')];
}
$ineligibleRoute=route191($freshEffective,runnerHealth191(2),'intent-runner-busy-191');

$out=[
    'inventory'=>$inventory,'inventory_owns_resources'=>inventoryOwns191($inventory,$resources),
    'fresh_effective'=>$freshEffective,'impact'=>$impact,'plan'=>$plan,'action'=>$action,
    'runner_health'=>$health,'order'=>$order,'completed'=>$completed,'owner'=>$owner,
    'authority_denied'=>$authorityDenied,'budget_denied'=>$budgetDenied,
    'non_fresh'=>$nonFresh,'ineligible_order'=>$ineligibleRoute['order'],
    'secret_instruction_rejected'=>rejected191(fn()=>InfrastructureIntent::plan(
        intent191(['intent_id'=>'intent-secret-191','instruction_ref'=>'controlbot:token:supersecret']),
        authority191(),null,NOW191,
    )),
    'secret_evidence_rejected'=>rejected191(fn()=>RunnerGateway::event([
        'version'=>1,'event_id'=>'55555555-5555-7555-8555-555555555555',
        'order_id'=>$order['order_id'],'attempt_id'=>$order['attempt_id'],
        'runner_id'=>$order['runner_id'],'generation'=>$order['generation'],
        'sequence'=>4,'state'=>'completed','occurred_at'=>NOW191+4,
        'evidence'=>['code'=>'verified','summary'=>'bad','ref'=>'controlbot:secret/api-key'],
    ])),
];

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
