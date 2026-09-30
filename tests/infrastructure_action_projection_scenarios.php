<?php
declare(strict_types=1);

foreach ([
    'Approvals','OwnerSession','GitHub','ApprovalEndpoint','BudgetGuard',
    'VentureIdentity','VentureAccessSource','DecisionRights','DecisionRuntime',
    'VerifiedAccessContext','IdentityCenter','CapitalPolicy','CapabilityPolicy',
    'RunnerGateway','VentureAccessRuntime','InfrastructureIntent',
    'InfrastructureActionProjection',
] as $file) {
    require __DIR__ . '/../src/' . $file . '.php';
}

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessRuntime;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Infrastructure\InfrastructureActionProjection;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const NOW = 2050;

final class ProjectionAuthoritySource implements VentureAccessSource
{
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array
    {
        return $this->row;
    }
}

function rejected205(callable $fn): bool
{
    try { $fn(); return false; }
    catch (InvalidArgumentException) { return true; }
}

function intent205(array $replace = []): array
{
    return array_replace([
        'version'=>1,'intent_id'=>'intent-205','origin_mode'=>'directed','group_id'=>'pl0n3r',
        'venture_id'=>'platform','project_id'=>'controlbot','repository_ref'=>'pl0n3r/ControlBot',
        'intent_type'=>'read','priority'=>'high','scope'=>'project:controlbot','blast_radius'=>'low',
        'capability'=>'hostinger.read','depends_on'=>['pl0n3r/ControlBot#190'],
        'claims'=>['infra:controlbot-main'],'policy_ref'=>'controlbot:policy/infrastructure-v1',
        'evidence_refs'=>['pl0n3r/ControlBot#205'],'idempotency_key'=>'infra-action-205',
        'authority_level'=>'l2_venture_admin','budget_ref'=>null,'approval_ref'=>null,
        'instruction_ref'=>'controlbot:infrastructure/intent-205','cost_applicable'=>false,
        'cost_ref'=>null,'evidence'=>[
            'plan_ref'=>null,'impact_ref'=>null,'rollback_ref'=>null,
            'safe_point_ref'=>null,'verify_ref'=>null,'irreversible'=>false,
        ],
    ], $replace);
}

function verified205(string $capability,string $level): VerifiedAccessContext
{
    $scope='project:controlbot';
    $policy='controlbot:policy/business-os-v1';
    $source=new ProjectionAuthoritySource([
        'identity'=>[
            'version'=>1,'identity_id'=>'identity-admin','kind'=>'human',
            'display_name'=>'INFRA ADMIN','state'=>'active',
            'source_ref'=>'controlbot:identity/identity-admin','observed_at'=>1900,
        ],
        'scope'=>$scope,'active_policy_refs'=>[$policy],
        'grant'=>[
            'version'=>1,'grant_id'=>'grant-projection','identity_id'=>'identity-admin',
            'role'=>$level==='L1_OPERATOR'?'operator':'venture_admin',
            'capability'=>$capability,'scope'=>$scope,'authority_level'=>$level,
            'policy_ref'=>$policy,'budget_limit'=>null,'granted_at'=>1800,'expires_at'=>2600,
        ],
    ]);
    $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $sessions=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'infra-action-'); $audit=new AppendOnlyAuditLog($path);
    try {
        $runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/controlbot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/controlbot',
            ['identity_id'=>'identity-admin','scope'=>$scope,'capability'=>$capability],NOW,
        );
    } finally { @unlink($path); }
}

function authority205(string $decision='allow',string $actionCapability='hostinger.read'): object
{
    $grantCapability=$decision==='deny'?'venture.read':$actionCapability;
    $level=$decision==='owner_decision_required'?'L1_OPERATOR':'L2_VENTURE_ADMIN';
    return VentureAccessRuntime::projectInfrastructureAuthority(
        verified205($grantCapability,$level),$actionCapability,'L2_VENTURE_ADMIN',NOW,
    );
}

$planned=InfrastructureActionProjection::project(intent205(),authority205(),null,NOW);
$owner=InfrastructureActionProjection::project(
    intent205(['intent_id'=>'intent-owner']),authority205('owner_decision_required'),null,NOW,
);
$denied=InfrastructureActionProjection::project(
    intent205(['intent_id'=>'intent-denied']),authority205('deny'),null,NOW,
);
$fakePlan=['version'=>1,'status'=>'planned','execution'=>false,'authority'=>['decision'=>'allow']];
$fabricated=[
    'decision'=>'allow','reason_code'=>'authorized','scope'=>'project:controlbot',
    'evidence_ref'=>'controlbot:venture-access-authority/fabricated','policy_restrictions'=>[],
];
$cross=authority205();

$out=[
    'planned'=>$planned,'owner'=>$owner,'denied'=>$denied,
    'fake_plan_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project($fakePlan,authority205(),null,NOW)
    ),
    'caller_status_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project(
            intent205(),authority205(),null,NOW,['status'=>'planned']
        )
    ),
    'fabricated_array_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project(intent205(),$fabricated,null,NOW)
    ),
    'fabricated_object_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project(intent205(),(object)$fabricated,null,NOW)
    ),
    'cross_capability_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project(
            intent205(['capability'=>'config.write']),$cross,null,NOW
        )
    ),
    'secret_rejected'=>rejected205(
        fn()=>InfrastructureActionProjection::project(
            intent205([
                'intent_id'=>'intent-secret',
                'instruction_ref'=>'controlbot:token:supersecret',
            ]),
            authority205(),null,NOW,
        )
    ),
];

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
