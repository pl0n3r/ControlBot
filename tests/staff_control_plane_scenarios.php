<?php
declare(strict_types=1);
foreach(['Approvals','OwnerSession','VentureIdentity','VentureAccessSource','DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','StaffControlPlane'] as $file)
    require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Staff\StaffControlPlane;
use ControlBot\Staff\StaffProductGateway;

const NOW=9000; const PROJECT='alpha'; const SCOPE='project:alpha'; const POLICY='controlbot:policy/staff-v1';

final class AccessSource implements VentureAccessSource {
    public function __construct(private string $level='L4_OWNER'){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array {
        return ['identity'=>['version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>8900],
            'scope'=>SCOPE,'active_policy_refs'=>[POLICY],
            'grant'=>['version'=>1,'grant_id'=>'grant-owner-staff','identity_id'=>'identity-owner','role'=>$this->level==='L4_OWNER'?'owner':'operator',
                'capability'=>'staff.manage','scope'=>SCOPE,'authority_level'=>$this->level,'policy_ref'=>POLICY,'budget_limit'=>null,'granted_at'=>8800,'expires_at'=>10000]];
    }
}
final class SessionSource implements ExternalApiSessionSource {
    public function __construct(private string $method='passkey'){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array {
        return ['version'=>1,
            'device'=>['version'=>1,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'registered_at'=>8500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'session'=>['version'=>1,'session_ref'=>$sessionRef,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'scope'=>$scope,'issued_at'=>8900,'expires_at'=>9500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'step_up'=>['version'=>1,'step_up_ref'=>$stepUpRef,'session_ref'=>$sessionRef,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'method'=>$this->method,'verified_at'=>8950,'expires_at'=>9200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'observed_at'=>NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/staff-test'];
    }
}
final class Gateway implements StaffProductGateway {
    public int $mutations=0; public int $lookups=0; public array $lookupQueue=[]; public array $lastIntent=[];
    public array $summaryRow=['project_id'=>PROJECT,'boundary'=>'staff_only','active_staff'=>2,'suspended_staff'=>1,'failed_logins_summary'=>3,'source_freshness'=>'fresh','observed_at'=>NOW];
    public array $searchRow=['project_id'=>PROJECT,'boundary'=>'staff_only','records'=>[],'source_freshness'=>'fresh','observed_at'=>NOW];
    public array $roles=['admin','staff'];
    public array $mutation=['intent_id'=>'intent_00000001','idempotency_key'=>'idem_00000001','status'=>'applied','product_audit_ref'=>'product:audit/aaaaaaaa'];
    public function summary(string $projectId,int $now): array{return $this->summaryRow;}
    public function search(string $projectId,string $query,int $now): array{return $this->searchRow;}
    public function allowedRoles(string $projectId,int $now): array{return $this->roles;}
    public function lookup(string $projectId,string $intentId,string $idempotencyKey,int $now): array{
        $this->lookups++; if($this->lookupQueue!==[]) return array_shift($this->lookupQueue);
        return ['intent_id'=>$intentId,'idempotency_key'=>$idempotencyKey,'status'=>'not_found','product_audit_ref'=>null];
    }
    public function mutate(array $intent,int $now): array{$this->mutations++;$this->lastIntent=$intent;return array_replace($this->mutation,['intent_id'=>$intent['intent_id'],'idempotency_key'=>$intent['idempotency_key']]);}
}
function access(string $level='L4_OWNER'): VerifiedAccessContext {
    $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $owners=new OwnerSessionService('pl0n3r',$vault); $session=[]; $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
    $path=tempnam(sys_get_temp_dir(),'staff-audit-'); $audit=new AppendOnlyAuditLog($path);
    try{$runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],new AccessSource($level));
        return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>SCOPE,'capability'=>'staff.manage'],NOW);
    }finally{@unlink($path);}
}
function auth(VerifiedAccessContext $access,string $method='passkey'): VerifiedExternalSessionContext {
    return VerifiedExternalSessionContext::fromSource($access,new SessionSource($method),'device:'.str_repeat('1',32),'session:'.str_repeat('2',32),'stepup:'.str_repeat('3',32),NOW);
}
function intent(string $action='staff.suspend',?string $role=null,string $id='intent_00000001'): array {
    return ['version'=>1,'intent_id'=>$id,'project_id'=>PROJECT,'staff_ref'=>'staff:'.str_repeat('4',32),'action'=>$action,'requested_role'=>$role,
        'requested_by'=>'identity-owner','passkey_assertion_ref'=>'stepup:'.str_repeat('3',32),'idempotency_key'=>'idem_'.substr($id,7),
        'requested_at'=>NOW,'policy_result'=>'allow'];
}
function account(string $kind='staff'): array {
    return ['project_id'=>PROJECT,'staff_id'=>'staff:'.str_repeat('5',32),'display_name'=>'Equipo Uno','masked_email'=>'e***@example.invalid','role'=>'staff','status'=>'active',
        'last_access_at'=>8900,'mfa_state'=>'enabled','source'=>'product:staff/api','observed_at'=>NOW,'account_kind'=>$kind];
}
function bad(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function alog(): AppendOnlyAuditLog{return new AppendOnlyAuditLog(tempnam(sys_get_temp_dir(),'staff-log-'));}

$case=$argv[1]??'';
if($case==='summary'){
    $g=new Gateway(); $valid=StaffControlPlane::summary($g,PROJECT,NOW); $g->summaryRow['boundary']='ambiguous';
    $out=['valid'=>$valid,'ambiguous_rejected'=>bad(fn()=>StaffControlPlane::summary($g,PROJECT,NOW))];
}elseif($case==='search'){
    $g=new Gateway();$g->searchRow['records']=[account('admin'),array_replace(account(),['staff_id'=>'staff:'.str_repeat('6',32)])];
    $result=StaffControlPlane::search($g,PROJECT,'equipo',NOW);$r=new ReflectionClass(StaffControlPlane::class);
    $out=['result'=>$result,'stateful_properties'=>count($r->getProperties()),'contains_raw_email'=>str_contains(json_encode($result),'person@example')];
}elseif($case==='invite'){
    $g=new Gateway();$a=access();$ok=StaffControlPlane::execute($g,alog(),$a,auth($a),intent('staff.invite','admin'),NOW);
    $mfa=bad(fn()=>StaffControlPlane::execute(new Gateway(),alog(),$a,auth($a,'mfa'),intent('staff.invite','admin'),NOW));
    $g2=new Gateway();$role=bad(fn()=>StaffControlPlane::execute($g2,alog(),$a,auth($a),intent('staff.invite','superadmin'),NOW));
    $out=['ok'=>$ok,'mfa_rejected'=>$mfa,'role_rejected'=>$role,'serialized'=>json_encode($ok)];
}elseif($case==='mutations'){
    $rows=[];foreach([['staff.suspend',null],['staff.reactivate',null],['staff.role.change','staff']] as $idx=>$spec){
        $g=new Gateway();$a=access();$i=intent($spec[0],$spec[1],'intent_0000000'.($idx+2));$r=StaffControlPlane::execute($g,alog(),$a,auth($a),$i,NOW);
        $rows[]=['status'=>$r['status'],'mutations'=>$g->mutations,'scope'=>'project:'.$g->lastIntent['project_id'],'idempotency_key'=>$g->lastIntent['idempotency_key'],'action'=>$g->lastIntent['action']];
    }$out=$rows;
}elseif($case==='ambiguous'){
    $g=new Gateway();$a=access();$i=intent();$g->mutation['status']='unknown';$g->mutation['product_audit_ref']='product:audit/unknown01';
    $g->lookupQueue=[['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'not_found','product_audit_ref'=>null],
        ['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'applied','product_audit_ref'=>'product:audit/reconciled01']];
    $resolved=StaffControlPlane::execute($g,alog(),$a,auth($a),$i,NOW);
    $g2=new Gateway();$g2->mutation=$g->mutation;$g2->lookupQueue=[['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'not_found','product_audit_ref'=>null],
        ['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'unknown','product_audit_ref'=>'product:audit/stillunknown']];
    $unknown=StaffControlPlane::execute($g2,alog(),$a,auth($a),$i,NOW);
    $out=['resolved'=>$resolved,'mutations'=>$g->mutations,'lookups'=>$g->lookups,'unknown'=>$unknown,'unknown_mutations'=>$g2->mutations];
}elseif($case==='boundary'){
    $g=new Gateway();$g->searchRow['records']=[account('customer')];$customer=bad(fn()=>StaffControlPlane::search($g,PROJECT,'x',NOW));
    $g2=new Gateway();$g2->searchRow['boundary']='mixed';$mixed=bad(fn()=>StaffControlPlane::search($g2,PROJECT,'x',NOW));
    $out=['customer_rejected'=>$customer,'mixed_rejected'=>$mixed];
}elseif($case==='agent'){
    $g=new Gateway();$a=access('L0_AI_AUTONOMOUS');$out=['rejected'=>bad(fn()=>StaffControlPlane::execute($g,alog(),$a,auth($a),intent(),NOW)),'mutations'=>$g->mutations];
}elseif($case==='audit'){
    $g=new Gateway();$a=access();$log=alog();$r=StaffControlPlane::execute($g,$log,$a,auth($a),intent(),NOW);$s=json_encode($r);
    $out=['result'=>$r,'entries'=>$log->entries(),'has_both'=>str_starts_with($r['audit']['controlbot_audit_ref'],'controlbot:audit/staff/')&&str_starts_with($r['audit']['product_audit_ref'],'product:audit/'),
        'secret_free'=>!preg_match('/(?:password|token|credential|cookie|secret|@example)/i',$s)];
}else{fwrite(STDERR,"unknown staff scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
