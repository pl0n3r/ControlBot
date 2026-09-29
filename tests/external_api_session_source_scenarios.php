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
require __DIR__.'/../src/ExternalApiSession.php';
require __DIR__.'/../src/ExternalApiSessionSource.php';
require __DIR__.'/../src/VerifiedExternalSessionContext.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const SRC_NOW=7000;
const SRC_SCOPE='venture:alpha';
const SRC_DEVICE='device:11111111111111111111111111111111';
const SRC_SESSION='session:22222222222222222222222222222222';
const SRC_STEP='stepup:33333333333333333333333333333333';

function sr(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException|LogicException|TypeError){return true;} }
function sd(array $o=[]): array { return array_replace(['version'=>1,'device_ref'=>SRC_DEVICE,'identity_id'=>'identity-owner','registered_at'=>6500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }
function ss(array $o=[]): array { return array_replace(['version'=>1,'session_ref'=>SRC_SESSION,'device_ref'=>SRC_DEVICE,'identity_id'=>'identity-owner','scope'=>SRC_SCOPE,'issued_at'=>6900,'expires_at'=>7500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }
function su(array $o=[]): array { return array_replace(['version'=>1,'step_up_ref'=>SRC_STEP,'session_ref'=>SRC_SESSION,'device_ref'=>SRC_DEVICE,'identity_id'=>'identity-owner','method'=>'passkey','verified_at'=>6950,'expires_at'=>7200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],$o); }

final class SessionFixtureSource implements ExternalApiSessionSource {
    public int $calls=0;
    public function __construct(private array $row) {}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array {
        $this->calls++;
        return $this->row;
    }
}
final class SourceAccess implements VentureAccessSource {
    public function resolve(string $identityId,string $scope,string $capability,int $now): array {
        return [
            'identity'=>['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>6900],
            'scope'=>SRC_SCOPE,
            'active_policy_refs'=>['controlbot:policy/external-owner-v1'],
            'grant'=>['version'=>1,'grant_id'=>'grant-owner-source','identity_id'=>'identity-owner','role'=>'owner','capability'=>'owner.cockpit.read','scope'=>SRC_SCOPE,'authority_level'=>'L4_OWNER','policy_ref'=>'controlbot:policy/external-owner-v1','budget_limit'=>null,'granted_at'=>6800,'expires_at'=>8000],
        ];
    }
}
function sctx(): VerifiedAccessContext {
    $source=new SourceAccess();
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'external-session-source-');
    $audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/ControlBot',
            ['identity_id'=>'identity-owner','scope'=>SRC_SCOPE,'capability'=>'owner.cockpit.read'],
            SRC_NOW
        );
    }finally{@unlink($path);}
}
function row(array $o=[]): array { return array_replace([
    'version'=>1,'device'=>sd(),'session'=>ss(),'step_up'=>su(),
    'observed_at'=>6995,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/primary',
],$o); }
function build(array $o=[],?string $step=SRC_STEP): VerifiedExternalSessionContext {
    $source=new SessionFixtureSource(row($o));
    return VerifiedExternalSessionContext::fromSource(sctx(),$source,SRC_DEVICE,SRC_SESSION,$step,SRC_NOW);
}

$case=$argv[1]??'';
if($case==='source'){
    $source=new SessionFixtureSource(row());
    $ctx=VerifiedExternalSessionContext::fromSource(sctx(),$source,SRC_DEVICE,SRC_SESSION,SRC_STEP,SRC_NOW);
    $out=['calls'=>$source->calls,'summary'=>$ctx->safeSummary(),'device'=>$ctx->device(),'session'=>$ctx->session(),'step'=>$ctx->stepUp()];
}elseif($case==='mint'){
    $r=new ReflectionClass(VerifiedExternalSessionContext::class);
    $ctor=$r->getConstructor();
    $ctx=build();
    $out=[
        'constructor_private'=>$ctor!==null && $ctor->isPrivate(),
        'serialize_rejected'=>sr(fn()=>serialize($ctx)),
        'public_methods'=>array_values(array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
            $r->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===VerifiedExternalSessionContext::class
        ))),
    ];
    sort($out['public_methods'],SORT_STRING);
}elseif($case==='fail_closed'){
    $out=[
        'stale'=>sr(fn()=>build(['freshness'=>'stale'])),
        'unknown'=>sr(fn()=>build(['freshness'=>'unknown'])),
        'future_observation'=>sr(fn()=>build(['observed_at'=>7001])),
        'wrong_identity'=>sr(fn()=>build(['session'=>ss(['identity_id'=>'identity-other'])])),
        'wrong_scope'=>sr(fn()=>build(['session'=>ss(['scope'=>'venture:beta'])])),
        'revoked_device'=>sr(fn()=>build(['device'=>sd(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
        'revoked_session'=>sr(fn()=>build(['session'=>ss(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
        'expired_session'=>sr(fn()=>build(['session'=>ss(['expires_at'=>7000])])),
        'revoked_step'=>sr(fn()=>build(['step_up'=>su(['state'=>'revoked','revoked_at'=>6990,'revocation_reason'=>'owner_revoked'])])),
        'wrong_step'=>sr(fn()=>build(['step_up'=>su(['session_ref'=>'session:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])])),
    ];
}elseif($case==='secrets'){
    $good=build()->safeSummary();
    $bad=[];
    foreach(['access_token','cookie','otp','secret','credential','private_key','public_key'] as $field){
        $raw=row();$raw[$field]='forbidden';
        $source=new SessionFixtureSource($raw);
        $bad[$field]=sr(fn()=>VerifiedExternalSessionContext::fromSource(sctx(),$source,SRC_DEVICE,SRC_SESSION,SRC_STEP,SRC_NOW));
    }
    $out=['summary'=>$good,'bad'=>$bad];
}elseif($case==='boundary'){
    $out=build()->safeSummary();
}elseif($case==='pure'){
    $out=[
        'source_methods'=>get_class_methods(ExternalApiSessionSource::class),
        'context_methods'=>get_class_methods(VerifiedExternalSessionContext::class),
        'source_code'=>file_get_contents(__DIR__.'/../src/ExternalApiSessionSource.php'),
        'context_code'=>file_get_contents(__DIR__.'/../src/VerifiedExternalSessionContext.php'),
    ];
}else{fwrite(STDERR,"Unknown external api session source scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
