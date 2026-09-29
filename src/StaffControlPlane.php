<?php
declare(strict_types=1);

namespace ControlBot\Staff;

use InvalidArgumentException;

final class StaffControlPlane
{
    private const TYPES=['staff','admin'];
    private const STATUSES=['active','suspended'];
    private const MFA=['unknown','required','satisfied'];
    private const FRESHNESS=['fresh','stale','unknown'];
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
