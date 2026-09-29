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
        self::exactFields($raw,[
            'version','pipeline_id','venture_id','lead_ref','opportunity_ref','source_ref','campaign_ref',
            'creative_ref','owner_ref','stage','qualification','next_action_ref','observed_at','freshness',
            'customer_success_handoff_ref',
        ],'RevenuePipeline');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenuePipeline version invalid.');

        $stage=self::choice($raw['stage'],self::STAGES,'stage');
        $qualification=self::choice($raw['qualification'],self::QUALIFICATIONS,'qualification');
        $opportunity=self::nullableReference($raw['opportunity_ref'],'opportunity');
        $handoff=self::nullableReference($raw['customer_success_handoff_ref'],'customer-success');

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

        $campaign=self::nullableReference($raw['campaign_ref'],'campaign');
        $creative=self::nullableReference($raw['creative_ref'],'creative');

        return [
            'version'=>1,
            'pipeline_id'=>self::reference($raw['pipeline_id'],'pipeline'),
            'venture_id'=>self::venture($raw['venture_id']),
            'lead_ref'=>self::reference($raw['lead_ref'],'lead'),
            'opportunity_ref'=>$opportunity,
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'campaign_ref'=>$campaign,
            'creative_ref'=>$creative,
            'owner_ref'=>self::reference($raw['owner_ref'],'owner'),
            'stage'=>$stage,
            'qualification'=>$qualification,
            'next_action_ref'=>self::nullableReference($raw['next_action_ref'],'action'),
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
            'customer_success_handoff_ref'=>$handoff,
        ];
    }

    public static function forecast(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::exactFields($raw,[
            'version','forecast_id','venture_id','opportunity_ref','amount_minor','currency','confidence',
            'source_ref','observed_at','freshness',
        ],'RevenueForecast');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueForecast version invalid.');

        [$venture,$opportunity]=self::pipelineScope($raw,$pipeline,'RevenueForecast');

        return [
            'version'=>1,
            'forecast_id'=>self::reference($raw['forecast_id'],'forecast'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'amount_minor'=>self::amount($raw['amount_minor'],'amount_minor'),
            'currency'=>self::currency($raw['currency']),
            'confidence'=>self::confidence($raw['confidence']),
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
            'classification'=>'forecast',
            'demonstrated_revenue'=>false,
        ];
    }

    public static function attribution(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::exactFields($raw,[
            'version','attribution_id','venture_id','opportunity_ref','campaign_ref','creative_ref','classification',
            'amount_minor','currency','source_ref','evidence_refs','observed_at','freshness',
        ],'RevenueAttribution');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueAttribution version invalid.');

        [$venture,$opportunity]=self::pipelineScope($raw,$pipeline,'RevenueAttribution');

        $campaign=self::nullableReference($raw['campaign_ref'],'campaign');
        $creative=self::nullableReference($raw['creative_ref'],'creative');
        if($campaign!==$pipeline['campaign_ref'] || $creative!==$pipeline['creative_ref'])
            throw new InvalidArgumentException('RevenueAttribution campaign scope mismatch.');

        $classification=self::choice($raw['classification'],self::ATTRIBUTION,'classification');
        $amount=$raw['amount_minor']===null?null:self::amount($raw['amount_minor'],'amount_minor');
        $evidence=self::references($raw['evidence_refs'],'evidence',32);
        if($classification==='unknown' && $amount!==null)
            throw new InvalidArgumentException('Unknown attribution cannot claim revenue amount.');
        if(in_array($classification,['observed','inferred'],true) && $amount===null)
            throw new InvalidArgumentException('Known attribution requires amount.');
        if(in_array($classification,['observed','inferred'],true) && $pipeline['stage']!=='won')
            throw new InvalidArgumentException('Known revenue attribution requires won pipeline.');
        if($classification==='observed' && $evidence===[])
            throw new InvalidArgumentException('Observed attribution requires evidence.');

        return [
            'version'=>1,
            'attribution_id'=>self::reference($raw['attribution_id'],'attribution'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'campaign_ref'=>$campaign,
            'creative_ref'=>$creative,
            'classification'=>$classification,
            'amount_minor'=>$amount,
            'currency'=>self::currency($raw['currency']),
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'evidence_refs'=>$evidence,
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
            'demonstrated_amount_minor'=>$classification==='observed'?$amount:null,
        ];
    }

    public static function lifecycleSignal(array $raw,array $pipelineRaw): array
    {
        $pipeline=self::pipeline($pipelineRaw);
        self::exactFields($raw,[
            'version','signal_id','venture_id','opportunity_ref','kind','classification','source_ref','observed_at',
            'freshness','product_intelligence_ref','customer_success_ref',
        ],'RevenueLifecycleSignal');
        if($raw['version']!==1) throw new InvalidArgumentException('RevenueLifecycleSignal version invalid.');

        [$venture,$opportunity]=self::pipelineScope($raw,$pipeline,'RevenueLifecycleSignal');

        if($pipeline['stage']!=='won')
            throw new InvalidArgumentException('Lifecycle signal requires won pipeline.');

        $productRef=self::nullableReference($raw['product_intelligence_ref'],'product-intelligence');
        $successRef=self::nullableReference($raw['customer_success_ref'],'customer-success');
        if($productRef===null && $successRef===null)
            throw new InvalidArgumentException('Lifecycle signal requires a domain handoff reference.');

        return [
            'version'=>1,
            'signal_id'=>self::reference($raw['signal_id'],'signal'),
            'venture_id'=>$venture,
            'opportunity_ref'=>$opportunity,
            'kind'=>self::choice($raw['kind'],self::SIGNAL_KINDS,'kind'),
            'classification'=>self::choice($raw['classification'],self::ATTRIBUTION,'classification'),
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'observed_at'=>self::natural($raw['observed_at'],'observed_at'),
            'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
            'product_intelligence_ref'=>$productRef,
            'customer_success_ref'=>$successRef,
        ];
    }

    private static function pipelineScope(array $raw,array $pipeline,string $label): array
    {
        $venture=self::venture($raw['venture_id']);
        $opportunity=self::reference($raw['opportunity_ref'],'opportunity');
        if($pipeline['opportunity_ref']!==null
            && $venture===$pipeline['venture_id']
            && $opportunity===$pipeline['opportunity_ref']) return [$venture,$opportunity];
        throw new InvalidArgumentException($label.' scope mismatch.');
    }

    private static function reference(mixed $value,string $namespace): string
    {
        $pattern=sprintf('/^%s:[a-f0-9]{32}$/D',preg_quote($namespace,'/'));
        if(is_string($value) && preg_match($pattern,$value)===1) return $value;
        throw new InvalidArgumentException($namespace.' ref invalid.');
    }

    private static function nullableReference(mixed $value,string $namespace): ?string
    {
        return $value===null ? null : self::reference($value,$namespace);
    }

    private static function references(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values) || !array_is_list($values) || count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $refs=array_map(
            static fn(mixed $value): string=>self::reference($value,$namespace),
            $values,
        );
        if(count(array_unique($refs,SORT_STRING))!==count($refs))
            throw new InvalidArgumentException($namespace.' ref duplicated.');
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function venture(mixed $value): string
    {
        if(is_string($value) && preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)===1) return $value;
        throw new InvalidArgumentException('venture_id invalid.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value) && in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function natural(mixed $value,string $label): int
    {
        if(is_int($value) && $value>=0) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function amount(mixed $value,string $label): int
    {
        $amount=self::natural($value,$label);
        if($amount<=1_000_000_000_000_000) return $amount;
        throw new InvalidArgumentException($label.' exceeds limit.');
    }

    private static function confidence(mixed $value): int
    {
        if(is_int($value) && $value>=0 && $value<=100) return $value;
        throw new InvalidArgumentException('confidence invalid.');
    }

    private static function currency(mixed $value): string
    {
        if(is_string($value) && preg_match('/^[A-Z]{3}$/D',$value)===1) return $value;
        throw new InvalidArgumentException('currency invalid.');
    }

    private static function exactFields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row) || array_is_list($row))
            throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);
        if(count($actual)===count($expected)
            && array_diff($actual,$expected)===[] && array_diff($expected,$actual)===[]) return;
        throw new InvalidArgumentException($label.' fields invalid.');
    }

}
