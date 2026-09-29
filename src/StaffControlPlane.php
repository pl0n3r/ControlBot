<?php
declare(strict_types=1);

namespace ControlBot\Staff;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use InvalidArgumentException;

interface StaffProductGateway
{
    public function summary(string $projectId,int $now): array;
    public function search(string $projectId,string $query,int $now): array;
    public function allowedRoles(string $projectId,int $now): array;
    public function lookup(string $projectId,string $intentId,string $idempotencyKey,int $now): array;
    public function mutate(array $intent,int $now): array;
}

final class StaffControlPlane
{
    private const ACTIONS=['staff.invite','staff.suspend','staff.reactivate','staff.role.change','staff.password_recovery.send'];
    private const STATES=['active','suspended','invited'];
    private const MFA=['unknown','required','enabled'];
    private const OUTCOMES=['not_found','applied','failed','unknown'];

    public static function summary(StaffProductGateway $gateway,string $projectId,int $now): array
    {
        $project=self::project($projectId); self::positive($now,'now');
        $raw=$gateway->summary($project,$now);
        self::fields($raw,['project_id','boundary','active_staff','suspended_staff','failed_logins_summary','source_freshness','observed_at'],'StaffSummary');
        if($raw['project_id']!==$project||$raw['boundary']!=='staff_only'||$raw['source_freshness']!=='fresh')
            throw new InvalidArgumentException('staff summary boundary invalid.');
        foreach(['active_staff','suspended_staff','failed_logins_summary'] as $field)
            if(!is_int($raw[$field])||$raw[$field]<0) throw new InvalidArgumentException($field.' invalid.');
        self::observed($raw['observed_at'],$now);
        return $raw;
    }

    public static function search(StaffProductGateway $gateway,string $projectId,string $query,int $now): array
    {
        $project=self::project($projectId); self::positive($now,'now');
        if(trim($query)===''||strlen($query)>120||preg_match('/[\x00-\x1F\x7F]/',$query))
            throw new InvalidArgumentException('staff search query invalid.');
        $raw=$gateway->search($project,$query,$now);
        self::fields($raw,['project_id','boundary','records','source_freshness','observed_at'],'StaffSearch');
        if($raw['project_id']!==$project||$raw['boundary']!=='staff_only'||$raw['source_freshness']!=='fresh'
            ||!is_array($raw['records'])||!array_is_list($raw['records'])||count($raw['records'])>100)
            throw new InvalidArgumentException('staff search boundary invalid.');
        self::observed($raw['observed_at'],$now);
        $records=[];
        foreach($raw['records'] as $row) $records[]=self::account($row,$project,$now);
        usort($records,static fn(array $a,array $b): int=>$a['staff_id']<=>$b['staff_id']);
        return ['project_id'=>$project,'records'=>$records,'source_freshness'=>'fresh','observed_at'=>$raw['observed_at']];
    }

    public static function execute(
        StaffProductGateway $gateway, AppendOnlyAuditLog $audit, VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication, array $intentRaw, int $now
    ): array {
        self::positive($now,'now'); $intent=self::intent($intentRaw,$now);
        $a=$access->safeSummary(); $s=$authentication->safeSummary(); $step=$authentication->stepUp();
        $scope='project:'.$intent['project_id'];
        if(($a['authority_level']??null)!=='L4_OWNER'||($a['capability']??null)!=='staff.manage'
            ||($a['scope']??null)!==$scope||($s['scope']??null)!==$scope
            ||($a['identity_id']??null)!==($s['identity_id']??null)
            ||($a['identity_id']??null)!==$intent['requested_by'])
            throw new InvalidArgumentException('owner staff authority required.');
        if(!is_array($step)||($step['method']??null)!=='passkey'
            ||($step['step_up_ref']??null)!==$intent['passkey_assertion_ref'])
            throw new InvalidArgumentException('fresh passkey required.');
        if($intent['policy_result']!=='allow') throw new InvalidArgumentException('staff policy denied.');

        $roles=self::roles($gateway->allowedRoles($intent['project_id'],$now));
        $needsRole=in_array($intent['action'],['staff.invite','staff.role.change'],true);
        if($needsRole){
            if($intent['requested_role']===null||!in_array($intent['requested_role'],$roles,true))
                throw new InvalidArgumentException('requested staff role not allowed.');
        }elseif($intent['requested_role']!==null) throw new InvalidArgumentException('requested_role not allowed.');
        self::audit($audit,$intent,'requested',null,$now);

        $before=self::outcome($gateway->lookup($intent['project_id'],$intent['intent_id'],$intent['idempotency_key'],$now),$intent,true);
        if($before['status']!=='not_found') return self::finish($audit,$intent,$before,$before['status']==='applied'?'already_applied':self::publicStatus($before['status']),$now);

        $after=self::outcome($gateway->mutate($intent,$now),$intent,false);
        if($after['status']==='unknown'){
            $after=self::outcome($gateway->lookup($intent['project_id'],$intent['intent_id'],$intent['idempotency_key'],$now),$intent,true);
            if(in_array($after['status'],['unknown','not_found'],true))
                return self::finish($audit,$intent,$after,'unknown_outcome',$now);
        }
        return self::finish($audit,$intent,$after,self::publicStatus($after['status']),$now);
    }

    private static function account(mixed $raw,string $project,int $now): array
    {
        self::fields($raw,['project_id','staff_id','display_name','masked_email','role','status','last_access_at','mfa_state','source','observed_at','account_kind'],'StaffAccountSummary');
        if($raw['project_id']!==$project||!in_array($raw['account_kind'],['staff','admin'],true))
            throw new InvalidArgumentException('non-staff record rejected.');
        $staff=self::opaque($raw['staff_id'],'staff_id','staff');
        if(!is_string($raw['display_name'])||trim($raw['display_name'])===''||strlen($raw['display_name'])>120)
            throw new InvalidArgumentException('display_name invalid.');
        if(!is_string($raw['masked_email'])||strlen($raw['masked_email'])>160||!str_contains($raw['masked_email'],'*')
            ||preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/D',$raw['masked_email'])!==1)
            throw new InvalidArgumentException('masked_email invalid.');
        $role=self::slug($raw['role'],'role'); $status=self::oneOf($raw['status'],self::STATES,'status');
        $last=$raw['last_access_at']===null?null:self::positive($raw['last_access_at'],'last_access_at');
        $mfa=self::oneOf($raw['mfa_state'],self::MFA,'mfa_state');
        $observed=self::observed($raw['observed_at'],$now); $source=self::ref($raw['source'],'source');
        return ['project_id'=>$project,'staff_id'=>$staff,'display_name'=>$raw['display_name'],'masked_email'=>$raw['masked_email'],
            'role'=>$role,'status'=>$status,'last_access_at'=>$last,'mfa_state'=>$mfa,'source'=>$source,'observed_at'=>$observed,'account_kind'=>$raw['account_kind']];
    }

    private static function intent(array $raw,int $now): array
    {
        self::fields($raw,['version','intent_id','project_id','staff_ref','action','requested_role','requested_by','passkey_assertion_ref','idempotency_key','requested_at','policy_result'],'StaffActionIntent');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('StaffActionIntent version invalid.');
        $at=self::positive($raw['requested_at'],'requested_at');
        if($at>$now||$now-$at>300) throw new InvalidArgumentException('staff intent expired.');
        return ['version'=>1,'intent_id'=>self::key($raw['intent_id'],'intent_id'),'project_id'=>self::project($raw['project_id']),
            'staff_ref'=>self::staffRef($raw['staff_ref']),'action'=>self::oneOf($raw['action'],self::ACTIONS,'action'),
            'requested_role'=>$raw['requested_role']===null?null:self::slug($raw['requested_role'],'requested_role'),
            'requested_by'=>self::identity($raw['requested_by']),'passkey_assertion_ref'=>self::opaque($raw['passkey_assertion_ref'],'passkey_assertion_ref','stepup'),
            'idempotency_key'=>self::key($raw['idempotency_key'],'idempotency_key'),'requested_at'=>$at,
            'policy_result'=>self::oneOf($raw['policy_result'],['allow','deny'],'policy_result')];
    }

    private static function outcome(mixed $raw,array $intent,bool $allowNotFound): array
    {
        self::fields($raw,['intent_id','idempotency_key','status','product_audit_ref'],'StaffMutationOutcome');
        if($raw['intent_id']!==$intent['intent_id']||$raw['idempotency_key']!==$intent['idempotency_key'])
            throw new InvalidArgumentException('staff mutation correlation mismatch.');
        $status=self::oneOf($raw['status'],self::OUTCOMES,'status');
        if(!$allowNotFound&&$status==='not_found') throw new InvalidArgumentException('mutation returned not_found.');
        $audit=$raw['product_audit_ref'];
        if($status==='not_found'){
            if($audit!==null) throw new InvalidArgumentException('not_found cannot carry audit.');
        }else $audit=self::productAudit($audit);
        return ['intent_id'=>$intent['intent_id'],'idempotency_key'=>$intent['idempotency_key'],'status'=>$status,'product_audit_ref'=>$audit];
    }

    private static function finish(AppendOnlyAuditLog $log,array $intent,array $outcome,string $status,int $now): array
    {
        $product=$outcome['product_audit_ref']; self::audit($log,$intent,$status,$product,$now);
        $audit=['controlbot_audit_ref'=>'controlbot:audit/staff/'.hash('sha256',$intent['intent_id'].'|'.$intent['idempotency_key'].'|'.$status),
            'product_audit_ref'=>$product,'project_id'=>$intent['project_id'],'action'=>$intent['action'],
            'intent_id'=>$intent['intent_id'],'outcome'=>$status,'occurred_at'=>$now];
        return ['status'=>$status,'intent_id'=>$intent['intent_id'],'audit'=>$audit];
    }

    private static function audit(AppendOnlyAuditLog $log,array $intent,string $result,?string $evidence,int $now): void {
        $log->record(['actor'=>$intent['requested_by'],'action'=>$intent['action'],'repository'=>'pl0n3r/ControlBot','issue'=>40,'category'=>'staff','option'=>$intent['intent_id'],'sha'=>null,'result'=>$result,'evidence'=>$evidence,'at'=>$now]);
    }
    private static function publicStatus(string $status): string { return $status==='unknown'?'unknown_outcome':$status; }
    private static function roles(mixed $raw): array {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>32) throw new InvalidArgumentException('staff roles invalid.');
        $out=[]; foreach($raw as $role){$role=self::slug($role,'role');if(isset($out[$role]))throw new InvalidArgumentException('staff roles duplicated.');$out[$role]=true;}
        $roles=array_keys($out);sort($roles,SORT_STRING);return $roles;
    }
    private static function productAudit(mixed $v): string {
        if(!is_string($v)||preg_match('#^product:audit/[a-z0-9][a-z0-9._-]{7,95}$#D',$v)!==1) throw new InvalidArgumentException('product_audit_ref invalid.');
        return $v;
    }
    private static function staffRef(mixed $v): string {
        if(!is_string($v)||preg_match('/^(staff|invitee):[a-f0-9]{32}$/D',$v)!==1) throw new InvalidArgumentException('staff_ref invalid.');
        return $v;
    }
    private static function project(mixed $v): string {
        if(!is_string($v)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$v)!==1) throw new InvalidArgumentException('project_id invalid.');
        return $v;
    }
    private static function identity(mixed $v): string {
        if(!is_string($v)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$v)!==1) throw new InvalidArgumentException('requested_by invalid.');
        return $v;
    }
    private static function slug(mixed $v,string $label): string {
        if(!is_string($v)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$v)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }
    private static function key(mixed $v,string $label): string {
        if(!is_string($v)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,79}$/D',$v)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }
    private static function opaque(mixed $v,string $label,string $ns): string {
        if(!is_string($v)||preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{32}$/D',$v)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }
    private static function ref(mixed $v,string $label): string {
        if(!is_string($v)||preg_match('#^product:[a-z][a-z0-9._/-]{1,119}$#D',$v)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }
    private static function observed(mixed $v,int $now): int {
        $at=self::positive($v,'observed_at'); if($at>$now) throw new InvalidArgumentException('observed_at invalid.'); return $at;
    }
    private static function positive(mixed $v,string $label): int {
        if(!is_int($v)||$v<1) throw new InvalidArgumentException($label.' invalid.'); return $v;
    }
    private static function oneOf(mixed $v,array $allowed,string $label): string {
        if(!is_string($v)||!in_array($v,$allowed,true)) throw new InvalidArgumentException($label.' invalid.'); return $v;
    }
    private static function fields(mixed $row,array $expected,string $label): void {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
