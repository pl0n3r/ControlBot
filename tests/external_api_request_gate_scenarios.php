<?php
declare(strict_types=1);

require __DIR__.'/../src/Approvals.php';
require __DIR__.'/../src/OwnerSession.php';
require __DIR__.'/../src/GitHub.php';
require __DIR__.'/../src/ApprovalEndpoint.php';
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/VentureAccessSource.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/DecisionRuntime.php';
require __DIR__.'/../src/VerifiedAccessContext.php';
require __DIR__.'/../src/ExternalApiContract.php';
require __DIR__.'/../src/ExternalApiAccess.php';
require __DIR__.'/../src/ExternalApiSession.php';
require __DIR__.'/../src/ExternalApiSessionSource.php';
require __DIR__.'/../src/VerifiedExternalSessionContext.php';
require __DIR__.'/../src/ExternalApiRequestGate.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiRequestGate;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const GATE_NOW=7000;
const GATE_SCOPE='venture:alpha';
const GATE_POLICY='controlbot:policy/external-owner-v1';

final class GateSource implements VentureAccessSource {
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array { return $this->row; }
}
final class GateSessionSource implements ExternalApiSessionSource {
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array { return $this->row; }
}
function gi(string $identity='identity-owner'): array { return ['version'=>1,'identity_id'=>$identity,'kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>6900]; }
function gg(string $cap,string $level='L4_OWNER',string $scope=GATE_SCOPE,string $identity='identity-owner'): array { return ['version'=>1,'grant_id'=>'grant-owner-api','identity_id'=>$identity,'role'=>$level==='L4_OWNER'?'owner':'portfolio_admin','capability'=>$cap,'scope'=>$scope,'authority_level'=>$level,'policy_ref'=>GATE_POLICY,'budget_limit'=>null,'granted_at'=>6800,'expires_at'=>8000]; }
function gsnap(string $cap,string $level='L4_OWNER',string $scope=GATE_SCOPE,string $identity='identity-owner'): array { return ['identity'=>gi($identity),'scope'=>$scope,'active_policy_refs'=>[GATE_POLICY],'grant'=>gg($cap,$level,$scope,$identity)]; }
function gctx(string $cap,string $level='L4_OWNER',string $scope=GATE_SCOPE,string $identity='identity-owner'): VerifiedAccessContext {
    $source=new GateSource(gsnap($cap,$level,$scope,$identity));
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'external-api-gate-'); $audit=new AppendOnlyAuditLog($path);
    try {
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/ControlBot',
            ['identity_id'=>$identity,'scope'=>$scope,'capability'=>$cap],GATE_NOW
        );
    } finally { @unlink($path); }
}
function gd(array $o=[]): array { return array_replace(['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','registered_at'=>6500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }
function gsession(array $o=[]): array { return array_replace(['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','scope'=>GATE_SCOPE,'issued_at'=>6900,'expires_at'=>7500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }
function gu(array $o=[]): array { return array_replace(['version'=>1,'step_up_ref'=>'stepup:33333333333333333333333333333333','session_ref'=>'session:22222222222222222222222222222222','device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>6950,'expires_at'=>7200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }
function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function badType(callable $fn): bool { try{$fn();return false;}catch(TypeError){return true;} }
function gauth(VerifiedAccessContext $ctx,array $device,array $session,?array $up): VerifiedExternalSessionContext {
    $source=new GateSessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>$up,'observed_at'=>GATE_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/gate-fixture']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref']??'', $session['session_ref']??'', $up['step_up_ref']??null,GATE_NOW);
}
function gate(VerifiedAccessContext $ctx,string $method,string $path,?array $up=null,array $device=[],array $session=[]): array {
    $device=$device?:gd();$session=$session?:gsession();
    return ExternalApiRequestGate::authorize($ctx,$method,$path,GATE_SCOPE,gauth($ctx,$device,$session,$up),GATE_NOW);
}

$case=$argv[1]??'';
if($case==='read'){
    $out=[
        'valid'=>gate(gctx('owner.cockpit.read'),'GET','/api/v1/cockpit'),
        'with_step'=>gate(gctx('owner.cockpit.read'),'GET','/api/v1/cockpit',gu()),
        'revoked_device'=>bad(fn()=>gate(gctx('owner.cockpit.read'),'GET','/api/v1/cockpit',null,gd(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked']))),
    ];
}elseif($case==='deny'){
    $out=gate(gctx('owner.inbox.read'),'GET','/api/v1/cockpit',gu());
}elseif($case==='mutation'){
    $ctx=gctx('owner.decision.write');
    $out=[
        'without'=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision'),
        'with'=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision',gu()),
    ];
}elseif($case==='auth_fail'){
    $ctx=gctx('owner.decision.write');
    $out=[
        'expired_session'=>bad(fn()=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision',gu(),gd(),gsession(['expires_at'=>7000]))),
        'revoked_session'=>bad(fn()=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision',gu(),gd(),gsession(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked']))),
        'revoked_step'=>bad(fn()=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision',gu(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked']))),
        'wrong_step_session'=>bad(fn()=>gate($ctx,'POST','/api/v1/owner-decisions/{decision_id}/decision',gu(['session_ref'=>'session:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']))),
    ];
}elseif($case==='client'){
    $session=gsession();$session['capability']='owner.decision.write';
    $device=gd();$device['access_token']='forbidden';
    $step=gu();$step['policy_ref']='controlbot:policy/client';
    $out=[
        'raw_context'=>badType(fn()=>ExternalApiRequestGate::authorize([], 'GET','/api/v1/cockpit',GATE_SCOPE,gauth(gctx('owner.cockpit.read'),gd(),gsession(),null),GATE_NOW)),
        'session_auth_field'=>bad(fn()=>gate(gctx('owner.cockpit.read'),'GET','/api/v1/cockpit',null,gd(),$session)),
        'device_secret'=>bad(fn()=>gate(gctx('owner.cockpit.read'),'GET','/api/v1/cockpit',null,$device)),
        'step_auth_field'=>bad(fn()=>gate(gctx('owner.decision.write'),'POST','/api/v1/owner-decisions/{decision_id}/decision',$step)),
    ];
}elseif($case==='trusted_mismatch'){
    $access=gctx('owner.cockpit.read');
    $other=gctx('owner.cockpit.read','L4_OWNER',GATE_SCOPE,'identity-other');
    $otherAuth=gauth($other,gd(['identity_id'=>'identity-other']),gsession(['identity_id'=>'identity-other']),null);
    $beta=gctx('owner.cockpit.read','L4_OWNER','venture:beta');
    $betaAuth=gauth($beta,gd(),gsession(['scope'=>'venture:beta']),null);
    $out=[
        'identity'=>bad(fn()=>ExternalApiRequestGate::authorize($access,'GET','/api/v1/cockpit',GATE_SCOPE,$otherAuth,GATE_NOW)),
        'scope'=>bad(fn()=>ExternalApiRequestGate::authorize($access,'GET','/api/v1/cockpit',GATE_SCOPE,$betaAuth,GATE_NOW)),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiRequestGate::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiRequestGate::class));
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiRequestGate.php')];
}else{fwrite(STDERR,"Unknown external API request gate scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
