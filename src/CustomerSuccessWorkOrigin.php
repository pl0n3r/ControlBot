<?php
declare(strict_types=1);

namespace ControlBot\CustomerSuccess;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CustomerSuccessWorkOrigin
{
    private const REQUIRED=['version','work_id','group_id','work_type','requested_capabilities','required_roles','authority_level','priority_class','depends_on','claims','policy_ref','observed_at'];
    private const OPTIONAL=['project_id','repository_ref','budget_ref','approval_ref'];
    private const PRIORITIES=['critical','high','medium'];
    private const WORK_TYPES=['engineering','security','infrastructure','operations','data_analytics','product','content','marketing_growth','sales_support','finance_analysis','compliance_review','knowledge_documentation'];
    private const SECRET='/(?:Bearer\s+[A-Za-z0-9._~+\/=\-]{10,}|github_pat_[A-Za-z0-9_]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9]{20,})/i';

    public static function fromSupportSignal(array $intent,array $signalRaw,string $ventureId): array
    {
        $signal=CustomerSuccessCore::supportSignal($signalRaw,$ventureId);
        $origin='controlbot:customer-success/'.$signal['signal_id'];
        return self::build(
            $intent,$signal['venture_id'],$origin,
            [$origin,$signal['product_ref'],$signal['pattern_ref'],$signal['knowledge_ref'],$signal['evidence_ref'],$signal['escalation_ref']],
            $signal['severity']
        );
    }

    public static function fromHealthException(array $intent,array $snapshotRaw,string $dimensionName,string $ventureId): array
    {
        $snapshot=CustomerSuccessCore::snapshot($snapshotRaw,$ventureId);
        $dimension=null;
        foreach($snapshot['dimensions'] as $candidate)
            if($candidate['name']===$dimensionName){$dimension=$candidate;break;}
        if($dimension===null) throw new InvalidArgumentException('Customer health dimension invalid.');
        $origin='controlbot:customer-success/'.$snapshot['snapshot_id'].'/'.$dimensionName;
        return self::build(
            $intent,$snapshot['venture_id'],$origin,
            [$origin,$snapshot['product_ref'],$dimension['value_ref'],$dimension['evidence_ref']],null
        );
    }

    private static function build(array $intent,string $ventureId,string $origin,array $evidence,?string $severity): array
    {
        self::intent($intent);
        $item=self::normalizedIntent($intent);
        $item['origin_mode']='automatic';
        $item['origin_system']='controlbot';
        $item['venture_id']=$ventureId;
        $item['producer_ref']='controlbot:customer-success';
        $item['evidence_refs']=self::evidence($evidence);
        $item['idempotency_key']='customer-success:'.hash('sha256',$origin.'|'.$item['work_type']);
        if($severity!==null) $item['severity']=$severity;
        return array_replace($item,self::optionals($intent));
    }

    private static function normalizedIntent(array $intent): array
    {
        return [
            'work_id'=>self::text($intent['work_id'],'work_id'),
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'work_type'=>self::catalog($intent['work_type'],self::WORK_TYPES,'work_type'),
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false,true),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false,true),
            'authority_level'=>self::slug($intent['authority_level'],'authority_level'),
            'priority_class'=>self::catalog($intent['priority_class'],self::PRIORITIES,'priority_class'),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true,false),
            'claims'=>self::items($intent['claims'],'claims',true,false),
            'policy_ref'=>self::text($intent['policy_ref'],'policy_ref'),
            'observed_at'=>self::timestamp($intent['observed_at']),
        ];
    }

    private static function optionals(array $intent): array
    {
        $out=[];
        foreach(['project_id','budget_ref','approval_ref'] as $field)
            if(array_key_exists($field,$intent)&&$intent[$field]!==null) $out[$field]=self::text($intent[$field],$field);
        if(isset($intent['repository_ref'])) $out['repository_ref']=self::repositoryRef($intent['repository_ref']);
        return $out;
    }

    private static function repositoryRef(mixed $value): string
    {
        $value=self::text($value,'repository_ref');
        $parts=explode('/',$value);
        if(count($parts)!==2) throw new InvalidArgumentException('repository_ref invalid.');
        foreach($parts as $part)
            if(preg_match('/^[A-Za-z0-9_.-]+$/D',$part)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
        return $value;
    }

    private static function intent(array $intent): void
    {
        $keys=array_keys($intent); $allowed=array_merge(self::REQUIRED,self::OPTIONAL);
        if(array_diff(self::REQUIRED,$keys)!==[]||array_diff($keys,$allowed)!==[]||($intent['version']??null)!==1)
            throw new InvalidArgumentException('Work origin intent fields invalid.');
    }

    private static function evidence(array $refs): array
    {
        $out=[];
        foreach($refs as $ref) if($ref!==null) $out[]=self::text($ref,'evidence_ref');
        $out=array_values(array_unique($out)); sort($out,SORT_STRING); return $out;
    }

    private static function items(mixed $value,string $field,bool $allowEmpty,bool $asSlug): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty&&$value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item) $out[]=$asSlug?self::slug($item,$field.'[]'):self::text($item,$field.'[]');
        $out=array_values(array_unique($out)); sort($out,SORT_STRING); return $out;
    }

    private static function catalog(mixed $value,array $allowed,string $field): string
    {
        $normalized=self::slug($value,$field);
        if(!in_array($normalized,$allowed,true)) throw new InvalidArgumentException($field.' invalid.');
        return $normalized;
    }

    private static function slug(mixed $value,string $field): string
    {
        $valid=is_string($value)&&preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)===1;
        if(!$valid) throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $field): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($field.' invalid.');
        $value=trim($value);
        $bad=$value===''||strlen($value)>240||strpbrk($value,"\n\r\0")!==false||preg_match(self::SECRET,$value)===1;
        if($bad) throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        $raw=self::text($value,'observed_at');
        if(preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$raw)!==1)
            throw new InvalidArgumentException('observed_at timezone required.');
        try{$date=new DateTimeImmutable($raw);}catch(\Exception $error){
            throw new InvalidArgumentException('observed_at invalid.',0,$error);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
