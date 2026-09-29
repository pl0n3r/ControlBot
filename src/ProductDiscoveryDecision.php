<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscoveryDecision
{
    private const DECISIONS=['BUILD','ITERATE','PARK','STOP','RESEARCH_MORE'];
    private const BUILD_GATES=['authority','budget','aegis','lex'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?[0-9][0-9(). -]{7,}[0-9])/i';

    public static function decide(
        array $raw,
        array $assessmentRaw,
        array $experimentRaw,
        array $initiativeRaw,
        array $hypothesisRaw,
        array $outcomeRaw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        $assessment=ProductDiscoveryAssessment::assess(
            $assessmentRaw,$experimentRaw,$initiativeRaw,$hypothesisRaw,
            $outcomeRaw,$baselineRaw,$variantRaw,$expectedVentureId,$expectedProductId
        );
        self::fields($raw,[
            'version','decision_ref','assessment_ref','experiment_ref','initiative_id',
            'hypothesis_ref','decision','decision_reason_ref','decision_evidence_refs',
        ]);
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('DiscoveryDecision version invalid.');

        $decision=self::choice($raw['decision'],self::DECISIONS,'decision');
        $assessmentRef=self::ref($raw['assessment_ref'],'assessment');
        $experimentRef=self::ref($raw['experiment_ref'],'experiment');
        $initiativeId=self::ref($raw['initiative_id'],'initiative');
        $hypothesisRef=self::ref($raw['hypothesis_ref'],'hypothesis');
        if($assessmentRef!==$assessment['assessment_ref']
            ||$experimentRef!==$assessment['experiment_ref']
            ||$initiativeId!==$assessment['initiative_id']
            ||$hypothesisRef!==$assessment['hypothesis_ref'])
            throw new InvalidArgumentException('DiscoveryDecision binding mismatch.');
        if($decision==='BUILD'&&$assessment['classification']!=='VALIDATED')
            throw new InvalidArgumentException('BUILD requires validated assessment.');

        $result=[
            'version'=>1,
            'decision_ref'=>self::ref($raw['decision_ref'],'decision'),
            'assessment_ref'=>$assessmentRef,
            'experiment_ref'=>$experimentRef,
            'initiative_id'=>$initiativeId,
            'hypothesis_ref'=>$hypothesisRef,
            'venture_id'=>$assessment['venture_id'],
            'product_id'=>$assessment['product_id'],
            'classification'=>$assessment['classification'],
            'decision'=>$decision,
            'decision_reason_ref'=>self::ref($raw['decision_reason_ref'],'reason'),
            'decision_evidence_refs'=>self::refs($raw['decision_evidence_refs'],'evidence',32),
            'assessment_rule_ref'=>$assessment['assessment_rule_ref'],
            'assessment_evidence_refs'=>$assessment['assessment_evidence_refs'],
            'evaluation_window'=>$assessment['evaluation_window'],
            'freshness'=>$assessment['freshness'],
            'confidence'=>$assessment['confidence'],
            'source_ref'=>$assessment['source_ref'],
            'evidence_ref'=>$assessment['evidence_ref'],
            'required_gates'=>$decision==='BUILD'?self::BUILD_GATES:[],
            'factory_handoff_ready'=>false,
            'execution'=>false,
        ];
        self::safe($result);
        return $result;
    }

    private static function ref(mixed $value,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($namespace.' ref invalid.');
        return $value;
    }

    private static function refs(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $seen=[];
        foreach($values as $value){
            $value=self::ref($value,$namespace);
            if(isset($seen[$value])) throw new InvalidArgumentException($namespace.' ref duplicated.');
            $seen[$value]=true;
        }
        $out=array_keys($seen);sort($out,SORT_STRING);return $out;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('DiscoveryDecision invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('DiscoveryDecision fields invalid.');
    }

    private static function safe(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item)self::safe($item);return;}
        if(is_string($value)
            &&preg_match('/^(?:decision|assessment|experiment|initiative|hypothesis|reason|rule|evidence):[a-f0-9]{32}$/D',$value)!==1
            &&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1))
            throw new InvalidArgumentException('DiscoveryDecision contains sensitive material.');
    }
}
