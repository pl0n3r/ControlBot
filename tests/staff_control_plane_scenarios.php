<?php
declare(strict_types=1);
foreach(['Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity','VentureAccessSource','DecisionRights','DecisionRuntime','VerifiedAccessContext','ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext','StaffControlPlane'] as $file)
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

function summary(array $x=[]): array { return array_replace([
 'version'=>1,'project_id'=>'controlbot','population'=>'staff_only','active_staff'=>2,'suspended_staff'=>1,
 'failed_logins_summary'=>3,'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh','admin_ref'=>'admin/controlbot'
],$x); }
function records(): array { return [[
 'staff_id'=>'staff-alpha','account_type'=>'staff','display_name'=>'Ada Operator','email'=>'ada@example.com',
 'role'=>'operator','status'=>'active','last_access_at'=>990,'mfa_state'=>'satisfied'
],[
 'staff_id'=>'staff-owner','account_type'=>'admin','display_name'=>'Owner Admin','email'=>'owner@example.org',
 'role'=>'admin','status'=>'suspended','last_access_at'=>null,'mfa_state'=>'required'
]]; }
function search(array $x=[]): array { return array_replace([
 'version'=>1,'project_id'=>'controlbot','population'=>'staff_only','records'=>records(),
 'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh'
],$x); }
function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
const ACTION_NOW=9000;
final class StaffAccessSource implements VentureAccessSource {
 public function __construct(private string $kind='human',private string $level='L4_OWNER',private string $cap='staff.manage',private string $scope='project:controlbot'){}
 public function resolve(string $identityId,string $scope,string $capability,int $now): array {
  return ['identity'=>['version'=>1,'identity_id'=>'identity-owner','kind'=>$this->kind,'display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>8900],
   'scope'=>$this->scope,'active_policy_refs'=>['controlbot:policy/staff-v1'],
   'grant'=>['version'=>1,'grant_id'=>'grant-owner-staff','identity_id'=>'identity-owner','role'=>$this->level==='L4_OWNER'?'owner':'operator',
    'capability'=>$this->cap,'scope'=>$this->scope,'authority_level'=>$this->level,'policy_ref'=>'controlbot:policy/staff-v1','budget_limit'=>null,'granted_at'=>8800,'expires_at'=>10000]];
 }
}
final class StaffSessionSource implements ExternalApiSessionSource {
 public function __construct(private string $method='passkey',private string $scope='project:controlbot'){}
 public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array {
  return ['version'=>1,
   'device'=>['version'=>1,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'registered_at'=>8500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
   'session'=>['version'=>1,'session_ref'=>$sessionRef,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'scope'=>$this->scope,'issued_at'=>8900,'expires_at'=>9500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
   'step_up'=>['version'=>1,'step_up_ref'=>$stepUpRef,'session_ref'=>$sessionRef,'device_ref'=>$deviceRef,'identity_id'=>$identityId,'method'=>$this->method,'verified_at'=>8950,'expires_at'=>9200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
   'observed_at'=>ACTION_NOW,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/staff-test'];
 }
}
final class StaffGateway implements StaffProductGateway {
 public int $mutations=0;public int $lookups=0;public array $lookupQueue=[];public array $lastIntent=[];public array $roles=['admin','staff'];public bool $throwOnMutate=false;
 public array $mutation=['intent_id'=>'intent_00000001','idempotency_key'=>'','status'=>'applied','product_audit_ref'=>'product:audit/action0001'];
 public function allowedRoles(string $projectId,int $now): array{return $this->roles;}
 public function lookup(string $projectId,string $intentId,string $idempotencyKey,int $now): array{$this->lookups++;if($this->lookupQueue!==[])return array_shift($this->lookupQueue);return ['intent_id'=>$intentId,'idempotency_key'=>$idempotencyKey,'status'=>'not_found','product_audit_ref'=>null];}
 public function mutate(array $intent,int $now): array{$this->mutations++;$this->lastIntent=$intent;if($this->throwOnMutate)throw new RuntimeException('gateway failure');return array_replace($this->mutation,['intent_id'=>$intent['intent_id'],'idempotency_key'=>$intent['idempotency_key']]);}
}
function staff_access(string $kind='human',string $level='L4_OWNER',string $cap='staff.manage',string $scope='project:controlbot'): VerifiedAccessContext {
 $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));$owners=new OwnerSessionService('pl0n3r',$vault);$session=[];$owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
 $path=tempnam(sys_get_temp_dir(),'staff-access-');$audit=new AppendOnlyAuditLog($path);
 try{$runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],new StaffAccessSource($kind,$level,$cap,$scope));return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$cap],ACTION_NOW);}finally{@unlink($path);}
}
function staff_auth(VerifiedAccessContext $access,string $method='passkey',string $scope='project:controlbot'): VerifiedExternalSessionContext {
 return VerifiedExternalSessionContext::fromSource($access,new StaffSessionSource($method,$scope),'device:'.str_repeat('1',32),'session:'.str_repeat('2',32),'stepup:'.str_repeat('3',32),ACTION_NOW);
}
function staff_log(): AppendOnlyAuditLog{return new AppendOnlyAuditLog(tempnam(sys_get_temp_dir(),'staff-log-'));}
function staff_intent(string $action='staff.suspend',?string $role=null,string $id='intent_00000001',string $staff='staff-alpha',string $requestedBy='identity-owner'): array {
 return ['version'=>1,'intent_id'=>$id,'project_id'=>'controlbot','staff_ref'=>$staff,'action'=>$action,'requested_role'=>$role,'requested_by'=>$requestedBy,
  'passkey_assertion_ref'=>'stepup:'.str_repeat('3',32),'idempotency_key'=>StaffControlPlane::idempotencyKey('controlbot',$id,$action,$staff,$role),'requested_at'=>ACTION_NOW];
}
$case=$argv[1]??'';
if($case==='summary'){
 echo json_encode(['valid'=>StaffControlPlane::summary(summary(),'controlbot'),
  'customer_boundary'=>bad(fn()=>StaffControlPlane::summary(summary(['population'=>'all_accounts']),'controlbot'))],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='search'){
 $out=StaffControlPlane::search(search(),'controlbot');
 $quoted=records();$quoted[0]['email']='"a@private-name"@example.com';
 $quotedOut=StaffControlPlane::search(search(['records'=>$quoted]),'controlbot');
 echo json_encode(['valid'=>$out,'raw_email_leaked'=>str_contains(json_encode($out,JSON_THROW_ON_ERROR),'ada@example.com'),
  'quoted_masked'=>$quotedOut['records'][0]['masked_email']],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='invalid'){
 $customer=records();$customer[0]['account_type']='customer';
 $extra=records();$extra[0]['password']='nope';
 echo json_encode([
  'customer'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$customer]),'controlbot')),
  'ambiguous'=>bad(fn()=>StaffControlPlane::search(search(['population'=>'unknown']),'controlbot')),
  'cross_project'=>bad(fn()=>StaffControlPlane::search(search(),'condor')),
  'extra_sensitive'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$extra]),'controlbot')),
 ],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='invite'){
 $g=new StaffGateway();$a=staff_access();$log=staff_log();$ok=StaffControlPlane::execute($g,$log,$a,staff_auth($a),staff_intent('staff.invite','admin'),ACTION_NOW);
 $mfa=bad(fn()=>StaffControlPlane::execute(new StaffGateway(),staff_log(),$a,staff_auth($a,'mfa'),staff_intent('staff.invite','admin'),ACTION_NOW));
 $role=bad(fn()=>StaffControlPlane::execute(new StaffGateway(),staff_log(),$a,staff_auth($a),staff_intent('staff.invite','superadmin'),ACTION_NOW));
 echo json_encode(['ok'=>$ok,'mfa_rejected'=>$mfa,'role_rejected'=>$role,'serialized'=>json_encode($ok)],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='mutations'){
 $rows=[];foreach([['staff.suspend',null],['staff.reactivate',null],['staff.role.change','staff']] as $n=>$spec){$g=new StaffGateway();$a=staff_access();$i=staff_intent($spec[0],$spec[1],'intent_0000000'.($n+2));$r=StaffControlPlane::execute($g,staff_log(),$a,staff_auth($a),$i,ACTION_NOW);$rows[]=['status'=>$r['status'],'mutations'=>$g->mutations,'scope'=>'project:'.$g->lastIntent['project_id'],'idempotency_key'=>$g->lastIntent['idempotency_key'],'expected'=>StaffControlPlane::idempotencyKey('controlbot',$i['intent_id'],$i['action'],$i['staff_ref'],$i['requested_role'])];}
 $same='intent_reuse01';$keys=[
  staff_intent('staff.suspend',null,$same,'staff-alpha')['idempotency_key'],
  staff_intent('staff.reactivate',null,$same,'staff-alpha')['idempotency_key'],
  staff_intent('staff.suspend',null,$same,'staff-beta')['idempotency_key'],
  staff_intent('staff.role.change','admin',$same,'staff-alpha')['idempotency_key'],
 ];
 echo json_encode(['rows'=>$rows,'payload_keys_distinct'=>count(array_unique($keys))===4],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='ambiguous'){
 $g=new StaffGateway();$a=staff_access();$i=staff_intent();$g->mutation['status']='unknown';$g->mutation['product_audit_ref']='product:audit/unknown0001';
 $g->lookupQueue=[['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'not_found','product_audit_ref'=>null],['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'applied','product_audit_ref'=>'product:audit/reconciled1']];
 $resolved=StaffControlPlane::execute($g,staff_log(),$a,staff_auth($a),$i,ACTION_NOW);
 $g2=new StaffGateway();$g2->mutation=$g->mutation;$g2->lookupQueue=[['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'not_found','product_audit_ref'=>null],['intent_id'=>$i['intent_id'],'idempotency_key'=>$i['idempotency_key'],'status'=>'unknown','product_audit_ref'=>'product:audit/stillunknown1']];
 $unknown=StaffControlPlane::execute($g2,staff_log(),$a,staff_auth($a),$i,ACTION_NOW);
 echo json_encode(['resolved'=>$resolved,'mutations'=>$g->mutations,'lookups'=>$g->lookups,'unknown'=>$unknown,'unknown_mutations'=>$g2->mutations],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='authority'){
 $cases=[];foreach([['agent','L4_OWNER','staff.manage'],['human','L0_AI_AUTONOMOUS','staff.manage'],['human','L4_OWNER','config.write']] as $row){$g=new StaffGateway();$a=staff_access($row[0],$row[1],$row[2]);$cases[]=bad(fn()=>StaffControlPlane::execute($g,staff_log(),$a,staff_auth($a),staff_intent(),ACTION_NOW))&&$g->mutations===0;}
 $g=new StaffGateway();$a=staff_access('human','L4_OWNER','staff.manage','project:other');$cases[]=bad(fn()=>StaffControlPlane::execute($g,staff_log(),$a,staff_auth($a,'passkey','project:other'),staff_intent(),ACTION_NOW))&&$g->mutations===0;
 $g=new StaffGateway();$a=staff_access();$cases[]=bad(fn()=>StaffControlPlane::execute($g,staff_log(),$a,staff_auth($a),staff_intent('staff.suspend',null,'intent_00000009','staff-alpha','identity-other'),ACTION_NOW))&&$g->mutations===0;
 echo json_encode(['rejected'=>!in_array(false,$cases,true)],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='audit'){
 $g=new StaffGateway();$a=staff_access();$log=staff_log();$r=StaffControlPlane::execute($g,$log,$a,staff_auth($a),staff_intent(),ACTION_NOW);$serialized=json_encode($r,JSON_THROW_ON_ERROR);
 $g2=new StaffGateway();$g2->throwOnMutate=true;$log2=staff_log();$caught=false;try{StaffControlPlane::execute($g2,$log2,$a,staff_auth($a),staff_intent('staff.suspend',null,'intent_00000008'),ACTION_NOW);}catch(Throwable){$caught=true;}$failureEntries=$log2->entries();
 echo json_encode(['result'=>$r,'entries'=>$log->entries(),'secret_free'=>preg_match('/(?:password|token|credential|recovery|@example)/i',$serialized)===0,'gateway_error_caught'=>$caught,'gateway_error_entries'=>$failureEntries],JSON_THROW_ON_ERROR),PHP_EOL;
}else{fwrite(STDERR,"scenario invalid\n");exit(2);}
