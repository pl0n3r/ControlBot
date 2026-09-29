<?php
declare(strict_types=1);

foreach([
    'Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource',
    'DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiContract','ExternalApiAccess',
    'ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate',
    'ExternalApiReadProjection','ExternalApiAuthenticatedRead'
] as $file) require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiAuthenticatedRead;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const A_NOW=10000;
const A_SCOPE='venture:alpha';
const A_POLICY='controlbot:policy/external-owner-v1';

final class AAccessSource implements VentureAccessSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array{return $this->row;}
}
final class ASessionSource implements ExternalApiSessionSource {
    public function __construct(private array $row){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array{return $this->row;}
}

function actx(string $capability,string $scope=A_SCOPE): VerifiedAccessContext {
    $identity=['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>9900];
    $grant=['version'=>1,'grant_id'=>'grant-owner-read','identity_id'=>'identity-owner','role'=>'owner','capability'=>$capability,'scope'=>$scope,'authority_level'=>'L4_OWNER','policy_ref'=>A_POLICY,'budget_limit'=>null,'granted_at'=>9800,'expires_at'=>11000];
    $source=new AAccessSource(['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>[A_POLICY],'grant'=>$grant]);
    $vault=new TokenVault(base64_encode(str_repeat('A',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault); $session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'auth-read-'); $audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime(
            $runtime,$session,'pl0n3r/ControlBot',
            ['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$capability],A_NOW
        );
    }finally{@unlink($path);}
}
function aauth(VerifiedAccessContext $ctx,string $scope=A_SCOPE,bool $revoked=false,string $identity='identity-owner'): VerifiedExternalSessionContext {
    $device=['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>$identity,'registered_at'=>9500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $session=['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>$device['device_ref'],'identity_id'=>$identity,'scope'=>$scope,'issued_at'=>9900,'expires_at'=>10500,'state'=>$revoked?'revoked':'active','revoked_at'=>$revoked?9990:null,'revocation_reason'=>$revoked?'owner_revoked':null];
    $source=new ASessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>null,'observed_at'=>A_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/auth-read']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref'],$session['session_ref'],null,A_NOW);
}
function ameta(string $state='current'): array {
    return [
        'request_id'=>str_repeat('a',32),'correlation_id'=>str_repeat('b',32),'generated_at'=>A_NOW,
        'freshness'=>$state==='unknown'
            ? ['state'=>'unknown','observed_at'=>null,'source_ref'=>null]
            : ['state'=>$state,'observed_at'=>9990,'source_ref'=>'controlbot:source/mobile-read'],
    ];
}
function ventures(): array {
    return [['venture_ref'=>'controlbot:venture/alpha','business_state'=>'current','technical_state'=>'stale','pending_decisions'=>2,'critical_events'=>1]];
}
function entries(): array {
    return [['entry_id'=>'decision-alpha','kind'=>'DECISION','venture_ref'=>'controlbot:venture/alpha','title'=>'Review budget','summary'=>'Owner review required','decision_id'=>'decision-alpha','deadline_at'=>10100]];
}
function bad(callable $fn): bool {
    try{$fn();return false;}catch(\InvalidArgumentException){return true;}
}

$case=$argv[1]??'';
if($case==='cockpit'){
    $ctx=actx('owner.cockpit.read');
    $out=ExternalApiAuthenticatedRead::cockpit($ctx,aauth($ctx),A_SCOPE,ameta(),ventures(),A_NOW);
}elseif($case==='inbox'){
    $ctx=actx('owner.inbox.read');
    $out=ExternalApiAuthenticatedRead::ownerInbox($ctx,aauth($ctx),A_SCOPE,ameta(),entries(),A_NOW);
}elseif($case==='invalid'){
    $ctx=actx('owner.inbox.read');
    $out=[
        'wrong_scope'=>bad(fn()=>ExternalApiAuthenticatedRead::ownerInbox($ctx,aauth($ctx,'venture:beta'),A_SCOPE,ameta(),entries(),A_NOW)),
        'revoked'=>bad(fn()=>ExternalApiAuthenticatedRead::ownerInbox($ctx,aauth($ctx,A_SCOPE,true),A_SCOPE,ameta(),entries(),A_NOW)),
        'wrong_capability'=>bad(function(){
            $wrong=actx('owner.cockpit.read');
            ExternalApiAuthenticatedRead::ownerInbox($wrong,aauth($wrong),A_SCOPE,ameta(),entries(),A_NOW);
        }),
    ];
}elseif($case==='shape'){
    $cctx=actx('owner.cockpit.read');
    $ictx=actx('owner.inbox.read');
    $out=[
        'cockpit'=>ExternalApiAuthenticatedRead::cockpit($cctx,aauth($cctx),A_SCOPE,ameta('unknown'),ventures(),A_NOW),
        'inbox'=>ExternalApiAuthenticatedRead::ownerInbox($ictx,aauth($ictx),A_SCOPE,ameta(),entries(),A_NOW),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiAuthenticatedRead::class);
    $methods=array_map(
        static fn(ReflectionMethod $m): string=>$m->getName(),
        array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiAuthenticatedRead::class)
    );
    sort($methods);
    $ctx=actx('owner.inbox.read');
    $first=ExternalApiAuthenticatedRead::ownerInbox($ctx,aauth($ctx),A_SCOPE,ameta(),entries(),A_NOW);
    $second=ExternalApiAuthenticatedRead::ownerInbox($ctx,aauth($ctx),A_SCOPE,ameta(),entries(),A_NOW);
    $out=['methods'=>$methods,'first'=>$first,'second'=>$second,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiAuthenticatedRead.php')];
}else{fwrite(STDERR,"Unknown authenticated read scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
