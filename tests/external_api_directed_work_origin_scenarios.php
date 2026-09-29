<?php
declare(strict_types=1);
foreach(['Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource','DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiContract','ExternalApiAccess','ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate','ExternalApiMobileAudit','ExternalApiDirectedWorkOrigin'] as $file)
    require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiDirectedWorkOrigin;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use InvalidArgumentException;

const D_NOW=10000;
const D_SCOPE='venture:alpha';
const D_POLICY='controlbot:policy/external-owner-v1';

final class DAccessSource implements VentureAccessSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array{return $this->row;}
}
final class DSessionSource implements ExternalApiSessionSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array{return $this->row;}
}
function dctx(string $cap='owner.decision.write',string $scope=D_SCOPE,string $level='L4_OWNER'): VerifiedAccessContext {
    $identity=['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>9900];
    $grant=['version'=>1,'grant_id'=>'grant-owner-api','identity_id'=>'identity-owner','role'=>$level==='L4_OWNER'?'owner':'portfolio_admin','capability'=>$cap,'scope'=>$scope,'authority_level'=>$level,'policy_ref'=>D_POLICY,'budget_limit'=>null,'granted_at'=>9800,'expires_at'=>11000];
    $source=new DAccessSource(['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>[D_POLICY],'grant'=>$grant]);
    $vault=new TokenVault(base64_encode(str_repeat('D',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'directed-work-');$audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$cap],D_NOW);
    }finally{@unlink($path);}
}
function dauth(VerifiedAccessContext $ctx,bool $step=true,string $scope=D_SCOPE,bool $revoked=false): VerifiedExternalSessionContext {
    $device=['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','registered_at'=>9500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $session=['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','scope'=>$scope,'issued_at'=>9900,'expires_at'=>10500,'state'=>$revoked?'revoked':'active','revoked_at'=>$revoked?9990:null,'revocation_reason'=>$revoked?'owner_revoked':null];
    $up=$step?['version'=>1,'step_up_ref'=>'stepup:33333333333333333333333333333333','session_ref'=>$session['session_ref'],'device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>9950,'expires_at'=>10200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null]:null;
    $source=new DSessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>$up,'observed_at'=>D_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/directed-work']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref'],$session['session_ref'],$up['step_up_ref']??null,D_NOW);
}
function dmutation(string $outcome='approve',array $extra=[]): array {
    return array_replace(['version'=>1,'outcome'=>$outcome,'idempotency_key'=>str_repeat('a',32)],$extra);
}
function dintent(array $extra=[]): array {
    return array_replace([
        'version'=>1,'group_id'=>'pl0n3r-group','venture_id'=>'alpha','project_id'=>'controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','work_type'=>'engineering',
        'requested_capabilities'=>['php'],'required_roles'=>['ingenieria-software','qa'],
        'priority_class'=>'high','depends_on'=>[],'claims'=>['src/mobile-owner-handoff'],
        'policy_ref'=>D_POLICY,'severity'=>'medium','budget_ref'=>null,
    ],$extra);
}
function dids(string $request='a',string $correlation='b'): array{return ['request_id'=>str_repeat($request,32),'correlation_id'=>str_repeat($correlation,32)];}
function dprocess(VerifiedAccessContext $ctx,array $mutation,?array $intent,?VerifiedExternalSessionContext $auth=null,string $scope=D_SCOPE,?array $ids=null,int $now=D_NOW): array {
    return ExternalApiDirectedWorkOrigin::process($ctx,$auth??dauth($ctx),$mutation,$scope,'controlbot:decision/alpha',$intent,$ids??dids(),$now);
}
function bad(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}

$case=$argv[1]??'';
if($case==='authorized'){
    $ctx=dctx();
    $out=[
        'ok'=>dprocess($ctx,dmutation(),dintent()),
        'no_step'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(),dauth($ctx,false))),
        'wrong_scope'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(),dauth($ctx,true,'venture:beta'))),
        'revoked'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(),dauth($ctx,true,D_SCOPE,true))),
    ];
}elseif($case==='client'){
    $ctx=dctx();
    $out=[
        'authority'=>bad(fn()=>dprocess($ctx,dmutation('approve',['authority_level'=>'L4_OWNER']),dintent())),
        'policy'=>bad(fn()=>dprocess($ctx,dmutation('approve',['policy_ref'=>D_POLICY]),dintent())),
        'work'=>bad(fn()=>dprocess($ctx,dmutation('approve',['work_type'=>'engineering']),dintent())),
    ];
}elseif($case==='reject'){
    $ctx=dctx();
    $out=[
        'result'=>dprocess($ctx,dmutation('reject'),null),
        'with_work'=>bad(fn()=>dprocess($ctx,dmutation('reject'),dintent())),
    ];
}elseif($case==='approve'){
    $ctx=dctx();
    $out=dprocess($ctx,dmutation(),dintent());
}elseif($case==='retry'){
    $ctx=dctx();
    $out=['first'=>dprocess($ctx,dmutation(),dintent(),null,D_SCOPE,dids('a','b'),D_NOW),
        'second'=>dprocess($ctx,dmutation(),dintent(),null,D_SCOPE,dids('c','d'),D_NOW+1)];
}elseif($case==='policy'){
    $ctx=dctx();
    $out=[
        'unverified_policy'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(['policy_ref'=>'controlbot:policy/not-active']))),
        'invalid_repo'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(['repository_ref'=>'not-a-repo']))),
        'secret'=>bad(fn()=>dprocess($ctx,dmutation(),dintent(['claims'=>['token=super-secret-value']]))),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiDirectedWorkOrigin::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiDirectedWorkOrigin::class));
    sort($methods);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiDirectedWorkOrigin.php')];
}else{fwrite(STDERR,"Unknown directed work origin scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
