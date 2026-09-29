<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductExperimentOutcome
{
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function outcome(
        array $raw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        $required=[
            'version','outcome_id','experiment_ref','venture_id','product_id',
            'evaluation_window','source_ref','evidence_ref',
        ];
        $keys=array_keys($raw);
        sort($keys,SORT_STRING);
        $requiredSorted=$required;
        sort($requiredSorted,SORT_STRING);
        if(array_is_list($raw) || $keys!==$requiredSorted)
            throw new InvalidArgumentException('ExperimentOutcome fields invalid.');
        if($raw['version']!==1) throw new InvalidArgumentException('ExperimentOutcome version invalid.');

        if(!is_string($raw['venture_id'])
            ||preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$raw['venture_id'])!==1
            ||!is_string($expectedVentureId)
            ||preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$expectedVentureId)!==1
            ||$raw['venture_id']!==$expectedVentureId)
            throw new InvalidArgumentException('Experiment outcome venture scope mismatch.');

        if(!is_string($raw['product_id'])
            ||preg_match('/^product-[a-z0-9][a-z0-9-]{1,79}$/D',$raw['product_id'])!==1
            ||!is_string($expectedProductId)
            ||preg_match('/^product-[a-z0-9][a-z0-9-]{1,79}$/D',$expectedProductId)!==1
            ||$raw['product_id']!==$expectedProductId)
            throw new InvalidArgumentException('Experiment outcome product scope mismatch.');

        $baseline=ProductIntelligence::metric($baselineRaw,$raw['venture_id'],$raw['product_id']);
        $variant=ProductIntelligence::metric($variantRaw,$raw['venture_id'],$raw['product_id']);
        self::assertComparable($baseline,$variant);

        $window=self::evaluationWindow($raw['evaluation_window']);
        self::assertInsideWindow($baseline['period'],$window,'baseline');
        self::assertInsideWindow($variant['period'],$window,'variant');

        $measured=$baseline['status']==='measured' && $variant['status']==='measured';
        $delta=null;
        $comparison='unknown';
        if($measured){
            $delta=$variant['value']-$baseline['value'];
            if(is_float($delta) && !is_finite($delta))
                throw new InvalidArgumentException('Experiment delta invalid.');
            $comparison=$delta>0?'higher':($delta<0?'lower':'equal');
        }

        if(!is_string($raw['outcome_id'])
            ||preg_match('/^outcome-[a-z0-9][a-z0-9-]{1,79}$/D',$raw['outcome_id'])!==1)
            throw new InvalidArgumentException('outcome_id invalid.');

        return [
            'version'=>1,
            'outcome_id'=>$raw['outcome_id'],
            'experiment_ref'=>self::opaqueExperiment($raw['experiment_ref']),
            'venture_id'=>$raw['venture_id'],
            'product_id'=>$raw['product_id'],
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
            'freshness'=>self::effectiveFreshness($baseline,$variant),
            'confidence'=>min($baseline['confidence'],$variant['confidence']),
            'source_ref'=>self::aggregateSource($raw['source_ref']),
            'evidence_ref'=>self::productEvidence($raw['evidence_ref']),
        ];
    }

    private static function assertComparable(array $baseline,array $variant): void
    {
        foreach(['venture_id','product_id','surface','category','unit'] as $dimension){
            if($baseline[$dimension]!==$variant[$dimension])
                throw new InvalidArgumentException('Baseline and variant are not comparable.');
        }
    }

    private static function effectiveFreshness(array $baseline,array $variant): string
    {
        $states=[$baseline['freshness'],$variant['freshness']];
        if($baseline['status']==='unknown' || $variant['status']==='unknown' || in_array('unknown',$states,true))
            return 'unknown';
        return in_array('stale',$states,true)?'stale':'fresh';
    }

    private static function evaluationWindow(mixed $value): array
    {
        if(!is_array($value) || array_is_list($value) || count($value)!==2
            ||!array_key_exists('start_at',$value) || !array_key_exists('end_at',$value)
            ||!is_int($value['start_at']) || !is_int($value['end_at'])
            ||$value['start_at']<0 || $value['end_at']<0 || $value['end_at']<=$value['start_at'])
            throw new InvalidArgumentException('evaluation_window invalid.');

        return ['start_at'=>$value['start_at'],'end_at'=>$value['end_at']];
    }

    private static function assertInsideWindow(array $period,array $window,string $label): void
    {
        if($period['start_at']<$window['start_at'] || $period['end_at']>$window['end_at'])
            throw new InvalidArgumentException($label.' metric outside evaluation window.');
    }

    private static function opaqueExperiment(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^experiment:[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException('experiment_ref invalid.');
        return $value;
    }

    private static function aggregateSource(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('/^aggregate:[A-Za-z0-9][A-Za-z0-9._:\\/#-]+$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('source_ref invalid.');
        return $value;
    }

    private static function productEvidence(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('/^evidence:product\\/[A-Za-z0-9][A-Za-z0-9._:\\/#-]+$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('evidence_ref invalid.');
        return $value;
    }
}
