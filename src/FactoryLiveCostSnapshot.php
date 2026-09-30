<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveCostSnapshot
{
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function build(array $snapshot): array
    {
        self::snapshot($snapshot);

        $products=[];
        foreach($snapshot['sections']['work'] as $signal){
            $data=$signal['data'];
            if(!is_array($data)||array_is_list($data))continue;
            $department=$data['department']??null;
            if(!in_array($department,['product','data_analytics'],true))continue;
            if(!isset($data['project'],$data['metric'])||!array_key_exists('metric_value',$data))
                continue;
            if($signal['freshness']==='unknown'||$signal['source_ref']===null||$signal['observed_at']===null)
                continue;
            $products[]=[
                'department'=>$department,
                'project'=>self::text($data['project'],'project'),
                'metric'=>self::text($data['metric'],'metric'),
                'value'=>$data['metric_value'],
                'status'=>$signal['state']==='unknown'?'unknown':'measured',
                'source_ref'=>$signal['source_ref'],
                'observed_at'=>$signal['observed_at'],
                'freshness'=>$signal['freshness'],
                'age_seconds'=>$signal['age_seconds'],
            ];
        }
        usort($products,static fn(array $a,array $b):int=>
            [$a['department'],$a['project'],$a['metric']] <=> [$b['department'],$b['project'],$b['metric']]
        );

        $tool=$snapshot['tool_usage'];
        $toolData=is_array($tool['data'])&&!array_is_list($tool['data'])?$tool['data']:[];
        $hasUsage=array_key_exists('used',$toolData)&&array_key_exists('limit',$toolData)
            && is_int($toolData['used'])&&$toolData['used']>=0
            && is_int($toolData['limit'])&&$toolData['limit']>=0
            && $toolData['used']<=$toolData['limit']
            && $tool['freshness']!=='unknown'
            && $tool['source_ref']!==null
            && $tool['observed_at']!==null;

        $view=[
            'version'=>1,
            'observed_at'=>$snapshot['observed_at'],
            'product_analytics'=>$products,
            'costs'=>['status'=>'unknown','items'=>[]],
            'limits'=>$hasUsage
                ?['status'=>'measured','items'=>[[
                    'used'=>$toolData['used'],'limit'=>$toolData['limit'],
                    'source_ref'=>$tool['source_ref'],'observed_at'=>$tool['observed_at'],
                    'freshness'=>$tool['freshness'],'age_seconds'=>$tool['age_seconds'],
                ]]]
                :['status'=>'unknown','items'=>[]],
            'tool_usage'=>[
                'status'=>$hasUsage?'measured':'unknown',
                'used'=>$hasUsage?$toolData['used']:null,
                'limit'=>$hasUsage?$toolData['limit']:null,
                'source_ref'=>$hasUsage?$tool['source_ref']:null,
                'observed_at'=>$hasUsage?$tool['observed_at']:null,
                'freshness'=>$hasUsage?$tool['freshness']:'unknown',
                'age_seconds'=>$hasUsage?$tool['age_seconds']:null,
            ],
        ];
        self::safe($view);
        return $view;
    }

    private static function snapshot(array $snapshot): void
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live snapshot invalid.');
        if(!is_string($snapshot['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$snapshot['fingerprint'])!==1)
            throw new InvalidArgumentException('Factory live fingerprint invalid.');
        $canonical=$snapshot;unset($canonical['fingerprint']);
        $expected=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if(!hash_equals($expected,$snapshot['fingerprint']))
            throw new InvalidArgumentException('Factory live fingerprint mismatch.');
        self::safe($snapshot);
        if(!isset($snapshot['sections']['work'])||!is_array($snapshot['sections']['work'])||!array_is_list($snapshot['sections']['work']))
            throw new InvalidArgumentException('Factory live work invalid.');
        self::fields($snapshot['tool_usage'],['id','authority','state','source_ref','observed_at','freshness','age_seconds','data'],'tool_usage');
    }

    private static function text(mixed $value,string $label): string
    {
        if(!is_string($value)||trim($value)===''||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return trim($value);
    }

    private static function safe(mixed $value): void
    {
        if(is_array($value)){
            foreach($value as $key=>$item){
                if(is_string($key)&&preg_match(self::SENSITIVE,$key)===1)
                    throw new InvalidArgumentException('Sensitive field.');
                self::safe($item);
            }
            return;
        }
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('Sensitive value.');
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}
