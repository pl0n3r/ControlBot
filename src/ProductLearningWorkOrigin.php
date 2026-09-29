<?php
declare(strict_types=1);

namespace ControlBot\Business;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ProductLearningWorkOrigin
{
    private const REQUIRED=['version','work_id','group_id','work_type','requested_capabilities','required_roles','authority_level','priority_class','depends_on','claims','policy_ref','observed_at'];
    private const OPTIONAL=['project_id','repository_ref','budget_ref','approval_ref'];
    private const PRIORITIES=['critical','high','medium'];

    public static function fromMetric(array $intent,array $signalRaw,array $metricRaw,string $ventureId,string $productId): array
    {
        $signal=ProductLearningSignal::fromMetric($signalRaw,$metricRaw,$ventureId,$productId);
        return self::build($intent,$signal);
    }

    public static function fromExperimentOutcome(
        array $intent,array $signalRaw,array $outcomeRaw,array $baselineRaw,array $variantRaw,
        string $ventureId,string $productId
    ): array {
        $signal=ProductLearningSignal::fromExperimentOutcome(
            $signalRaw,$outcomeRaw,$baselineRaw,$variantRaw,$ventureId,$productId
        );
        return self::build($intent,$signal);
    }

    private static function build(array $intent,array $signal): array
    {
        self::intent($intent);
        $result=[
            'work_id'=>self::text($intent['work_id'],'work_id'),
            'origin_mode'=>'automatic',
            'origin_system'=>'controlbot',
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'venture_id'=>$signal['venture_id'],
            'work_type'=>self::slug($intent['work_type'],'work_type'),
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false),
            'authority_level'=>self::slug($intent['authority_level'],'authority_level'),
            'producer_ref'=>'controlbot:product-intelligence',
            'priority_class'=>self::priority($intent['priority_class']),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true),
            'claims'=>self::items($intent['claims'],'claims',true),
            'policy_ref'=>self::text($intent['policy_ref'],'policy_ref'),
            'evidence_refs'=>self::evidence($signal),
            'idempotency_key'=>'product-learning:'.hash('sha256',$signal['signal_id'].'|'.$intent['work_type']),
            'observed_at'=>self::timestamp($intent['observed_at']),
        ];
        foreach(['project_id','budget_ref','approval_ref'] as $field){
            if(array_key_exists($field,$intent) && $intent[$field]!==null)
                $result[$field]=self::text($intent[$field],$field);
        }
        if(array_key_exists('repository_ref',$intent) && $intent['repository_ref']!==null){
            $repo=self::text($intent['repository_ref'],'repository_ref');
            if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
            $result['repository_ref']=$repo;
        }
        return $result;
    }

    private static function intent(array $intent): void
    {
        $keys=array_keys($intent);
        $allowed=array_merge(self::REQUIRED,self::OPTIONAL);
        if(array_diff(self::REQUIRED,$keys)!==[] || array_diff($keys,$allowed)!==[] || $intent['version']!==1)
            throw new InvalidArgumentException('Work origin intent fields invalid.');
    }

    private static function evidence(array $signal): array
    {
        $refs=[
            'controlbot:product-learning/'.$signal['signal_id'],
            $signal['aggregate_ref'],$signal['source_ref'],$signal['evidence_ref'],
        ];
        $refs=array_values(array_unique($refs)); sort($refs,SORT_STRING);
        return $refs;
    }

    private static function items(mixed $value,string $field,bool $allowEmpty): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty && $value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item){
            $item=self::slug($item,$field.'[]');
            if(!in_array($item,$out,true)) $out[]=$item;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function priority(mixed $value): string
    {
        $value=self::slug($value,'priority_class');
        if(!in_array($value,self::PRIORITIES,true)) throw new InvalidArgumentException('priority_class invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $field): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $field): string
    {
        if(!is_string($value)||$value===''||strlen($value)>240||str_contains($value,"\n")||str_contains($value,"\r"))
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        $raw=self::text($value,'observed_at');
        try{$date=new DateTimeImmutable($raw);}catch(\Exception){throw new InvalidArgumentException('observed_at invalid.');}
        if($date->getTimezone()->getName()==='UTC' && !str_ends_with($raw,'Z') && !str_contains($raw,'+'))
            throw new InvalidArgumentException('observed_at timezone required.');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
