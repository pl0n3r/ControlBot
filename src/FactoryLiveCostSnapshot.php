<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveCostSnapshot
{
    private const LIMIT=50;
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function build(
        array $snapshot,
        array $productMetrics=[],
        array $financeAttributions=[]
    ): array {
        self::snapshot($snapshot);
        $products=self::products($productMetrics);
        $costItems=self::costs($financeAttributions);
        $tool=$snapshot['tool_usage'];

        $view=[
            'version'=>1,
            'observed_at'=>$snapshot['observed_at'],
            'product_analytics'=>$products,
            'costs'=>[
                'status'=>$costItems===[]?'unknown':'measured',
                'items'=>$costItems,
            ],
            'limits'=>['status'=>'unknown','items'=>[]],
            'tool_usage'=>[
                'status'=>$tool['freshness']==='unknown'?'unknown':'measured',
                'source_ref'=>$tool['source_ref'],
                'observed_at'=>$tool['observed_at'],
                'freshness'=>$tool['freshness'],
                'age_seconds'=>$tool['age_seconds'],
                'data'=>$tool['data'],
            ],
        ];
        self::safe($view);
        return $view;
    }

    private static function products(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>self::LIMIT)
            throw new InvalidArgumentException('Product metrics invalid.');
        $out=[];
        foreach($rows as $raw){
            if(!is_array($raw))
                throw new InvalidArgumentException('Product metric invalid.');
            $metric=ProductIntelligence::metric(
                $raw,
                is_string($raw['venture_id']??null)?$raw['venture_id']:'',
                is_string($raw['product_id']??null)?$raw['product_id']:''
            );
            $out[]=[
                'metric_id'=>$metric['metric_id'],
                'venture_id'=>$metric['venture_id'],
                'product_id'=>$metric['product_id'],
                'surface'=>$metric['surface'],
                'category'=>$metric['category'],
                'status'=>$metric['status'],
                'value'=>$metric['value'],
                'unit'=>$metric['unit'],
                'sample_size'=>$metric['sample_size'],
                'source_ref'=>$metric['source_ref'],
                'evidence_ref'=>$metric['evidence_ref'],
                'observed_at'=>$metric['period']['end_at'],
                'freshness'=>$metric['freshness'],
                'confidence'=>$metric['confidence'],
                'nature'=>$metric['nature'],
            ];
        }
        usort($out,static fn(array $a,array $b):int=>
            [$a['venture_id'],$a['product_id'],$a['category'],$a['metric_id']]
            <=>
            [$b['venture_id'],$b['product_id'],$b['category'],$b['metric_id']]
        );
        return $out;
    }

    private static function costs(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>self::LIMIT)
            throw new InvalidArgumentException('Finance attributions invalid.');
        $out=[];
        foreach($rows as $raw){
            if(!is_array($raw))
                throw new InvalidArgumentException('Finance attribution invalid.');
            $cost=FinanceCostCenter::normalizeAttribution($raw);
            $out[]=['status'=>'measured']+$cost;
        }
        usort($out,static fn(array $a,array $b):int=>
            [$a['target_kind'],$a['target_id'],$a['attribution_id']]
            <=>
            [$b['target_kind'],$b['target_id'],$b['attribution_id']]
        );
        return $out;
    }

    private static function snapshot(array $snapshot): void
    {
        self::fields(
            $snapshot,
            ['version','observed_at','sections','tool_usage','fingerprint'],
            'snapshot'
        );
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])
            ||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live snapshot invalid.');
        if(!is_string($snapshot['fingerprint'])
            ||preg_match('/^[a-f0-9]{64}$/D',$snapshot['fingerprint'])!==1)
            throw new InvalidArgumentException('Factory live fingerprint invalid.');
        $canonical=$snapshot;
        unset($canonical['fingerprint']);
        $expected=hash('sha256',json_encode(
            $canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
        ));
        if(!hash_equals($expected,$snapshot['fingerprint']))
            throw new InvalidArgumentException('Factory live fingerprint mismatch.');
        self::fields(
            $snapshot['tool_usage'],
            ['id','authority','state','source_ref','observed_at','freshness','age_seconds','data'],
            'tool_usage'
        );
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
        if(!is_array($row)||array_is_list($row))
            throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);
        sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}
