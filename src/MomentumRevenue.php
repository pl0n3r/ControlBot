<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumRevenue
{
    private const STAGES=['lead','qualified','opportunity','proposal','won','lost','disqualified'];
    private const QUALIFICATIONS=['unknown','qualified','disqualified'];
    private const FRESHNESS=['current','stale','unknown'];
    private const ATTRIBUTION=['observed','inferred','unknown'];
    private const SIGNAL_KINDS=['renewal','upsell','churn'];

    public static function pipeline(array $raw): array
    {
        self::fields($raw,[
            'version','pipeline_id','venture_id','lead_ref','opportunity_ref','source_ref','campaign_ref',
            'creative_ref','owner_ref','stage','qualification','next_action_ref','observed_at','freshness',
            'customer_success_handoff_ref',
        ],'RevenuePipeline');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenuePipeline version invalid.');

        $stage=self::enumValue($raw['stage'],self::STAGES,'stage');
        $qualification=self::enumValue($raw['qualification'],self::QUALIFICATIONS,'qualification');
        $opportunity=self::nullableOpaque($raw['opportunity_ref'],'opportunity');
        $handoff=self::nullableOpaque($raw['customer_success_handoff_ref'],'customer-success');

        if(in_array($stage,['qualified','opportunity','proposal','won','lost'],true) && $qualification!=='qualified')
            throw new InvalidArgumentException('RevenuePipeline qualified stage mismatch.');
        if($stage==='disqualified' && $qualification!=='disqualified')
            throw new InvalidArgumentException('RevenuePipeline disqualified stage mismatch.');
        if($qualification==='disqualified' && $stage!=='disqualified')
            throw new InvalidArgumentException('RevenuePipeline qualification mismatch.');
        if(in_array($stage,['opportunity','proposal','won','lost'],true) && $opportunity===null)
            throw new InvalidArgumentException('RevenuePipeline opportunity required.');
        if($stage==='won' && $handoff===null)
            throw new InvalidArgumentException('Won revenue requires Customer Success handoff.');
        if($stage!=='won' && $handoff!==null)
            throw new InvalidArgumentException('Customer Success handoff only applies after won.');

        return [
            'version'=>1,
            'pipeline_id'=>self::opaque($raw['pipeline_id'],'pipeline'),
            'venture_id'=>self::venture($raw['venture_id']),
            'lead_ref'=>self::opaque($raw['lead_ref'],'lead'),
            'opportunity_ref'=>$opportunity,
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'campaign_ref'=>self::nullableOpaque($raw['campaign_ref'],'campaign'),
            'creative_ref'=>self::nullableOpaque($raw['creative_ref'],'creative'),
            'owner_ref'=>self::opaque($raw['owner_ref'],'owner'),
            'stage'=>$stage,
            'qualification'=>$qualification,
            'next_action_ref'=>self::nullableOpaque($raw['next_action_ref'],'action'),
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
            'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'customer_success_handoff_ref'=>$handoff,
        ];
    }

    public static function forecast(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::fields($raw,[
            'version','forecast_id','venture_id','opportunity_ref','amount_minor','currency','confidence',
            'source_ref','observed_at','freshness',
        ],'RevenueForecast');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueForecast version invalid.');

        $venture=self::venture($raw['venture_id']);
        $opportunity=self::opaque($raw['opportunity_ref'],'opportunity');
        if($pipeline['opportunity_ref']===null || $venture!==$pipeline['venture_id'] || $opportunity!==$pipeline['opportunity_ref'])
            throw new InvalidArgumentException('RevenueForecast scope mismatch.');

        return [
            'version'=>1,
            'forecast_id'=>self::opaque($raw['forecast_id'],'forecast'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'amount_minor'=>self::amount($raw['amount_minor'],'amount_minor'),
            'currency'=>self::currency($raw['currency']),
            'confidence'=>self::confidence($raw['confidence']),
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
            'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'classification'=>'forecast',
            'demonstrated_revenue'=>false,
        ];
    }

    public static function attribution(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::fields($raw,[
            'version','attribution_id','venture_id','opportunity_ref','campaign_ref','creative_ref','classification',
            'amount_minor','currency','source_ref','evidence_refs','observed_at','freshness',
        ],'RevenueAttribution');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueAttribution version invalid.');

        $venture=self::venture($raw['venture_id']);
        $opportunity=self::opaque($raw['opportunity_ref'],'opportunity');
        if($pipeline['opportunity_ref']===null || $venture!==$pipeline['venture_id'] || $opportunity!==$pipeline['opportunity_ref'])
            throw new InvalidArgumentException('RevenueAttribution scope mismatch.');

        $classification=self::enumValue($raw['classification'],self::ATTRIBUTION,'classification');
        $amount=$raw['amount_minor']===null?null:self::amount($raw['amount_minor'],'amount_minor');
        $evidence=self::opaqueList($raw['evidence_refs'],'evidence',32);
        if($classification==='unknown' && $amount!==null)
            throw new InvalidArgumentException('Unknown attribution cannot claim revenue amount.');
        if(in_array($classification,['observed','inferred'],true) && $amount===null)
            throw new InvalidArgumentException('Known attribution requires amount.');
        if($classification==='observed' && $evidence===[])
            throw new InvalidArgumentException('Observed attribution requires evidence.');

        return [
            'version'=>1,
            'attribution_id'=>self::opaque($raw['attribution_id'],'attribution'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'campaign_ref'=>self::nullableOpaque($raw['campaign_ref'],'campaign'),
            'creative_ref'=>self::nullableOpaque($raw['creative_ref'],'creative'),
            'classification'=>$classification,
            'amount_minor'=>$amount,
            'currency'=>self::currency($raw['currency']),
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'evidence_refs'=>$evidence,
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
            'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'demonstrated_amount_minor'=>$classification==='observed'?$amount:null,
        ];
    }

    public static function lifecycleSignal(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::fields($raw,[
            'version','signal_id','venture_id','opportunity_ref','kind','classification','source_ref','observed_at',
            'freshness','product_intelligence_ref','customer_success_ref',
        ],'RevenueLifecycleSignal');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueLifecycleSignal version invalid.');

        $venture=self::venture($raw['venture_id']);
        $opportunity=self::opaque($raw['opportunity_ref'],'opportunity');
        if($pipeline['opportunity_ref']===null || $venture!==$pipeline['venture_id'] || $opportunity!==$pipeline['opportunity_ref'])
            throw new InvalidArgumentException('RevenueLifecycleSignal scope mismatch.');

        $productRef=self::nullableOpaque($raw['product_intelligence_ref'],'product-intelligence');
        $successRef=self::nullableOpaque($raw['customer_success_ref'],'customer-success');
        if($productRef===null && $successRef===null)
            throw new InvalidArgumentException('Lifecycle signal requires a domain handoff reference.');

        return [
            'version'=>1,
            'signal_id'=>self::opaque($raw['signal_id'],'signal'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'kind'=>self::enumValue($raw['kind'],self::SIGNAL_KINDS,'kind'),
            'classification'=>self::enumValue($raw['classification'],self::ATTRIBUTION,'classification'),
            'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
            'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'product_intelligence_ref'=>$productRef,
            'customer_success_ref'=>$successRef,
        ];
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

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function amount(mixed $value,string $label): int
    {
        $amount=self::nonNegativeInt($value,$label);
        if($amount>1_000_000_000_000_000) throw new InvalidArgumentException($label.' exceeds limit.');
        return $amount;
    }

    private static function confidence(mixed $value): int
    {
        if(!is_int($value)||$value<0||$value>100) throw new InvalidArgumentException('confidence invalid.');
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[A-Z]{3}$/D',$value)!==1)
            throw new InvalidArgumentException('currency invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual);sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
