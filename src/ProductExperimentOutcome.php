<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductExperimentOutcome
{
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function outcome(
        array $raw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        self::fields($raw,[
            'version','outcome_id','experiment_ref','venture_id','product_id',
            'evaluation_window','source_ref','evidence_ref',
        ],'ExperimentOutcome');
        if($raw['version']!==1) throw new InvalidArgumentException('ExperimentOutcome version invalid.');

        $venture=self::id($raw['venture_id'],'venture_id','venture-');
        $product=self::id($raw['product_id'],'product_id','product-');
        if($venture!==self::id($expectedVentureId,'expected_venture_id','venture-')
            ||$product!==self::id($expectedProductId,'expected_product_id','product-'))
            throw new InvalidArgumentException('Experiment outcome scope mismatch.');

        $baseline=ProductIntelligence::metric($baselineRaw,$venture,$product);
        $variant=ProductIntelligence::metric($variantRaw,$venture,$product);
        self::compatible($baseline,$variant);

        $window=self::window($raw['evaluation_window']);
        self::insideWindow($baseline['period'],$window,'baseline');
        self::insideWindow($variant['period'],$window,'variant');

        $measured=$baseline['status']==='measured' && $variant['status']==='measured';
        $delta=null;
        $comparison='unknown';
        if($measured){
            $delta=$variant['value']-$baseline['value'];
            if(is_float($delta) && !is_finite($delta))
                throw new InvalidArgumentException('Experiment delta invalid.');
            if($delta>0) $comparison='higher';
            elseif($delta<0) $comparison='lower';
            else $comparison='equal';
        }

        return [
            'version'=>1,
            'outcome_id'=>self::id($raw['outcome_id'],'outcome_id','outcome-'),
            'experiment_ref'=>self::experimentRef($raw['experiment_ref']),
            'venture_id'=>$venture,
            'product_id'=>$product,
            'surface'=>$baseline['surface'],
            'category'=>$baseline['category'],
            'unit'=>$baseline['unit'],
            'baseline_metric_id'=>$baseline['metric_id'],
            'variant_metric_id'=>$variant['metric_id'],
            'evaluation_window'=>$window,
            'status'=>$measured?'measured':'inconclusive',
            'delta'=>$delta,
            'comparison'=>$comparison,
            'nature'=>($baseline['nature']==='observed' && $variant['nature']==='observed')?'observed':'inferred',
            'freshness'=>self::freshness($baseline,$variant),
            'confidence'=>min($baseline['confidence'],$variant['confidence']),
            'source_ref'=>self::aggregateRef($raw['source_ref'],'source_ref'),
            'evidence_ref'=>self::evidenceRef($raw['evidence_ref']),
        ];
    }

    private static function compatible(array $baseline,array $variant): void
    {
        foreach(['venture_id','product_id','surface','category','unit'] as $field){
            if($baseline[$field]!==$variant[$field])
                throw new InvalidArgumentException('Baseline and variant are not comparable.');
        }
    }

    private static function freshness(array $baseline,array $variant): string
    {
        if($baseline['status']==='unknown' || $variant['status']==='unknown'
            ||$baseline['freshness']==='unknown' || $variant['freshness']==='unknown')
            return 'unknown';
        if($baseline['freshness']==='stale' || $variant['freshness']==='stale')
            return 'stale';
        return 'fresh';
    }

    private static function window(mixed $raw): array
    {
        self::fields($raw,['start_at','end_at'],'evaluation_window');
        $start=self::nonNegativeInt($raw['start_at'],'evaluation_window.start_at');
        $end=self::nonNegativeInt($raw['end_at'],'evaluation_window.end_at');
        if($end<=$start) throw new InvalidArgumentException('evaluation_window invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }

    private static function insideWindow(array $period,array $window,string $label): void
    {
        if($period['start_at']<$window['start_at'] || $period['end_at']>$window['end_at'])
            throw new InvalidArgumentException($label.' metric outside evaluation window.');
    }

    private static function experimentRef(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^experiment:[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException('experiment_ref invalid.');
        return $value;
    }

    private static function aggregateRef(mixed $value,string $label): string
    {
        $ref=self::ref($value,$label);
        if(!str_starts_with($ref,'aggregate:'))
            throw new InvalidArgumentException($label.' must be aggregate.');
        return $ref;
    }

    private static function evidenceRef(mixed $value): string
    {
        $ref=self::ref($value,'evidence_ref');
        if(!str_starts_with($ref,'evidence:product/'))
            throw new InvalidArgumentException('evidence_ref invalid.');
        return $ref;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<3||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label,string $prefix): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($prefix,'/').'[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row);sort($keys);sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
