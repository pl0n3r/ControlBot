<?php
declare(strict_types=1);
foreach(['Approvals.php','OwnerSession.php','GitHub.php','ApprovalEndpoint.php','VentureIdentity.php','VentureAccessSource.php','DecisionRights.php','DecisionRuntime.php','VerifiedAccessContext.php','ExternalApiSession.php','ExternalApiSessionSource.php','VerifiedExternalSessionContext.php'] as $f) require __DIR__.'/../src/'.$f;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const N=7000,S='venture:alpha',D='device:11111111111111111111111111111111',SE='session:22222222222222222222222222222222',U='stepup:33333333333333333333333333333333';
function bad(callable $f): bool {try{$f();return false;}catch(InvalidArgumentException){return true;}}
function logic(callable $f): bool {try{$f();return false;}catch(LogicException){return true;}}
function dev(array $o=[]):array{return array_replace(['version'=>1,'device_ref'=>D,'identity_id'=>'identity-owner','registered_at'=>6500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o);}
function ses(array $o=[]):array{return array_replace(['version'=>1,'session_ref'=>SE,'device_ref'=>D,'identity_id'=>'identity-owner','scope'=>S,'issued_at'=>6900,'expires_at'=>7500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o);}
function up(array $o=[]):array{return array_replace(['version'=>1,'step_up_ref'=>U,'session_ref'=>SE,'device_ref'=>D,'identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>6950,'expires_at'=>7200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o);}
function raw(array $o=[]):array{return array_replace(['version'=>1,'device'=>dev(),'session'=>ses(),'step_up'=>up(),'observed_at'=>6995,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/primary'],$o);}

final class FSource implements ExternalApiSessionSource{
    public int $calls=0; public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now):array{$this->calls++;return $this->row;}
}
final class FAccess implements VentureAccessSource{
    public function resolve(string $identityId,string $scope,string $capability,int $now):array{return [
        'identity'=>['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>6900],
        'scope'=>S,'active_policy_refs'=>['controlbot:policy/external-owner-v1'],
        'grant'=>['version'=>1,'grant_id'=>'grant-owner-source','identity_id'=>'identity-owner','role'=>'owner','capability'=>'owner.cockpit.read','scope'=>S,'authority_level'=>'L4_OWNER','policy_ref'=>'controlbot:policy/external-owner-v1','budget_limit'=>null,'granted_at'=>6800,'expires_at'=>8000],
    ];}
}
function access():VerifiedAccessContext{
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));$owners=new OwnerSessionService('pl0n3r',$vault);$s=[];
    $owners->establishTrustedOAuthSession($s,'pl0n3r','fixture-server-value');$p=tempnam(sys_get_temp_dir(),'session-source-');$audit=new AppendOnlyAuditLog($p);
    try{$runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],new FAccess());return VerifiedAccessContext::fromDecisionRuntime($runtime,$s,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>S,'capability'=>'owner.cockpit.read'],N);}finally{@unlink($p);}
}
function ctx(array $o=[],?string $step=U):VerifiedExternalSessionContext{$src=new FSource(raw($o));return VerifiedExternalSessionContext::fromSource(access(),$src,D,SE,$step,N);}

$case=$argv[1]??'';
if($case==='source'){$src=new FSource(raw());$c=VerifiedExternalSessionContext::fromSource(access(),$src,D,SE,U,N);$out=['calls'=>$src->calls,'summary'=>$c->safeSummary(),'device'=>$c->device(),'session'=>$c->session(),'step'=>$c->stepUp()];}
elseif($case==='mint'){$r=new ReflectionClass(VerifiedExternalSessionContext::class);$c=ctx();$m=array_map(fn($x)=>$x->getName(),array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),fn($x)=>$x->getDeclaringClass()->getName()===VerifiedExternalSessionContext::class));sort($m);$out=['constructor_private'=>$r->getConstructor()?->isPrivate(),'serialize_rejected'=>logic(fn()=>serialize($c)),'public_methods'=>$m];}
elseif($case==='fail_closed'){$out=[
    'stale'=>bad(fn()=>ctx(['freshness'=>'stale'])),'unknown'=>bad(fn()=>ctx(['freshness'=>'unknown'])),'future'=>bad(fn()=>ctx(['observed_at'=>7001])),
    'identity'=>bad(fn()=>ctx(['session'=>ses(['identity_id'=>'identity-other'])])),'scope'=>bad(fn()=>ctx(['session'=>ses(['scope'=>'venture:beta'])])),
    'device'=>bad(fn()=>ctx(['device'=>dev(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
    'session'=>bad(fn()=>ctx(['session'=>ses(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
    'expired'=>bad(fn()=>ctx(['session'=>ses(['expires_at'=>7000])])),'step'=>bad(fn()=>ctx(['step_up'=>up(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
    'step_mismatch'=>bad(fn()=>ctx(['step_up'=>up(['session_ref'=>'session:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])])),
];}
elseif($case==='secrets'){$bad=[];foreach(['access_token','cookie','otp','secret','credential','private_key','public_key'] as $k){$r=raw();$r[$k]='x';$s=new FSource($r);$bad[$k]=bad(fn()=>VerifiedExternalSessionContext::fromSource(access(),$s,D,SE,U,N));}$out=['summary'=>ctx()->safeSummary(),'bad'=>$bad];}
elseif($case==='boundary'){$out=ctx()->safeSummary();}
elseif($case==='pure'){$out=['source_methods'=>get_class_methods(ExternalApiSessionSource::class),'context_methods'=>get_class_methods(VerifiedExternalSessionContext::class),'source_code'=>file_get_contents(__DIR__.'/../src/ExternalApiSessionSource.php'),'context_code'=>file_get_contents(__DIR__.'/../src/VerifiedExternalSessionContext.php')];}
else{fwrite(STDERR,"Unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
