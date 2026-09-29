<?php
declare(strict_types=1);

namespace ControlBot\CustomerSuccess;

use InvalidArgumentException;

final class CustomerFeedbackSignal
{
    private const TARGETS=['discovery','product_intelligence'];
    private const ORIGINS=[
        'health_exception','onboarding_friction','renewal_signal',
        'satisfaction_signal','support_pattern',
    ];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const NATURE=['observed','inferred','unknown'];
    private const OUTPUT_FIELDS=[
        'version','feedback_ref','venture_id','product_ref','origin','targets',
        'source_ref','evidence_refs','observed_at','freshness','confidence','nature',
    ];

    public static function fromSupport(
        array $raw,
        array $supportRaw,
        string $expectedVentureId
    ): array {
        $support=CustomerSuccessCore::supportSignal($supportRaw,$expectedVentureId);
        if($support['pattern_ref']===null)
            throw new InvalidArgumentException('Support feedback requires pattern_ref.');

        $known=$support['freshness']!=='unknown';
        return self::envelope(
            $raw,
            $support['venture_id'],
            $support['product_ref'],
            'support_pattern',
            'customer-success:support/'.$support['signal_id'],
            self::refs([$support['evidence_ref']]),
            $known?$support['observed_at']:null,
            $support['freshness'],
            null,
            $known?'observed':'unknown'
        );
    }

    public static function fromHealthDimension(
        array $raw,
        array $snapshotRaw,
        string $dimensionName,
        string $expectedVentureId
    ): array {
        $snapshot=CustomerSuccessCore::snapshot($snapshotRaw,$expectedVentureId);
        $dimension=self::dimension($snapshot['dimensions'],$dimensionName);
        $origin=match($dimension['name']){
            'onboarding'=>'onboarding_friction',
            'renewal_signal'=>'renewal_signal',
            'satisfaction'=>'satisfaction_signal',
            default=>'health_exception',
        };
        $unknown=$dimension['status']==='unknown'||$dimension['freshness']==='unknown';
        return self::envelope(
            $raw,
            $snapshot['venture_id'],
            $snapshot['product_ref'],
            $origin,
            'customer-success:snapshot/'.$snapshot['snapshot_id'].'/'.$dimension['name'],
            self::refs([$dimension['evidence_ref']]),
            $unknown?null:$snapshot['observed_at'],
            $dimension['freshness'],
            $dimension['confidence'],
            $unknown?'unknown':$dimension['nature']
        );
    }

    public static function collection(array $signals): array
    {
        if(!array_is_list($signals)||$signals===[]||count($signals)>100)
            throw new InvalidArgumentException('feedback collection invalid.');

        $out=[];$seen=[];
        foreach($signals as $signal){
            self::fields($signal,self::OUTPUT_FIELDS,'CustomerFeedbackSignal');
            $ref=self::opaqueRef($signal['feedback_ref'],'feedback_ref','feedback');
            if(isset($seen[$ref])) throw new InvalidArgumentException('feedback_ref duplicated.');
            $seen[$ref]=true;
            $normalized=self::validateEnvelope($signal);
            $out[]=$normalized;
        }
        usort($out,static fn(array $a,array $b): int=>$a['feedback_ref']<=>$b['feedback_ref']);
        return $out;
    }

    private static function envelope(
        array $raw,
        string $ventureId,
        string $productRef,
        string $origin,
        string $sourceRef,
        array $evidenceRefs,
        ?int $observedAt,
        string $freshness,
        ?float $confidence,
        string $nature
    ): array {
        self::fields($raw,['version','feedback_ref','targets'],'CustomerFeedbackSignal input');
        if(($raw['version']??null)!==1)
            throw new InvalidArgumentException('CustomerFeedbackSignal version invalid.');

        $targets=self::targets($raw['targets']);
        if(in_array('product_intelligence',$targets,true) && $productRef==='')
            throw new InvalidArgumentException('product_intelligence requires product_ref.');

        $freshness=self::enumValue($freshness,self::FRESHNESS,'freshness');
        $nature=self::enumValue($nature,self::NATURE,'nature');
        if($freshness==='unknown' && ($observedAt!==null||$evidenceRefs!==[]||$nature!=='unknown'))
            throw new InvalidArgumentException('Unknown feedback must fail closed.');
        if($freshness!=='unknown' && ($observedAt===null||$evidenceRefs===[]))
            throw new InvalidArgumentException('Known feedback requires observed evidence.');
        if($confidence!==null && (!is_finite($confidence)||$confidence<0.0||$confidence>1.0))
            throw new InvalidArgumentException('confidence invalid.');

        return self::validateEnvelope([
            'version'=>1,
            'feedback_ref'=>self::opaqueRef($raw['feedback_ref'],'feedback_ref','feedback'),
            'venture_id'=>$ventureId,
            'product_ref'=>$productRef,
            'origin'=>self::enumValue($origin,self::ORIGINS,'origin'),
            'targets'=>$targets,
            'source_ref'=>$sourceRef,
            'evidence_refs'=>$evidenceRefs,
            'observed_at'=>$observedAt,
            'freshness'=>$freshness,
            'confidence'=>$confidence,
            'nature'=>$nature,
        ]);
    }

    private static function validateEnvelope(array $signal): array
    {
        self::fields($signal,self::OUTPUT_FIELDS,'CustomerFeedbackSignal');
        if(($signal['version']??null)!==1)
            throw new InvalidArgumentException('CustomerFeedbackSignal version invalid.');

        $feedbackRef=self::opaqueRef($signal['feedback_ref'],'feedback_ref','feedback');
        $venture=self::venture($signal['venture_id']);
        $product=self::opaqueRef($signal['product_ref'],'product_ref','product');
        $origin=self::enumValue($signal['origin'],self::ORIGINS,'origin');
        $targets=self::targets($signal['targets']);
        $source=self::sourceRef($signal['source_ref'],$origin);
        $evidence=self::refs($signal['evidence_refs']);
        $freshness=self::enumValue($signal['freshness'],self::FRESHNESS,'freshness');
        $nature=self::enumValue($signal['nature'],self::NATURE,'nature');
        $observed=self::nullableTimestamp($signal['observed_at'],'observed_at');
        $confidence=self::nullableConfidence($signal['confidence']);

        if($freshness==='unknown' && ($observed!==null||$evidence!==[]||$nature!=='unknown'))
            throw new InvalidArgumentException('Unknown feedback must fail closed.');
        if($freshness!=='unknown' && ($observed===null||$evidence===[]||$nature==='unknown'))
            throw new InvalidArgumentException('Known feedback requires observed evidence.');
        if(in_array('product_intelligence',$targets,true) && $product==='')
            throw new InvalidArgumentException('product_intelligence requires product_ref.');

        return [
            'version'=>1,
            'feedback_ref'=>$feedbackRef,
            'venture_id'=>$venture,
            'product_ref'=>$product,
            'origin'=>$origin,
            'targets'=>$targets,
            'source_ref'=>$source,
            'evidence_refs'=>$evidence,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
            'confidence'=>$confidence,
            'nature'=>$nature,
        ];
    }

    private static function dimension(array $dimensions,string $name): array
    {
        foreach($dimensions as $dimension){
            if($dimension['name']===$name) return $dimension;
        }
        throw new InvalidArgumentException('health dimension invalid.');
    }

    private static function targets(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value)||$value===[]||count($value)>2)
            throw new InvalidArgumentException('targets invalid.');
        $out=[];
        foreach($value as $target){
            $target=self::enumValue($target,self::TARGETS,'target');
            if(in_array($target,$out,true)) throw new InvalidArgumentException('target duplicated.');
            $out[]=$target;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function refs(array $values): array
    {
        $out=[];
        foreach($values as $value){
            if($value===null) continue;
            $ref=self::opaqueRef($value,'evidence_ref','evidence');
            if(in_array($ref,$out,true)) throw new InvalidArgumentException('evidence_ref duplicated.');
            $out[]=$ref;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function venture(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
        return $value;
    }

    private static function sourceRef(mixed $value,string $origin): string
    {
        if(!is_string($value)) throw new InvalidArgumentException('source_ref invalid.');
        $support='/^customer-success:support\/support:[a-f0-9]{32}$/D';
        $snapshot='/^customer-success:snapshot\/snapshot:[a-f0-9]{32}\/(?:activation|adoption|churn_risk|onboarding|reliability_impact|renewal_signal|satisfaction|support_burden|usage_recency)$/D';
        if($origin==='support_pattern' && preg_match($support,$value)===1) return $value;
        if($origin!=='support_pattern' && preg_match($snapshot,$value)===1) return $value;
        throw new InvalidArgumentException('source_ref invalid for origin.');
    }

    private static function nullableTimestamp(mixed $value,string $label): ?int
    {
        if($value===null) return null;
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableConfidence(mixed $value): ?float
    {
        if($value===null) return null;
        if(!is_int($value)&&!is_float($value))
            throw new InvalidArgumentException('confidence invalid.');
        $number=(float)$value;
        if(!is_finite($number)||$number<0.0||$number>1.0)
            throw new InvalidArgumentException('confidence invalid.');
        return $number;
    }

    private static function opaqueRef(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_fill_keys(array_keys($row),true);
        $wanted=array_fill_keys($expected,true);
        if(count($actual)!==count($wanted)||$actual!=$wanted)
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}
