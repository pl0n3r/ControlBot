<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductLearningSignal
{
    private const TARGETS=['capital','customer_success','discovery','momentum'];
    private const TYPES=['evidence_gap','experiment_result','inferred_change','observed_change','revenue_outcome'];

    public static function fromMetric(
        array $raw,
        array $metricRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        $metric=ProductIntelligence::metric($metricRaw,$expectedVentureId,$expectedProductId);
        $type=self::signalInput($raw);

        $expectedType=match(true){
            $metric['status']!=='measured'=>'evidence_gap',
            $metric['category']==='revenue_outcome'=>'revenue_outcome',
            $metric['nature']==='inferred'=>'inferred_change',
            default=>'observed_change',
        };
        if($type!==$expectedType)
            throw new InvalidArgumentException('Signal type does not match metric evidence.');

        return self::envelope(
            $raw,
            $type,
            'metric',
            'product-intelligence:metric/'.$metric['metric_id'],
            $metric['venture_id'],
            $metric['product_id'],
            $metric['surface'],
            $metric['status'],
            $metric['source_ref'],
            $metric['evidence_ref'],
            $metric['freshness'],
            $metric['confidence'],
            $metric['nature'],
            $metric['category']
        );
    }

    public static function fromExperimentOutcome(
        array $raw,
        array $outcomeRaw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        $outcome=ProductExperimentOutcome::outcome(
            $outcomeRaw,
            $baselineRaw,
            $variantRaw,
            $expectedVentureId,
            $expectedProductId
        );
        $type=self::signalInput($raw);
        if($type!=='experiment_result')
            throw new InvalidArgumentException('Experiment outcome requires experiment_result signal type.');

        return self::envelope(
            $raw,
            $type,
            'experiment_outcome',
            'product-intelligence:experiment-outcome/'.$outcome['outcome_id'],
            $outcome['venture_id'],
            $outcome['product_id'],
            $outcome['surface'],
            $outcome['status'],
            $outcome['source_ref'],
            $outcome['evidence_ref'],
            $outcome['freshness'],
            $outcome['confidence'],
            $outcome['nature'],
            $outcome['category']
        );
    }

    private static function signalInput(array $raw): string
    {
        $keys=array_keys($raw);
        sort($keys,SORT_STRING);
        if($keys!==['signal_id','targets','type','version'] || $raw['version']!==1)
            throw new InvalidArgumentException('Learning signal fields invalid.');

        if(!is_string($raw['signal_id'])
            ||preg_match('/^signal-[a-z0-9][a-z0-9-]{1,79}$/D',$raw['signal_id'])!==1)
            throw new InvalidArgumentException('signal_id invalid.');

        if(!is_string($raw['type']) || !in_array($raw['type'],self::TYPES,true))
            throw new InvalidArgumentException('signal type invalid.');

        self::targets($raw['targets']);
        return $raw['type'];
    }

    private static function envelope(
        array $raw,
        string $type,
        string $sourceKind,
        string $aggregateRef,
        string $ventureId,
        string $productId,
        string $surface,
        string $status,
        string $sourceRef,
        string $evidenceRef,
        string $freshness,
        float $confidence,
        string $nature,
        string $category
    ): array {
        return [
            'version'=>1,
            'signal_id'=>$raw['signal_id'],
            'venture_id'=>$ventureId,
            'product_id'=>$productId,
            'surface'=>$surface,
            'type'=>$type,
            'targets'=>self::targets($raw['targets']),
            'source_kind'=>$sourceKind,
            'aggregate_ref'=>$aggregateRef,
            'category'=>$category,
            'status'=>$status,
            'source_ref'=>$sourceRef,
            'evidence_ref'=>$evidenceRef,
            'freshness'=>$freshness,
            'confidence'=>$confidence,
            'nature'=>$nature,
        ];
    }

    private static function targets(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value)||$value===[]||count($value)>4)
            throw new InvalidArgumentException('targets invalid.');

        $targets=[];
        foreach($value as $target){
            if(!is_string($target)||!in_array($target,self::TARGETS,true))
                throw new InvalidArgumentException('target invalid.');
            if(in_array($target,$targets,true))
                throw new InvalidArgumentException('target duplicated.');
            $targets[]=$target;
        }
        sort($targets,SORT_STRING);
        return $targets;
    }
}
