<?php
declare(strict_types=1);
foreach(['Approvals.php','OwnerSession.php','GitHub.php','ApprovalEndpoint.php','VentureIdentity.php','VentureAccessSource.php','DecisionRights.php','DecisionRuntime.php','VerifiedAccessContext.php','ExternalApiSession.php','ExternalApiSessionSource.php','VerifiedExternalSessionContext.php','StaffControlPlane.php'] as $f) require __DIR__.'/../src/'.$f;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\ExternalApi\ExternalApiSessionSource;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Staff\StaffControlPlane;

const N=9000,S='project:controlbot',D='device:11111111111111111111111111111111',SE='session:22222222222222222222222222222222',U='stepup:33333333333333333333333333333333';
const I='staffintent:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',ST='staff:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',IV='invitee:cccccccccccccccccccccccccccccccc';
function summary(array $x=[]): array { return array_replace(['version'=>1,'project_id'=>'controlbot','population'=>'staff_only','active_staff'=>2,'suspended_staff'=>1,'failed_logins_summary'=>3,'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh','admin_ref'=>'admin/controlbot'],$x); }
function records(): array { return [['staff_id'=>'staff-alpha','account_type'=>'staff','display_name'=>'Ada Operator','email'=>'ada@example.com','role'=>'operator','status'=>'active','last_access_at'=>990,'mfa_state'=>'satisfied'],['staff_id'=>'staff-owner','account_type'=>'admin','display_name'=>'Owner Admin','email'=>'owner@example.org','role'=>'admin','status'=>'suspended','last_access_at'=>null,'mfa_state'=>'required']]; }
function search(array $x=[]): array { return array_replace(['version'=>1,'project_id'=>'controlbot','population'=>'staff_only','records'=>records(),'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh'],$x); }
function bad(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

final class StaffAccessSource implements VentureAccessSource {
    public function __construct(private string $kind='human',private string $cap='staff.manage',private string $authority='L4_OWNER',private string $scope=S){}
    public function resolve(string $identityId,string $scope,string $capability,int $now): array {
        return [
            'identity'=>['version'=>1,'identity_id'=>'identity-owner','kind'=>$this->kind,'display_name'=>'Owner','state'=>'active','source_ref'=>'controlbot:identity/staff-owner','observed_at'=>8800],
            'scope'=>$this->scope,'active_policy_refs'=>['controlbot:policy/staff-v1'],
            'grant'=>['version'=>1,'grant_id'=>'grant-staff-owner','identity_id'=>'identity-owner','role'=>$this->authority==='L4_OWNER'?'owner':'venture_admin','capability'=>$this->cap,'scope'=>$this->scope,'authority_level'=>$this->authority,'policy_ref'=>'controlbot:policy/staff-v1','budget_limit'=>null,'granted_at'=>8700,'expires_at'=>9500],
        ];
    }
}
final class StaffSessionSource implements ExternalApiSessionSource {
    public function __construct(private string $scope=S,private string $method='passkey'){}
    public function resolve(string $identityId,string $scope,string $deviceRef,string $sessionRef,?string $stepUpRef,int $now): array {
        return ['version'=>1,
            'device'=>['version'=>1,'device_ref'=>D,'identity_id'=>'identity-owner','registered_at'=>8500,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'session'=>['version'=>1,'session_ref'=>SE,'device_ref'=>D,'identity_id'=>'identity-owner','scope'=>$this->scope,'issued_at'=>8900,'expires_at'=>9300,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'step_up'=>['version'=>1,'step_up_ref'=>U,'session_ref'=>SE,'device_ref'=>D,'identity_id'=>'identity-owner','method'=>$this->method,'verified_at'=>8950,'expires_at'=>9200,'state'=>'active','revoked_at'=>null,'revocation_reason'=>null],
            'observed_at'=>8995,'freshness'=>'fresh','source_ref'=>'controlbot:session-source/staff',
        ];
    }
}
function contexts(string $kind='human',string $cap='staff.manage',string $authority='L4_OWNER',string $scope=S,string $method='passkey'): array {
    $source=new StaffAccessSource($kind,$cap,$authority,$scope);
    $vault=new TokenVault(base64_encode(str_repeat('S',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));$owners=new OwnerSessionService('pl0n3r',$vault);$session=[];
    $owners->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');$path=tempnam(sys_get_temp_dir(),'staff-access-');$audit=new AppendOnlyAuditLog($path);
    try{$runtime=DecisionRuntime::fromServer($owners,$audit,['pl0n3r/ControlBot'],$source);$access=VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/ControlBot',['identity_id'=>'identity-owner','scope'=>$scope,'capability'=>$cap],N);}
    finally{@unlink($path);}
    $external=VerifiedExternalSessionContext::fromSource($access,new StaffSessionSource($scope,$method),D,SE,U,N);
    return [$access,$external];
}
function intentRaw(string $action='staff.invite',?string $role='admin',?string $target=null): array {
    return ['version'=>1,'intent_id'=>I,'project_id'=>'controlbot','staff_id_or_invitee_ref'=>$target??($action==='staff.invite'?IV:ST),'action'=>$action,'requested_role'=>$role,'requested_at'=>N];
}
function intent(string $action='staff.invite',?string $role='admin',?string $target=null): array {
    [$a,$s]=contexts();return StaffControlPlane::actionIntent(intentRaw($action,$role,$target),$a,$s,['admin','operator'],N);
}
function outcome(array $intent,string $state='applied',array $extra=[]): array {
    return array_replace(['version'=>1,'idempotency_key'=>$intent['idempotency_key'],'state'=>$state,'product_audit_ref'=>'productaudit:dddddddddddddddddddddddddddddddd','evidence_ref'=>'evidence:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','observed_at'=>9050],$extra);
}

$case=$argv[1]??'';
if($case==='summary'){echo json_encode(['valid'=>StaffControlPlane::summary(summary(),'controlbot'),'customer_boundary'=>bad(fn()=>StaffControlPlane::summary(summary(['population'=>'all_accounts']),'controlbot'))],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='search'){$out=StaffControlPlane::search(search(),'controlbot');$quoted=records();$quoted[0]['email']='"a@private-name"@example.com';$quotedOut=StaffControlPlane::search(search(['records'=>$quoted]),'controlbot');echo json_encode(['valid'=>$out,'raw_email_leaked'=>str_contains(json_encode($out,JSON_THROW_ON_ERROR),'ada@example.com'),'quoted_masked'=>$quotedOut['records'][0]['masked_email']],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='invalid'){$customer=records();$customer[0]['account_type']='customer';$extra=records();$extra[0]['password']='nope';echo json_encode(['customer'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$customer]),'controlbot')),'ambiguous'=>bad(fn()=>StaffControlPlane::search(search(['population'=>'unknown']),'controlbot')),'cross_project'=>bad(fn()=>StaffControlPlane::search(search(),'condor')),'extra_sensitive'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$extra]),'controlbot'))],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='invite'){$i=intent();$secret=outcome($i);$secret['activation_token']='forbidden';echo json_encode(['intent'=>$i,'result'=>StaffControlPlane::recordOutcome(outcome($i),$i),'role_reject'=>bad(fn()=>intent('staff.invite','superadmin')),'secret_reject'=>bad(fn()=>StaffControlPlane::recordOutcome($secret,$i))],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='mutations'){$out=[];foreach([['staff.suspend',null],['staff.reactivate',null],['staff.role.change','operator']] as [$action,$role]){$a=intent($action,$role);$b=intent($action,$role);$out[$action]=['typed'=>$a,'stable'=>$a['idempotency_key']===$b['idempotency_key']];}echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='ambiguous'){$i=intent('staff.suspend',null);$u=StaffControlPlane::recordOutcome(outcome($i,'unknown'),$i);$applied=StaffControlPlane::reconcile(['version'=>1,'idempotency_key'=>$i['idempotency_key'],'state'=>'applied','product_audit_ref'=>'productaudit:ffffffffffffffffffffffffffffffff','evidence_ref'=>'evidence:11111111111111111111111111111111','observed_at'=>9060],$u,$i);$notFound=StaffControlPlane::reconcile(['version'=>1,'idempotency_key'=>$i['idempotency_key'],'state'=>'not_found','product_audit_ref'=>'productaudit:22222222222222222222222222222222','evidence_ref'=>'evidence:33333333333333333333333333333333','observed_at'=>9060],$u,$i);echo json_encode(['unknown'=>$u,'applied'=>$applied,'not_found'=>$notFound],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='authority'){echo json_encode([
    'agent'=>bad(function(){[$a,$s]=contexts('agent');StaffControlPlane::actionIntent(intentRaw(),$a,$s,['admin'],N);}),
    'capability'=>bad(function(){[$a,$s]=contexts('human','staff.read');StaffControlPlane::actionIntent(intentRaw(),$a,$s,['admin'],N);}),
    'authority'=>bad(function(){[$a,$s]=contexts('human','staff.manage','L2_VENTURE_ADMIN');StaffControlPlane::actionIntent(intentRaw(),$a,$s,['admin'],N);}),
    'scope'=>bad(function(){[$a,$s]=contexts('human','staff.manage','L4_OWNER','project:other');StaffControlPlane::actionIntent(intentRaw(),$a,$s,['admin'],N);}),
    'mfa'=>bad(function(){[$a,$s]=contexts('human','staff.manage','L4_OWNER',S,'mfa');StaffControlPlane::actionIntent(intentRaw(),$a,$s,['admin'],N);}),
],JSON_THROW_ON_ERROR),PHP_EOL;}
elseif($case==='audit'){$i=intent('staff.password_recovery.send',null);$r=StaffControlPlane::recordOutcome(outcome($i),$i);echo json_encode(['result'=>$r,'serialized'=>json_encode($r,JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR),PHP_EOL;}
else{fwrite(STDERR,"scenario invalid\n");exit(2);}