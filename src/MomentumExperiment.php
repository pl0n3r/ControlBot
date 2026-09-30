<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumExperiment
{
    private const STATUSES=['draft','ready','running','completed','cancelled'];
    private const RESULT_STATES=['observed','inferred','unknown'];
    private const FRESHNESS=['current','stale','unknown'];

    public static function experiment(array $raw,array $campaignRaw,array $brandRaw): array
    {
        $campaign=MomentumCampaign::campaign($campaignRaw,$brandRaw);
        self::fields($raw,[
            'version','experiment_id','venture_id','campaign_id','hypothesis_ref',
            'baseline_ref','variant_refs','metric_ref','window','status','result',
            'evidence_refs','execution',
        ],'MomentumExperiment');
        if(($raw['version']??null)!==1||($raw['execution']??null)!==false)
            throw new InvalidArgumentException('MomentumExperiment version or execution invalid.');

        $venture=self::venture($raw['venture_id']);
        $campaignId=self::opaque($raw['campaign_id'],'campaign');
        if($venture!==$campaign['venture_id']||$campaignId!==$campaign['campaign_id'])
            throw new InvalidArgumentException('MomentumExperiment campaign scope mismatch.');

        $status=self::enumValue($raw['status'],self::STATUSES,'status');
        $hypothesis=self::nullableOpaque($raw['hypothesis_ref'],'hypothesis');
        $baseline=self::nullableOpaque($raw['baseline_ref'],'creative');
        $variants=self::opaqueList($raw['variant_refs'],'creative',25);
        $metric=self::nullableOpaque($raw['metric_ref'],'metric');
        $window=self::window($raw['window']);

        if(in_array($status,['ready','running','completed'],true)
            &&($hypothesis===null||$baseline===null||$variants===[]||$metric===null
                ||$window['start_at']===null||$window['end_at']===null))
            throw new InvalidArgumentException('Experiment readiness contract incomplete.');

        $campaignCreatives=$campaign['creative_variant_refs'];
        if($baseline!==null&&!in_array($baseline,$campaignCreatives,true))
            throw new InvalidArgumentException('Experiment baseline outside Campaign.');
        foreach($variants as $variant){
            if(!in_array($variant,$campaignCreatives,true))
                throw new InvalidArgumentException('Experiment variant outside Campaign.');
        }
        if($baseline!==null&&in_array($baseline,$variants,true))
            throw new InvalidArgumentException('Experiment baseline duplicated as variant.');

        $arms=$variants;
        if($baseline!==null) $arms[]=$baseline;
        $result=self::result($raw['result'],$arms);
        if($status!=='completed'&&$result['state']!=='unknown')
            throw new InvalidArgumentException('Experiment result before completion invalid.');

        return [
            'version'=>1,
            'experiment_id'=>self::opaque($raw['experiment_id'],'experiment'),
            'venture_id'=>$venture,
            'campaign_id'=>$campaignId,
            'hypothesis_ref'=>$hypothesis,
            'baseline_ref'=>$baseline,
            'variant_refs'=>$variants,
            'metric_ref'=>$metric,
            'window'=>$window,
            'status'=>$status,
            'result'=>$result,
            'evidence_refs'=>self::opaqueList($raw['evidence_refs'],'evidence',32),
            'execution'=>false,
        ];
    }

    private static function result(mixed $raw,array $arms): array
    {
        self::fields($raw,[
            'state','winner_ref','effect_bps','source_ref','observed_at','freshness','evidence_refs',
        ],'ExperimentResult');
        $state=self::enumValue($raw['state'],self::RESULT_STATES,'result.state');
        $freshness=self::enumValue($raw['freshness'],self::FRESHNESS,'result.freshness');
        $winner=self::nullableOpaque($raw['winner_ref'],'creative');
        $effect=$raw['effect_bps'];
        $source=self::nullableOpaque($raw['source_ref'],'source');
        $observedAt=$raw['observed_at'];
        $evidence=self::opaqueList($raw['evidence_refs'],'evidence',32);

        if($state==='unknown'){
            if($winner!==null||$effect!==null||$source!==null||$observedAt!==null
                ||$freshness!=='unknown'||$evidence!==[])
                throw new InvalidArgumentException('Unknown experiment result must not imply evidence.');
        }else{
            if($source===null||!is_int($observedAt)||$observedAt<0
                ||$freshness==='unknown'||$evidence===[])
                throw new InvalidArgumentException('Experiment result evidence incomplete.');
            if(($winner===null)!==($effect===null)
                ||($effect!==null&&(!is_int($effect)||$effect < -10000||$effect > 100000)))
                throw new InvalidArgumentException('Experiment effect invalid.');
            if($winner!==null&&!in_array($winner,$arms,true))
                throw new InvalidArgumentException('Experiment winner outside arms.');
        }
        return [
            'state'=>$state,'winner_ref'=>$winner,'effect_bps'=>$effect,
            'source_ref'=>$source,'observed_at'=>$observedAt,
            'freshness'=>$freshness,'evidence_refs'=>$evidence,
        ];
    }

    private static function window(mixed $raw): array
    {
        self::fields($raw,['start_at','end_at'],'ExperimentWindow');
        $start=$raw['start_at'];$end=$raw['end_at'];
        foreach([$start,$end] as $value)
            if($value!==null&&(!is_int($value)||$value<0))
                throw new InvalidArgumentException('Experiment window invalid.');
        if(($start===null)!==($end===null)||($start!==null&&$start>=$end))
            throw new InvalidArgumentException('Experiment window invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }

    private static function opaqueList(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $out=[];
        foreach($values as $value){
            $ref=self::opaque($value,$namespace);
            if(isset($out[$ref])) throw new InvalidArgumentException($namespace.' ref duplicated.');
            $out[$ref]=true;
        }
        $refs=array_keys($out);sort($refs,SORT_STRING);return $refs;
    }

    private static function opaque(mixed $value,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($namespace.' ref invalid.');
        return $value;
    }

    private static function nullableOpaque(mixed $value,string $namespace): ?string
    {
        return $value===null?null:self::opaque($value,$namespace);
    }

    private static function venture(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
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
        $actual=array_keys($row);sort($actual);sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
