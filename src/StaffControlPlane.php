<?php
declare(strict_types=1);

namespace ControlBot\Staff;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\ExternalApi\VerifiedExternalSessionContext;
use InvalidArgumentException;

interface StaffProductGateway
{
    public function allowedRoles(string $projectId,int $now): array;
    public function lookup(string $projectId,string $intentId,string $idempotencyKey,int $now): array;
    public function mutate(array $intent,int $now): array;
}

final class StaffControlPlane
{
    private const TYPES=['staff','admin'];
    private const STATUSES=['active','suspended'];
    private const MFA=['unknown','required','satisfied'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const ACTIONS=['staff.invite','staff.suspend','staff.reactivate','staff.role.change','staff.password_recovery.send'];
    private const OUTCOMES=['not_found','applied','denied','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|credential|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function summary(array $raw,string $expectedProjectId): array
    {
        self::fields($raw,['version','project_id','population','active_staff','suspended_staff','failed_logins_summary','source_ref','observed_at','freshness','admin_ref'],'StaffSummary');
        if(($raw['version']??null)!==1||($raw['population']??null)!=='staff_only') throw new InvalidArgumentException('Staff summary boundary invalid.');
        $project=self::id($raw['project_id'],'project_id');
        if($project!==self::id($expectedProjectId,'expected_project_id')) throw new InvalidArgumentException('Staff project scope mismatch.');
        return [
            'version'=>1,'project_id'=>$project,'population'=>'staff_only',
            'active_staff'=>self::count($raw['active_staff'],'active_staff'),
            'suspended_staff'=>self::count($raw['suspended_staff'],'suspended_staff'),
            'failed_logins_summary'=>self::count($raw['failed_logins_summary'],'failed_logins_summary'),
            'source_ref'=>self::ref($raw['source_ref'],'source_ref'),'observed_at'=>self::time($raw['observed_at'],'observed_at'),
            'freshness'=>self::one($raw['freshness'],self::FRESHNESS,'freshness'),'admin_ref'=>self::ref($raw['admin_ref'],'admin_ref'),
        ];
    }

    public static function search(array $raw,string $expectedProjectId): array
    {
        self::fields($raw,['version','project_id','population','records','source_ref','observed_at','freshness'],'StaffSearch');
        if(($raw['version']??null)!==1||($raw['population']??null)!=='staff_only') throw new InvalidArgumentException('Staff search boundary invalid.');
        $project=self::id($raw['project_id'],'project_id');
        if($project!==self::id($expectedProjectId,'expected_project_id')) throw new InvalidArgumentException('Staff project scope mismatch.');
        if(!is_array($raw['records'])||!array_is_list($raw['records'])||count($raw['records'])>100) throw new InvalidArgumentException('staff records invalid.');
        $records=[];$seen=[];
        foreach($raw['records'] as $row){
            self::fields($row,['staff_id','account_type','display_name','email','role','status','last_access_at','mfa_state'],'StaffAccount');
            $id=self::id($row['staff_id'],'staff_id'); if(isset($seen[$id])) throw new InvalidArgumentException('staff duplicated.'); $seen[$id]=true;
            $records[]=[
                'staff_id'=>$id,'account_type'=>self::one($row['account_type'],self::TYPES,'account_type'),
                'display_name'=>self::text($row['display_name'],'display_name',120),'masked_email'=>self::maskEmail($row['email']),
                'role'=>self::slug($row['role'],'role'),'status'=>self::one($row['status'],self::STATUSES,'status'),
                'last_access_at'=>$row['last_access_at']===null?null:self::time($row['last_access_at'],'last_access_at'),
                'mfa_state'=>self::one($row['mfa_state'],self::MFA,'mfa_state'),
            ];
        }
        usort($records,static fn(array $a,array $b):int=>$a['staff_id']<=>$b['staff_id']);
        return [
            'version'=>1,'project_id'=>$project,'population'=>'staff_only','records'=>$records,
            'source_ref'=>self::ref($raw['source_ref'],'source_ref'),'observed_at'=>self::time($raw['observed_at'],'observed_at'),
            'freshness'=>self::one($raw['freshness'],self::FRESHNESS,'freshness'),'directory_persisted'=>false,
        ];
    }

    public static function idempotencyKey(string $projectId,string $intentId): string
    {
        $project=self::id($projectId,'project_id');$intent=self::key($intentId,'intent_id');
        return 'staffidem_'.substr(hash('sha256',$project.'|'.$intent),0,32);
    }

    public static function execute(
        StaffProductGateway $gateway, AppendOnlyAuditLog $audit, VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication, array $intentRaw, int $now
    ): array {
        self::time($now,'now');$intent=self::intent($intentRaw,$now);
        $summary=$access->safeSummary();$decision=$access->decisionContext();$session=$authentication->safeSummary();$step=$authentication->stepUp();
        $scope='project:'.$intent['project_id'];
        if(($decision['identity']['kind']??null)!=='human'||($summary['authority_level']??null)!=='L4_OWNER'
            ||($summary['capability']??null)!=='staff.manage'||($summary['scope']??null)!==$scope
            ||($session['identity_id']??null)!==($summary['identity_id']??null)||($session['scope']??null)!==$scope
            ||($summary['identity_id']??null)!==$intent['requested_by'])
            throw new InvalidArgumentException('owner staff authority required.');
        if(!is_array($step)||($step['method']??null)!=='passkey'||($step['step_up_ref']??null)!==$intent['passkey_assertion_ref']
            ||!is_int($step['verified_at']??null)||!is_int($step['expires_at']??null)
            ||$step['verified_at']>$now||$now>=$step['expires_at'])
            throw new InvalidArgumentException('fresh passkey required.');

        $roles=self::roles($gateway->allowedRoles($intent['project_id'],$now));
        $needsRole=in_array($intent['action'],['staff.invite','staff.role.change'],true);
        if($needsRole){
            if($intent['requested_role']===null||!in_array($intent['requested_role'],$roles,true))
                throw new InvalidArgumentException('requested staff role not allowed.');
        }elseif($intent['requested_role']!==null) throw new InvalidArgumentException('requested_role not allowed.');

        self::audit($audit,$intent,'requested',null,$now);
        $before=self::outcome($gateway->lookup($intent['project_id'],$intent['intent_id'],$intent['idempotency_key'],$now),$intent,true);
        if(in_array($before['status'],['applied','denied'],true))
            return self::finish($audit,$intent,$before,$before['status'],$now);
        if($before['status']==='unknown')
            return self::finish($audit,$intent,$before,'unknown_outcome',$now);

        $result=self::outcome($gateway->mutate($intent,$now),$intent,false);
        if($result['status']==='unknown'){
            $reconciled=self::outcome($gateway->lookup($intent['project_id'],$intent['intent_id'],$intent['idempotency_key'],$now),$intent,true);
            if(in_array($reconciled['status'],['applied','denied'],true))
                return self::finish($audit,$intent,$reconciled,$reconciled['status'],$now);
            if($reconciled['status']==='not_found')
                return self::finish($audit,$intent,$result,'not_found',$now);
            return self::finish($audit,$intent,$result,'unknown_outcome',$now);
        }
        return self::finish($audit,$intent,$result,$result['status'],$now);
    }

    private static function intent(array $raw,int $now): array
    {
        self::fields($raw,['version','intent_id','project_id','staff_ref','action','requested_role','requested_by','passkey_assertion_ref','idempotency_key','requested_at'],'StaffActionIntent');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('StaffActionIntent version invalid.');
        $intent=self::key($raw['intent_id'],'intent_id');$project=self::id($raw['project_id'],'project_id');
        $requested=self::time($raw['requested_at'],'requested_at');
        if($requested>$now||$now-$requested>300) throw new InvalidArgumentException('staff intent expired.');
        $key=self::key($raw['idempotency_key'],'idempotency_key');
        if(!hash_equals(self::idempotencyKey($project,$intent),$key)) throw new InvalidArgumentException('idempotency_key mismatch.');
        return ['version'=>1,'intent_id'=>$intent,'project_id'=>$project,'staff_ref'=>self::id($raw['staff_ref'],'staff_ref'),
            'action'=>self::one($raw['action'],self::ACTIONS,'action'),
            'requested_role'=>$raw['requested_role']===null?null:self::slug($raw['requested_role'],'requested_role'),
            'requested_by'=>self::id($raw['requested_by'],'requested_by'),
            'passkey_assertion_ref'=>self::opaque($raw['passkey_assertion_ref'],'passkey_assertion_ref','stepup'),
            'idempotency_key'=>$key,'requested_at'=>$requested];
    }

    private static function outcome(mixed $raw,array $intent,bool $allowNotFound): array
    {
        self::fields($raw,['intent_id','idempotency_key','status','product_audit_ref'],'StaffMutationOutcome');
        if(($raw['intent_id']??null)!==$intent['intent_id']||($raw['idempotency_key']??null)!==$intent['idempotency_key'])
            throw new InvalidArgumentException('staff mutation correlation mismatch.');
        $status=self::one($raw['status'],self::OUTCOMES,'status');
        if(!$allowNotFound&&$status==='not_found') throw new InvalidArgumentException('mutation returned not_found.');
        $ref=$raw['product_audit_ref'];
        if($status==='not_found'){
            if($ref!==null) throw new InvalidArgumentException('not_found cannot carry audit.');
        }else $ref=self::productAudit($ref);
        return ['intent_id'=>$intent['intent_id'],'idempotency_key'=>$intent['idempotency_key'],'status'=>$status,'product_audit_ref'=>$ref];
    }

    private static function finish(AppendOnlyAuditLog $log,array $intent,array $outcome,string $status,int $now): array
    {
        $local='controlbot:audit/staff/'.substr(hash('sha256',$intent['intent_id'].'|'.$intent['idempotency_key'].'|'.$status),0,32);
        self::audit($log,$intent,$status,$outcome['product_audit_ref'],$now);
        return ['status'=>$status,'intent_id'=>$intent['intent_id'],'audit'=>[
            'controlbot_audit_ref'=>$local,'product_audit_ref'=>$outcome['product_audit_ref'],
            'project_id'=>$intent['project_id'],'action'=>$intent['action'],'intent_id'=>$intent['intent_id'],
            'outcome'=>$status,'occurred_at'=>$now,
        ]];
    }

    private static function audit(AppendOnlyAuditLog $log,array $intent,string $result,?string $evidence,int $now): void
    {
        $log->record(['actor'=>$intent['requested_by'],'action'=>$intent['action'],'repository'=>'pl0n3r/ControlBot',
            'issue'=>333,'category'=>'staff','option'=>$intent['intent_id'],'sha'=>null,'result'=>$result,'evidence'=>$evidence,'at'=>$now]);
    }

    private static function roles(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>32) throw new InvalidArgumentException('staff roles invalid.');
        $seen=[];foreach($raw as $role){$role=self::slug($role,'role');if(isset($seen[$role]))throw new InvalidArgumentException('staff roles duplicated.');$seen[$role]=true;}
        $roles=array_keys($seen);sort($roles,SORT_STRING);return $roles;
    }

    private static function productAudit(mixed $value): string
    {
        if(!is_string($value)||preg_match('#^product:audit/[a-z0-9][a-z0-9._-]{7,95}$#D',$value)!==1||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('product_audit_ref invalid.');
        return $value;
    }

    private static function key(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function maskEmail(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>254||filter_var($value,FILTER_VALIDATE_EMAIL)===false) throw new InvalidArgumentException('email invalid.');
        $separator=strrpos($value,'@');
        $local=substr($value,0,$separator);
        $domain=substr($value,$separator+1);
        return substr($local,0,1).'***@'.strtolower($domain);
    }
    private static function id(mixed $v,string $l): string { if(!is_string($v)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$v)!==1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function slug(mixed $v,string $l): string { if(!is_string($v)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$v)!==1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function ref(mixed $v,string $l): string { if(!is_string($v)||strlen($v)>160||preg_match('#^[a-z][a-z0-9._:-]*(?:/[a-z0-9._:-]+)*$#D',$v)!==1||preg_match(self::SENSITIVE,$v)===1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function text(mixed $v,string $l,int $max): string { if(!is_string($v)||trim($v)===''||strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v)===1) throw new InvalidArgumentException($l.' invalid.'); return trim($v); }
    private static function count(mixed $v,string $l): int { if(!is_int($v)||$v<0) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function time(mixed $v,string $l): int { if(!is_int($v)||$v<1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function one(mixed $v,array $allowed,string $l): string { if(!is_string($v)||!in_array($v,$allowed,true)) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function fields(mixed $row,array $expected,string $label): void { if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.'); $a=array_keys($row);sort($a);sort($expected);if($a!==$expected) throw new InvalidArgumentException($label.' fields invalid.'); }
}
