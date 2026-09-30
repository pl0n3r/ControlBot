<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumPerformance
{
    private const FRESHNESS=['current','stale','unknown'];
    private const CLASSIFICATION=['observed','inferred','unknown'];

    public static function project(
        array $raw,
        array $pipelineRaw,
        array $attributionRaw,
    ): array {
        self::fields($raw,[
            'version','performance_id','venture_id','campaign_ref','paid_media_ref','period','currency',
            'spend','funnel','cost','source_ref','evidence_refs','observed_at','freshness','execution',
        ],'MomentumPerformance');
        if($raw['version']!==1||$raw['execution']!==false)
            throw new InvalidArgumentException('MomentumPerformance version or execution invalid.');

        $performanceId=self::opaque($raw['performance_id'],'performance');
        $venture=self::venture($raw['venture_id']);
        $campaign=self::opaque($raw['campaign_ref'],'campaign');
        $paidMedia=self::opaque($raw['paid_media_ref'],'paid-media');
        $period=self::period($raw['period']);
        $currency=self::currency($raw['currency']);
        $spend=self::moneyObservation($raw['spend'],'SpendObservation',['observed','unknown']);
        $funnel=self::funnelObservation($raw['funnel']);
        $cost=self::moneyObservation($raw['cost'],'CostObservation',['observed','unknown']);
        $source=self::opaque($raw['source_ref'],'source');
        $evidence=self::refs($raw['evidence_refs'],'evidence',64);
        if($evidence===[]) throw new InvalidArgumentException('MomentumPerformance evidence required.');
        $observedAt=self::natural($raw['observed_at'],'observed_at');
        $freshness=self::choice($raw['freshness'],self::FRESHNESS,'freshness');

        foreach([$observedAt,$spend['observed_at'],$funnel['observed_at'],$cost['observed_at']] as $time)
            self::within($time,$period);
        if($spend['currency']!==$currency||$cost['currency']!==$currency)
            throw new InvalidArgumentException('MomentumPerformance currency mismatch.');

        $pipeline=MomentumRevenue::pipeline($pipelineRaw);
        $attribution=MomentumRevenue::attribution($attributionRaw,$pipelineRaw);
        if($pipeline['venture_id']!==$venture||$attribution['venture_id']!==$venture)
            throw new InvalidArgumentException('MomentumPerformance venture scope mismatch.');
        if($pipeline['campaign_ref']!==$campaign||$attribution['campaign_ref']!==$campaign)
            throw new InvalidArgumentException('MomentumPerformance campaign scope mismatch.');
        if($attribution['currency']!==$currency)
            throw new InvalidArgumentException('MomentumPerformance attribution currency mismatch.');
        self::within($attribution['observed_at'],$period);

        $current=$freshness==='current';
        $spendKnown=$current&&$spend['classification']==='observed'&&$spend['freshness']==='current';
        $funnelKnown=$current&&$funnel['classification']!=='unknown'&&$funnel['freshness']==='current';
        $costKnown=$current&&$cost['classification']==='observed'&&$cost['freshness']==='current';
        $revenueObserved=$current&&$attribution['classification']==='observed'&&$attribution['freshness']==='current';
        $revenueInferred=$current&&$attribution['classification']==='inferred'&&$attribution['freshness']==='current';

        $spendMinor=$spendKnown?$spend['amount_minor']:null;
        $leads=$funnelKnown?$funnel['leads']:null;
        $conversions=$funnelKnown?$funnel['conversions']:null;
        $observedRevenue=$revenueObserved?$attribution['demonstrated_amount_minor']:null;
        $inferredRevenue=$revenueInferred?$attribution['amount_minor']:null;
        $costMinor=$costKnown?$cost['amount_minor']:null;
        $margin=($spendMinor!==null&&$costMinor!==null&&$observedRevenue!==null)
            ?$observedRevenue-$spendMinor-$costMinor:null;

        $cac=($spendMinor!==null&&$conversions!==null&&$conversions>0)
            ?intdiv($spendMinor,$conversions):null;
        $cacClass=$cac===null?'unknown':($funnel['classification']==='observed'?'observed':'inferred');
        $roas=($spendMinor!==null&&$spendMinor>0&&$observedRevenue!==null)
            ?(int)floor(($observedRevenue/$spendMinor)*1000):null;

        return [
            'version'=>1,
            'performance_id'=>$performanceId,
            'venture_id'=>$venture,
            'campaign_ref'=>$campaign,
            'paid_media_ref'=>$paidMedia,
            'period'=>$period,
            'currency'=>$currency,
            'source_ref'=>$source,
            'evidence_refs'=>$evidence,
            'observed_at'=>$observedAt,
            'freshness'=>$freshness,
            'spend'=>$spend,
            'funnel'=>$funnel,
            'revenue'=>[
                'classification'=>$attribution['classification'],
                'amount_minor'=>$attribution['amount_minor'],
                'observed_amount_minor'=>$attribution['demonstrated_amount_minor'],
                'source_ref'=>$attribution['source_ref'],
                'evidence_refs'=>$attribution['evidence_refs'],
                'observed_at'=>$attribution['observed_at'],
                'freshness'=>$attribution['freshness'],
            ],
            'cost'=>$cost,
            'derived'=>[
                'spend_minor'=>$spendMinor,
                'leads'=>$leads,
                'conversions'=>$conversions,
                'observed_revenue_minor'=>$observedRevenue,
                'inferred_revenue_minor'=>$inferredRevenue,
                'cost_minor'=>$costMinor,
                'margin_minor'=>$margin,
            ],
            'unit_economics'=>[
                'cac_minor'=>$cac,
                'cac_classification'=>$cacClass,
                'roas_milli'=>$roas,
                'roas_classification'=>$roas===null?'unknown':'observed',
                'payback_days'=>null,
                'payback_classification'=>'unknown',
                'ltv_minor'=>null,
                'ltv_classification'=>'unknown',
            ],
            'execution'=>false,
        ];
    }

    private static function moneyObservation(mixed $raw,string $label,array $allowed): array
    {
        self::fields($raw,['classification','amount_minor','currency','source_ref','evidence_refs','observed_at','freshness'],$label);
        $classification=self::choice($raw['classification'],$allowed,'classification');
        $amount=$raw['amount_minor']===null?null:self::amount($raw['amount_minor'],'amount_minor');
        $evidence=self::refs($raw['evidence_refs'],'evidence',32);
        if($classification==='unknown'&&$amount!==null)
            throw new InvalidArgumentException($label.' unknown cannot claim amount.');
        if($classification!=='unknown'&&($amount===null||$evidence===[]))
            throw new InvalidArgumentException($label.' known value requires amount and evidence.');
        return [
            'classification'=>$classification,
            'amount_minor'=>$amount,
            'currency'=>self::currency($raw['currency']),
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'evidence_refs'=>$evidence,
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
        ];
    }

    private static function funnelObservation(mixed $raw): array
    {
        self::fields($raw,['classification','leads','conversions','source_ref','evidence_refs','observed_at','freshness'],'FunnelObservation');
        $classification=self::choice($raw['classification'],self::CLASSIFICATION,'classification');
        $leads=$raw['leads']===null?null:self::natural($raw['leads'],'leads');
        $conversions=$raw['conversions']===null?null:self::natural($raw['conversions'],'conversions');
        $evidence=self::refs($raw['evidence_refs'],'evidence',32);
        if($classification==='unknown'&&($leads!==null||$conversions!==null))
            throw new InvalidArgumentException('Unknown funnel cannot claim counts.');
        if($classification!=='unknown'&&($leads===null||$conversions===null||$evidence===[]))
            throw new InvalidArgumentException('Known funnel requires counts and evidence.');
        if($leads!==null&&$conversions!==null&&$conversions>$leads)
            throw new InvalidArgumentException('Funnel conversions exceed leads.');
        return [
            'classification'=>$classification,
            'leads'=>$leads,
            'conversions'=>$conversions,
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'evidence_refs'=>$evidence,
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
        ];
    }

    private static function period(mixed $raw): array
    {
        self::fields($raw,['start_at','end_at'],'PerformancePeriod');
        $start=self::natural($raw['start_at'],'start_at');
        $end=self::natural($raw['end_at'],'end_at');
        if($end<$start) throw new InvalidArgumentException('Performance period invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }

    private static function within(int $value,array $period): void
    {
        if($value<$period['start_at']||$value>$period['end_at'])
            throw new InvalidArgumentException('Evidence outside performance period.');
    }

    private static function venture(mixed $value): string
    {
        if(is_string($value)&&preg_match('/^venture-[a-z][a-z0-9-]{1,55}$/D',$value)===1) return $value;
        throw new InvalidArgumentException('venture_id invalid.');
    }

    private static function opaque(mixed $value,string $namespace): string
    {
        if(is_string($value)&&preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)===1) return $value;
        throw new InvalidArgumentException($namespace.' ref invalid.');
    }

    private static function refs(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $refs=array_map(static fn(mixed $value): string=>self::opaque($value,$namespace),$values);
        if(count(array_unique($refs,SORT_STRING))!==count($refs))
            throw new InvalidArgumentException($namespace.' ref duplicated.');
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value)&&in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function currency(mixed $value): string
    {
        if(is_string($value)&&preg_match('/^[A-Z]{3}$/D',$value)===1) return $value;
        throw new InvalidArgumentException('currency invalid.');
    }

    private static function natural(mixed $value,string $label): int
    {
        if(is_int($value)&&$value>=0) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function amount(mixed $value,string $label): int
    {
        $amount=self::natural($value,$label);
        if($amount<=1_000_000_000_000_000) return $amount;
        throw new InvalidArgumentException($label.' exceeds limit.');
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);
        if(count($actual)===count($expected)&&array_diff($actual,$expected)===[]&&array_diff($expected,$actual)===[]) return;
        throw new InvalidArgumentException($label.' fields invalid.');
    }
}
