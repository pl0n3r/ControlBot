<?php
declare(strict_types=1);

foreach([
    'Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource',
    'DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiContract','ExternalApiAccess',
    'ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate',
    'ExternalApiOwnerDecisionRead'
] as $file) require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiOwnerDecisionRead;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const D_NOW=14000;
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
function dctx(string $capability='owner.decision.read',string $scope=D_SCOPE): VerifiedAccessContext {
    $identity=['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>13900];
    $grant=['version'=>1,'grant_id'=>'grant-owner-decision-read','identity_id'=>'identity-owner','role'=>'owner','capability'=>$capability,'scope'=>$scope,'authority_level'=>'L4_OWNER','policy_ref'=>D_POLICY,'budget_limit'=>null,'granted_at'=>13800,'expires_at'=>15000];
    $source=new DAccessSource(['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>[D_POLICY],'grant'=>$grant]);
    $vault=new TokenVault(base64_encode(str_repeat('D',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'decision-read-');$audit=new AppendOnlyAuditLog($path);
    try{
        $runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);
        return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$capability],D_NOW);
    }finally{@unlink($path);}
}
function dauth(VerifiedAccessContext $ctx,string $scope=D_SCOPE,bool $revoked=false): VerifiedExternalSessionContext {
    $device=['version'=>1,'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'identity-owner','registered_at'=>13500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null];
    $session=['version'=>1,'session_ref'=>'session:22222222222222222222222222222222','device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner','scope'=>$scope,'issued_at'=>13900,'expires_at'=>14500,'state'=>$revoked?'revoked':'active','revoked_at'=>$revoked?13950:null,'revocation_reason'=>$revoked?'owner_revoked':null];
    $source=new DSessionSource(['version'=>1,'device'=>$device,'session'=>$session,'step_up'=>null,'observed_at'=>D_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/decision-read']);
    return VerifiedExternalSessionContext::fromSource($ctx,$source,$device['device_ref'],$session['session_ref'],null,D_NOW);
}
function dmeta(string $state='current'): array {
    return ['request_id'=>str_repeat('a',32),'correlation_id'=>str_repeat('b',32),'generated_at'=>D_NOW,'freshness'=>$state==='unknown'?['state'=>'unknown','observed_at'=>null,'source_ref'=>null]:['state'=>$state,'observed_at'=>13990,'source_ref'=>'controlbot:source/owner-decision-read']];
}
function ddetail(array $overrides=[]): array {
    return array_replace(['decision_id'=>'decision-alpha','category'=>'product-direction','title'=>'Review launch sequence','question'=>'Approve the staged launch for the next release?','options'=>[['key'=>'A','label'=>'Approve staged launch'],['key'=>'B','label'=>'Keep current rollout']],'state'=>'pending','deadline_at'=>14500],$overrides);
}
function dbad(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function dread(array $meta=[],array $detail=[]): array {
    $ctx=dctx();
    return ExternalApiOwnerDecisionRead::detail($ctx,dauth($ctx),D_SCOPE,'decision-alpha',$meta?:dmeta(),$detail?:ddetail(),D_NOW);
}

$case=$argv[1]??'';
if($case==='authorized') $out=dread();
elseif($case==='mismatch'){
    $ctx=dctx();
    $out=[
        'requested'=>dbad(fn()=>ExternalApiOwnerDecisionRead::detail($ctx,dauth($ctx),D_SCOPE,'decision-beta',dmeta(),ddetail(),D_NOW)),
        'server'=>dbad(fn()=>dread(dmeta(),ddetail(['decision_id'=>'decision-beta']))),
    ];
}elseif($case==='contract'){
    $out=[
        'valid'=>dread(),
        'duplicate_option'=>dbad(fn()=>dread(dmeta(),ddetail(['options'=>[['key'=>'A','label'=>'Approve staged launch'],['key'=>'A','label'=>'Keep current rollout']]]))),
        'invalid_state'=>dbad(fn()=>dread(dmeta(),ddetail(['state'=>'unknown']))),
        'invalid_deadline'=>dbad(fn()=>dread(dmeta(),ddetail(['deadline_at'=>0]))),
        'extra_field'=>dbad(fn()=>dread(dmeta(),ddetail()+['authority_level'=>'L4_OWNER'])),
    ];
}elseif($case==='invalid_auth'){
    $ctx=dctx();
    $out=[
        'wrong_scope'=>dbad(fn()=>ExternalApiOwnerDecisionRead::detail($ctx,dauth($ctx,'venture:beta'),D_SCOPE,'decision-alpha',dmeta(),ddetail(),D_NOW)),
        'revoked'=>dbad(fn()=>ExternalApiOwnerDecisionRead::detail($ctx,dauth($ctx,D_SCOPE,true),D_SCOPE,'decision-alpha',dmeta(),ddetail(),D_NOW)),
        'wrong_capability'=>dbad(function(){
            $wrong=dctx('owner.cockpit.read');
            ExternalApiOwnerDecisionRead::detail($wrong,dauth($wrong),D_SCOPE,'decision-alpha',dmeta(),ddetail(),D_NOW);
        }),
    ];
}elseif($case==='privacy'){
    $out=[
        'unknown'=>dread(dmeta('unknown')),
        'ordinary_copy'=>dread(dmeta(),ddetail(['question'=>'Review tokenization footprint with the Secretary on 2026-09-29 10:00'])),
        'email_pii'=>dbad(fn()=>dread(dmeta(),ddetail(['title'=>'Contact owner@example.com']))),
        'phone_pii'=>dbad(fn()=>dread(dmeta(),ddetail(['title'=>'Call 300-123-4567']))),
        'secret'=>dbad(fn()=>dread(dmeta(),ddetail(['question'=>'Share API token before launch?']))),
        'dsn'=>dbad(fn()=>dread(dmeta(),ddetail(['question'=>'Share DSN before launch?']))),
    ];
}elseif($case==='pure'){
    $first=dread();$second=dread();$reflection=new ReflectionClass(ExternalApiOwnerDecisionRead::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiOwnerDecisionRead::class));
    sort($methods);
    $out=['methods'=>$methods,'first'=>$first,'second'=>$second,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiOwnerDecisionRead.php')];
}else{fwrite(STDERR,"Unknown owner decision read scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
