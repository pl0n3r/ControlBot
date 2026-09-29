<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscoveryAssessment
{
    private const CLASSIFICATIONS=['VALIDATED','INVALIDATED','INCONCLUSIVE'];
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}|\\+?[0-9][0-9(). -]{7,}[0-9])/i';

    public static function assess(
        array $raw,
        array $experimentRaw,
        array $initiativeRaw,
        array $hypothesisRaw,
        array $outcomeRaw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        $plan=ProductDiscoveryExperiment::plan($experimentRaw,$initiativeRaw,$hypothesisRaw);
        $outcome=ProductExperimentOutcome::outcome(
            $outcomeRaw,$baselineRaw,$variantRaw,$expectedVentureId,$expectedProductId
        );

        self::fields($raw,[
            'version','assessment_ref','experiment_ref','initiative_id','hypothesis_ref',
            'primary_metric_ref','outcome_id','classification','assessment_rule_ref',
            'assessment_evidence_refs',
        ]);
        if(($raw['version']??null)!==1)
            throw new InvalidArgumentException('DiscoveryAssessment version invalid.');

        $assessmentRef=self::ref($raw['assessment_ref'],'assessment');
        $experimentRef=self::ref($raw['experiment_ref'],'experiment');
        $initiativeId=self::ref($raw['initiative_id'],'initiative');
        $hypothesisRef=self::ref($raw['hypothesis_ref'],'hypothesis');
        $primaryMetricRef=self::ref($raw['primary_metric_ref'],'metric');
        $outcomeId=self::outcomeId($raw['outcome_id']);
        $classification=self::choice($raw['classification'],self::CLASSIFICATIONS,'classification');
        $ruleRef=self::ref($raw['assessment_rule_ref'],'rule');
        $evidence=self::refs($raw['assessment_evidence_refs'],'evidence',32);

        if($experimentRef!==$plan['experiment_ref']
            ||$initiativeId!==$plan['initiative_id']
            ||$hypothesisRef!==$plan['hypothesis_ref']
            ||$primaryMetricRef!==$plan['primary_metric_ref']
            ||$outcomeId!==$outcome['outcome_id']
            ||$outcome['experiment_ref']!==$plan['experiment_ref']
            ||$outcome['evaluation_window']!==$plan['evaluation_window'])
            throw new InvalidArgumentException('DiscoveryAssessment binding mismatch.');

        if($plan['scope']!=='venture'
            ||$plan['venture_id']!==$outcome['venture_id']
            ||$outcome['venture_id']!==$expectedVentureId
            ||$outcome['product_id']!==$expectedProductId)
            throw new InvalidArgumentException('DiscoveryAssessment scope mismatch.');

        $reliable=$outcome['status']==='measured'
            &&$outcome['freshness']==='fresh'
            &&$outcome['nature']==='observed'
            &&$outcome['confidence']>0
            &&$plan['hypothesis_freshness']==='fresh'
            &&$plan['hypothesis_confidence']!=='unknown';

        if(!$reliable && $classification!=='INCONCLUSIVE')
            throw new InvalidArgumentException('DiscoveryAssessment unreliable evidence must be inconclusive.');

        $result=[
            'version'=>1,
            'assessment_ref'=>$assessmentRef,
            'experiment_ref'=>$plan['experiment_ref'],
            'initiative_id'=>$plan['initiative_id'],
            'hypothesis_ref'=>$plan['hypothesis_ref'],
            'primary_metric_ref'=>$plan['primary_metric_ref'],
            'outcome_id'=>$outcome['outcome_id'],
            'venture_id'=>$outcome['venture_id'],
            'product_id'=>$outcome['product_id'],
            'classification'=>$classification,
            'assessment_rule_ref'=>$ruleRef,
            'assessment_evidence_refs'=>$evidence,
            'evaluation_window'=>$outcome['evaluation_window'],
            'status'=>$outcome['status'],
            'delta'=>$outcome['delta'],
            'comparison'=>$outcome['comparison'],
            'nature'=>$outcome['nature'],
            'freshness'=>$outcome['freshness'],
            'confidence'=>$outcome['confidence'],
            'source_ref'=>$outcome['source_ref'],
            'evidence_ref'=>$outcome['evidence_ref'],
        ];
        self::safe($result);
        return $result;
    }

    private static function ref(mixed $value,string $namespace): string
    {
        if(!is_string($value)
            ||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($namespace.' ref invalid.');
        return $value;
    }

    private static function refs(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $out=[];
        foreach($values as $value){
            $value=self::ref($value,$namespace);
            if(isset($out[$value])) throw new InvalidArgumentException($namespace.' ref duplicated.');
            $out[$value]=true;
        }
        $refs=array_keys($out);
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function outcomeId(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^outcome-[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException('outcome_id invalid.');
        return $value;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row))
            throw new InvalidArgumentException('DiscoveryAssessment invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)
            throw new InvalidArgumentException('DiscoveryAssessment fields invalid.');
    }

    private static function safe(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::safe($item);return;}
        if(is_string($value)
            &&preg_match('/^(?:assessment|experiment|initiative|hypothesis|metric|rule|evidence):[a-f0-9]{32}$/D',$value)!==1
            &&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1))
            throw new InvalidArgumentException('DiscoveryAssessment contains sensitive material.');
    }
}
