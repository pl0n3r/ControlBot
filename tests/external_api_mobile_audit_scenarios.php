<?php
declare(strict_types=1);
foreach(['Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource','DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiContract','ExternalApiAccess','ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate','ExternalApiMobileAudit'] as $file)
    require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiMobileAudit;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const A_NOW=9000;
const A_SCOPE='venture:alpha';
const A_POLICY='controlbot:policy/external-owner-v1';

final class ASource implements VentureAccessSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array{return $this->row;}
}
final class ASessionSource implements ExternalApiSessionSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array{return $this->row;}
}
function actx(string $cap,string $level='L4_OWNER',string $scope=A_SCOPE): VerifiedAccessContext {
    $identity=['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>8900];
    $grant=['version'=>1,'grant_id'=>'grant-owner-api','identity_id'=>'identity-owner','role'=>$level==='L4_OWNER'?'owner':'portfolio_admin','capability'=>$cap,'scope'=>$scope,'authority_level'=>$level,'policy_ref'=>A_POLICY,'budget_limit'=>null,'granted_at'=>8800,'expires_at'=>10000];
    $source=new ASource(['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>[A_POLICY],'grant'=>$grant]);
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'mobile-audit-');$audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$cap],A_NOW);
    }finally{@unlink($path);}
}
function aauth(VerifiedAccessContext $ctx,bool $step=false,string $scope=A_SCOPE): VerifiedExternalSessionContext {
    $device=['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','registered_at'=>8500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $session=['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','scope'=>$scope,'issued_at'=>8900,'expires_at'=>9500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $up=$step?['version'=>1,'step_up_ref'=>'stepup:33333333333333333333333333333333','session_ref'=>$session['session_ref'],'device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>8950,'expires_at'=>9200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null]:null;
    $source=new ASessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>$up,'observed_at'=>A_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/mobile-audit']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref'],$session['session_ref'],$up['step_up_ref']??null,A_NOW);
}
function ids(): array{return ['request_id'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','correlation_id'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];}
function refs(array $o=[]): array{return array_replace(['decision_ref'=>null,'approval_ref'=>null,'work_item_ref'=>null,'result_ref'=>null],$o);}
function bad(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function auditEvent(VerifiedAccessContext $ctx,string $method,string $path,string $outcome,array $r=[],bool $step=false,string $scope=A_SCOPE): array {
    return ExternalApiMobileAudit::event($ctx,aauth($ctx,$step,$scope),$method,$path,$scope,ids(),A_NOW,$outcome,refs($r));
}

$case=$argv[1]??'';
if($case==='derived'){
    $out=auditEvent(actx('owner.cockpit.read'),'GET','/api/v1/cockpit','read_served');
}elseif($case==='trace'){
    $out=auditEvent(actx('owner.inbox.read'),'GET','/api/v1/owner-inbox','read_served');
}elseif($case==='authz'){
    $alpha=actx('owner.cockpit.read');
    $low=actx('owner.cockpit.read','L2_VENTURE_ADMIN');
    $out=[
        'horizontal'=>bad(fn()=>ExternalApiMobileAudit::event($alpha,aauth($alpha),'GET','/api/v1/cockpit','venture:beta',ids(),A_NOW,'read_served',refs())),
        'vertical'=>bad(fn()=>auditEvent($low,'GET','/api/v1/cockpit','read_served')),
    ];
}elseif($case==='refs'){
    $ctx=actx('owner.decision.write');
    $decision='controlbot:decision/abc';
    $out=[
        'accepted'=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','mutation_accepted',['decision_ref'=>$decision],true),
        'rejected'=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','mutation_rejected',['decision_ref'=>$decision],true),
        'handoff'=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','factory_handoff',['work_item_ref'=>'controlbot:work/item-abc'],true),
        'verified'=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','verification_succeeded',['result_ref'=>'controlbot:result/abc'],true),
        'missing_decision'=>bad(fn()=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','mutation_accepted',[],true)),
        'missing_work'=>bad(fn()=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','factory_handoff',[],true)),
        'missing_result'=>bad(fn()=>auditEvent($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision','verification_failed',[],true)),
    ];
}elseif($case==='sensitive'){
    $extra=refs();$extra['ip_address']='127.0.0.1';
    $ctx=actx('owner.cockpit.read');
    $out=[
        'event'=>auditEvent($ctx,'GET','/api/v1/cockpit','read_served'),
        'extra'=>bad(fn()=>ExternalApiMobileAudit::event($ctx,aauth($ctx),'GET','/api/v1/cockpit',A_SCOPE,ids(),A_NOW,'read_served',$extra)),
        'secret_ref'=>bad(fn()=>ExternalApiMobileAudit::event($ctx,aauth($ctx),'GET','/api/v1/cockpit',A_SCOPE,ids(),A_NOW,'read_served',refs(['result_ref'=>'controlbot:secret/value']))),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiMobileAudit::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiMobileAudit::class));
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiMobileAudit.php')];
}else{fwrite(STDERR,"Unknown mobile audit scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
