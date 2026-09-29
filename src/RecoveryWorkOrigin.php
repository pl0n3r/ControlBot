<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RecoveryWorkOrigin
{
    private const CLASSES=[
        'backup_missing','backup_stale','offsite_missing','checksum_failed',
        'restore_drill_failed','rpo_breached','rto_breached','retention_drift',
    ];
    private const STATES=['HEALTHY','DEGRADED','UNKNOWN','BLOCKED'];
    private const TYPES=['engineering','security','infrastructure','operations','data_analytics','product','content','marketing_growth','sales_support','finance_analysis','compliance_review','knowledge_documentation'];
    private const PRIORITIES=['critical','high','medium'];
    private const SEVERITIES=['critical','high','medium','low','info'];
    private const REQUIRED=['version','work_id','group_id','work_type','requested_capabilities','required_roles','authority_level','priority_class','depends_on','claims','policy_ref','health_ref'];
    private const OPTIONAL=['venture_id','repository_ref','budget_ref','approval_ref','severity'];
    private const SENSITIVE='/(?:Bearer\s+[A-Za-z0-9._~+\/=\-]{10,}|github_pat_[A-Za-z0-9_]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9]{20,}|(?:password|passwd|secret|token|api[_-]?key)\s*[:=])/i';

    public static function fromFactoryHealth(array $intent,array $healthRaw,string $workClass): array
    {
        $health=self::health($healthRaw);
        if($health['state']==='HEALTHY') throw new InvalidArgumentException('Healthy recovery cannot origin remediation.');
        $workClass=self::catalog($workClass,self::CLASSES,'recovery.work_class');
        if(!in_array($workClass,$health['work_item_classes'],true))
            throw new InvalidArgumentException('Recovery work class not present in Factory health.');

        self::intent($intent);
        $item=[
            'work_id'=>self::text($intent['work_id'],'work_id'),
            'origin_mode'=>'automatic',
            'origin_system'=>'aegis',
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'project_id'=>$health['project'],
            'work_type'=>self::catalog($intent['work_type'],self::TYPES,'work_type'),
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false,true),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false,true),
            'authority_level'=>self::slug($intent['authority_level'],'authority_level'),
            'producer_ref'=>'controlbot:aegis/recovery',
            'priority_class'=>self::catalog($intent['priority_class'],self::PRIORITIES,'priority_class'),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true,false),
            'claims'=>self::items($intent['claims'],'claims',true,false),
            'policy_ref'=>self::text($intent['policy_ref'],'policy_ref'),
            'evidence_refs'=>[InfrastructureProvider::normalizeReference($intent['health_ref'],'health_ref')],
            'idempotency_key'=>'recovery:'.$workClass.':'.substr(hash('sha256',$health['project'].'|'.$workClass.'|'.$health['observed_at']),0,48),
            'observed_at'=>$health['observed_at'],
        ];
        return array_replace($item,self::optionals($intent));
    }

    private static function health(array $raw): array
    {
        self::safeJson($raw);
        InfrastructureProvider::assertFields($raw,[
            'version','project','state','observed_at','reasons','work_item_classes',
            'freshness','backup_ref','drill_status','drill_observed','authority','execute',
        ],'Factory Recovery Health');
        if(($raw['version']??null)!==1||($raw['authority']??null)!=='unchanged'||($raw['execute']??null)!==false)
            throw new InvalidArgumentException('Factory Recovery Health contract invalid.');
        $state=self::catalog($raw['state'],self::STATES,'recovery.state');
        $classes=self::items($raw['work_item_classes'],'work_item_classes',true,true);
        foreach($classes as $class) if(!in_array($class,self::CLASSES,true))
            throw new InvalidArgumentException('Factory Recovery Health work class invalid.');
        if($state!=='HEALTHY'&&$classes===[])
            throw new InvalidArgumentException('Nonhealthy Recovery Health requires work class.');
        self::items($raw['reasons'],'reasons',false,false);
        self::freshness($raw['freshness']);
        self::nullableText($raw['backup_ref'],'backup_ref');
        if(($raw['drill_status']===null)!==($raw['drill_observed']===null))
            throw new InvalidArgumentException('Factory Recovery Health drill provenance invalid.');
        if($raw['drill_status']!==null) self::catalog($raw['drill_status'],['PASSED','BREACHED'],'drill_status');
        self::drillObserved($raw['drill_observed']);
        return [
            'project'=>self::slug($raw['project'],'project'),
            'state'=>$state,
            'observed_at'=>self::timestamp($raw['observed_at']),
            'work_item_classes'=>$classes,
        ];
    }

    private static function freshness(mixed $raw): void
    {
        if(!is_array($raw)||($raw!==[]&&array_is_list($raw))||count($raw)>4)
            throw new InvalidArgumentException('Recovery Health freshness invalid.');
        foreach($raw as $key=>$value)
            if(!is_string($key)||!is_int($value)||$value<0)
                throw new InvalidArgumentException('Recovery Health freshness invalid.');
    }

    private static function drillObserved(mixed $raw): void
    {
        if($raw===null) return;
        InfrastructureProvider::assertFields($raw,['rpo_seconds','rto_seconds','rpo_target_seconds','rto_target_seconds'],'Recovery Health drill_observed');
        foreach($raw as $value) if(!is_int($value)||$value<0)
            throw new InvalidArgumentException('Recovery Health drill_observed invalid.');
    }

    private static function intent(array $intent): void
    {
        $keys=array_keys($intent);$allowed=array_merge(self::REQUIRED,self::OPTIONAL);
        if(array_diff(self::REQUIRED,$keys)!==[]||array_diff($keys,$allowed)!==[]||($intent['version']??null)!==1)
            throw new InvalidArgumentException('Recovery work intent fields invalid.');
    }

    private static function optionals(array $intent): array
    {
        $out=[];
        foreach(['venture_id','budget_ref','approval_ref'] as $field)
            if(array_key_exists($field,$intent)&&$intent[$field]!==null) $out[$field]=self::text($intent[$field],$field);
        if(array_key_exists('repository_ref',$intent)&&$intent['repository_ref']!==null){
            $repo=self::text($intent['repository_ref'],'repository_ref');
            if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
            $out['repository_ref']=$repo;
        }
        if(array_key_exists('severity',$intent)&&$intent['severity']!==null)
            $out['severity']=self::catalog($intent['severity'],self::SEVERITIES,'severity');
        return $out;
    }

    private static function items(mixed $value,string $field,bool $allowEmpty,bool $slug): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty&&$value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item) $out[]=$slug?self::slug($item,$field.'[]'):self::text($item,$field.'[]');
        $out=array_values(array_unique($out));sort($out,SORT_STRING);return $out;
    }

    private static function catalog(mixed $value,array $allowed,string $field): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $field): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function nullableText(mixed $value,string $field): ?string
    { return $value===null?null:self::text($value,$field); }

    private static function text(mixed $value,string $field): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($field.' invalid.');
        $value=trim($value);
        if($value===''||strlen($value)>240||strpbrk($value,"\n\r\0")!==false||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        $raw=self::text($value,'observed_at');
        if(preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$raw)!==1)
            throw new InvalidArgumentException('observed_at timezone required.');
        try{$date=new DateTimeImmutable($raw);}catch(\Exception $e){
            throw new InvalidArgumentException('observed_at invalid.',0,$e);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function safeJson(array $raw): void
    {
        $encoded=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($encoded)>100000||preg_match(self::SENSITIVE,$encoded)===1)
            throw new InvalidArgumentException('Factory Recovery Health contains sensitive material.');
    }
}
