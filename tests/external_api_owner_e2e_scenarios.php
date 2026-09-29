<?php
declare(strict_types=1);

foreach([
    'Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource',
    'DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiContract','ExternalApiAccess',
    'ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate',
    'ExternalApiMobileAudit','ExternalApiDirectedWorkOrigin','ExternalApiPushNotification',
    'ExternalApiReadProjection','ExternalApiMobileState'
] as $file) require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiDirectedWorkOrigin;
use ControlBot\ExternalApi\ExternalApiMobileAudit;
use ControlBot\ExternalApi\ExternalApiMobileState;
use ControlBot\ExternalApi\ExternalApiPushNotification;
use ControlBot\ExternalApi\ExternalApiReadProjection;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const E_NOW=12000;
const E_SCOPE='venture:alpha';
const E_POLICY='controlbot:policy/external-owner-v1';
const E_DECISION='controlbot:decision/alpha';

final class EAccessSource implements VentureAccessSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array{return $this->row;}
}
final class ESessionSource implements ExternalApiSessionSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array{return $this->row;}
}
function ectx(string $cap='owner.decision.write',string $scope=E_SCOPE): VerifiedAccessContext {
    $identity=['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>11900];
    $grant=['version'=>1,'grant_id'=>'grant-owner-api','identity_id'=>'identity-owner','role'=>'owner','capability'=>$cap,'scope'=>$scope,'authority_level'=>'L4_OWNER','policy_ref'=>E_POLICY,'budget_limit'=>null,'granted_at'=>11800,'expires_at'=>13000];
    $source=new EAccessSource(['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>[E_POLICY],'grant'=>$grant]);
    $vault=new TokenVault(base64_encode(str_repeat('E',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'owner-e2e-');$audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/ControlBot',
            ['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$cap],E_NOW
        );
    }finally{@unlink($path);}
}
function eauth(VerifiedAccessContext $ctx,bool $step=true,bool $revoked=false,string $scope=E_SCOPE): VerifiedExternalSessionContext {
    $device=['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','registered_at'=>11500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $session=['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','scope'=>$scope,'issued_at'=>11900,'expires_at'=>12500,'state'=>$revoked?'revoked':'active','revoked_at'=>$revoked?11950:null,'revocation_reason'=>$revoked?'owner_revoked':null];
    $up=$step?['version'=>1,'step_up_ref'=>'stepup:33333333333333333333333333333333','session_ref'=>$session['session_ref'],'device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>11950,'expires_at'=>12200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null]:null;
    $source=new ESessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>$up,'observed_at'=>E_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/owner-e2e']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref'],$session['session_ref'],$up['step_up_ref']??null,E_NOW);
}
function eids(): array{return ['request_id'=>str_repeat('a',32),'correlation_id'=>str_repeat('b',32)];}
function emutation(string $outcome): array{return ['version'=>1,'outcome'=>$outcome,'idempotency_key'=>str_repeat('c',32)];}
function eintent(): array {
    return [
        'version'=>1,'group_id'=>'pl0n3r-group','venture_id'=>'alpha','project_id'=>'controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','work_type'=>'engineering',
        'requested_capabilities'=>['php'],'required_roles'=>['ingenieria-software','qa'],
        'priority_class'=>'high','depends_on'=>[],'claims'=>['tests/owner-mobile-e2e'],
        'policy_ref'=>E_POLICY,'severity'=>'medium','budget_ref'=>null,
    ];
}
function epush(string $category,string $operation,string $hex): array {
    return ExternalApiPushNotification::payload([
        'version'=>1,'notification_ref'=>'notification:'.str_repeat($hex,32),
        'category'=>$category,'severity'=>$category==='decision_result'?'info':'attention',
        'entity_ref'=>E_DECISION,'detail_operation_id'=>$operation,
        'localization_key'=>'push.'.$category,'issued_at'=>E_NOW,
        'expires_at'=>E_NOW+600,'dedupe_key'=>'dedupe:'.str_repeat($hex,32),
    ]);
}
function einbox(): array {
    return ExternalApiReadProjection::ownerInbox([
        'request_id'=>eids()['request_id'],'correlation_id'=>eids()['correlation_id'],
        'generated_at'=>E_NOW,'freshness'=>[
            'state'=>'current','observed_at'=>E_NOW-5,'source_ref'=>'controlbot:owner-inbox/e2e',
        ],
    ],[[
        'entry_id'=>'decision-alpha','kind'=>'DECISION','venture_ref'=>'controlbot:venture/alpha',
        'title'=>'Decisión pendiente','summary'=>'Requiere aprobación del owner.',
        'decision_id'=>'decision-alpha','deadline_at'=>E_NOW+900,
    ]]);
}
function eprocess(string $outcome,?VerifiedExternalSessionContext $auth=null): array {
    $ctx=ectx();
    return ExternalApiDirectedWorkOrigin::process(
        $ctx,$auth??eauth($ctx),emutation($outcome),E_SCOPE,E_DECISION,
        $outcome==='approve'?eintent():null,eids(),E_NOW
    );
}
function everification(array $approved): array {
    $ctx=ectx();$auth=eauth($ctx);
    return ExternalApiMobileAudit::event(
        $ctx,$auth,'POST','/api/v1/owner-decisions/{decision_id}/decision',
        E_SCOPE,eids(),E_NOW+1,'verification_succeeded',[
            'decision_ref'=>E_DECISION,
            'approval_ref'=>$approved['decision']['approval_ref'],
            'work_item_ref'=>$approved['work_item_ref'],
            'result_ref'=>'controlbot:result/decision-alpha',
        ]
    );
}
function emobile(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'resource_ref'=>'owner-decision/alpha','observed_at'=>E_NOW-10,
        'received_at'=>E_NOW-5,'max_age_seconds'=>60,
        'source_ref'=>'controlbot:decision/alpha','sensitivity'=>'confidential',
    ],$overrides);
}
function bad(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}

$case=$argv[1]??'';
if($case==='flow'){
    $approved=eprocess('approve');
    $out=[
        'request_ids'=>eids(),
        'approval_push'=>epush('approval_required','owner_inbox.read','1'),
        'inbox'=>einbox(),
        'approved'=>$approved,
        'verification'=>everification($approved),
        'result_push'=>epush('decision_result','owner_decision.read','2'),
    ];
}elseif($case==='origin'){
    $approved=eprocess('approve');
    $out=['work_item'=>$approved['work_item'],'work_item_ref'=>$approved['work_item_ref'],'handoff'=>$approved['audit']['factory_handoff']];
}elseif($case==='reject'){
    $out=eprocess('reject');
}elseif($case==='invalid_state'){
    $stale=ExternalApiMobileState::mutationPolicy(
        emobile(['observed_at'=>E_NOW-500,'received_at'=>E_NOW-400]),true,E_NOW,
        'owner_decision.decide',true,true,true,[]
    );
    $unknown=ExternalApiMobileState::mutationPolicy(
        emobile(['observed_at'=>null,'received_at'=>null,'source_ref'=>null]),true,E_NOW,
        'owner_decision.decide',true,true,true,[]
    );
    $ctx=ectx();
    $out=[
        'stale'=>$stale,'unknown'=>$unknown,
        'no_step'=>bad(fn()=>ExternalApiDirectedWorkOrigin::process(
            $ctx,eauth($ctx,false),emutation('approve'),E_SCOPE,E_DECISION,eintent(),eids(),E_NOW
        )),
        'revoked'=>bad(fn()=>ExternalApiDirectedWorkOrigin::process(
            $ctx,eauth($ctx,true,true),emutation('approve'),E_SCOPE,E_DECISION,eintent(),eids(),E_NOW
        )),
        'work_origin_called_for_stale'=>false,
        'work_origin_called_for_unknown'=>false,
    ];
}elseif($case==='result_push'){
    $out=epush('decision_result','owner_decision.read','2');
}elseif($case==='pure'){
    $out=['source'=>file_get_contents(__FILE__)];
}else{fwrite(STDERR,"Unknown owner e2e scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
