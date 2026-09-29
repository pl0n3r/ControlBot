<?php
declare(strict_types=1);

namespace ControlBot\CustomerSuccess;

use InvalidArgumentException;

final class CustomerSuccessCore
{
    private const DIMENSIONS = [
        'activation','adoption','churn_risk','onboarding','reliability_impact',
        'renewal_signal','satisfaction','support_burden','usage_recency',
    ];
    private const STATUSES = ['measured','insufficient_data','unknown'];
    private const FRESHNESS = ['fresh','stale','unknown'];
    private const NATURE = ['observed','inferred'];
    private const SUPPORT_CATEGORIES = ['access','billing','bug','how_to','incident','other','performance'];
    private const SEVERITIES = ['low','medium','high','critical'];
    private const RESOLUTION = ['open','investigating','resolved','escalated','unknown'];

    public static function snapshot(array $raw,string $expectedVentureId): array
    {
        self::fields($raw,['version','snapshot_id','venture_id','product_ref','observed_at','dimensions'],'CustomerSuccessSnapshot');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('CustomerSuccessSnapshot version invalid.');
        $venture=self::venture($raw['venture_id']);
        if($venture!==self::venture($expectedVentureId)) throw new InvalidArgumentException('Customer success venture scope mismatch.');
        $dimensions=self::dimensions($raw['dimensions']);
        return [
            'version'=>1,
            'snapshot_id'=>self::opaqueRef($raw['snapshot_id'],'snapshot_id','success'),
            'venture_id'=>$venture,
            'product_ref'=>self::opaqueRef($raw['product_ref'],'product_ref','product'),
            'observed_at'=>self::timestamp($raw['observed_at'],'observed_at'),
            'dimensions'=>$dimensions,
        ];
    }

    public static function supportSignal(array $raw,string $expectedVentureId): array
    {
        self::fields($raw,[
            'version','signal_id','venture_id','product_ref','category','severity',
            'pattern_ref','knowledge_ref','resolution_state','evidence_ref','freshness',
            'observed_at','escalation_ref',
        ],'SupportSignal');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('SupportSignal version invalid.');
        $venture=self::venture($raw['venture_id']);
        if($venture!==self::venture($expectedVentureId)) throw new InvalidArgumentException('Support signal venture scope mismatch.');
        $freshness=self::enumValue($raw['freshness'],self::FRESHNESS,'freshness');
        $observed=self::nullableTimestamp($raw['observed_at'],'observed_at');
        $evidence=self::nullableOpaqueRef($raw['evidence_ref'],'evidence_ref','evidence');
        if($freshness==='unknown' && ($observed!==null || $evidence!==null))
            throw new InvalidArgumentException('Unknown support signal must not invent evidence.');
        if($freshness!=='unknown' && ($observed===null || $evidence===null))
            throw new InvalidArgumentException('Known support signal requires observed evidence.');
        return [
            'version'=>1,
            'signal_id'=>self::opaqueRef($raw['signal_id'],'signal_id','support'),
            'venture_id'=>$venture,
            'product_ref'=>self::opaqueRef($raw['product_ref'],'product_ref','product'),
            'category'=>self::enumValue($raw['category'],self::SUPPORT_CATEGORIES,'category'),
            'severity'=>self::enumValue($raw['severity'],self::SEVERITIES,'severity'),
            'pattern_ref'=>self::nullableOpaqueRef($raw['pattern_ref'],'pattern_ref','pattern'),
            'knowledge_ref'=>self::nullableOpaqueRef($raw['knowledge_ref'],'knowledge_ref','knowledge'),
            'resolution_state'=>self::enumValue($raw['resolution_state'],self::RESOLUTION,'resolution_state'),
            'evidence_ref'=>$evidence,
            'freshness'=>$freshness,
            'observed_at'=>$observed,
            'escalation_ref'=>self::nullableOpaqueRef($raw['escalation_ref'],'escalation_ref','escalation'),
        ];
    }

    private static function dimensions(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)!==count(self::DIMENSIONS))
            throw new InvalidArgumentException('dimensions invalid.');
        $out=[];$seen=[];
        foreach($raw as $row){
            self::fields($row,['name','status','value_ref','evidence_ref','freshness','confidence','nature'],'HealthDimension');
            $name=self::enumValue($row['name'],self::DIMENSIONS,'dimension.name');
            if(isset($seen[$name])) throw new InvalidArgumentException('dimension duplicated.');
            $seen[$name]=true;
            $status=self::enumValue($row['status'],self::STATUSES,'dimension.status');
            $freshness=self::enumValue($row['freshness'],self::FRESHNESS,'dimension.freshness');
            $nature=self::enumValue($row['nature'],self::NATURE,'dimension.nature');
            $value=self::nullableOpaqueRef($row['value_ref'],'dimension.value_ref','metric');
            $evidence=self::nullableOpaqueRef($row['evidence_ref'],'dimension.evidence_ref','evidence');
            $confidence=self::confidence($row['confidence']);

            if($status==='measured' && ($value===null || $evidence===null || $freshness==='unknown'))
                throw new InvalidArgumentException('Measured dimension requires value, evidence and known freshness.');
            if($status!=='measured' && $value!==null)
                throw new InvalidArgumentException('Unmeasured dimension cannot assert a value.');
            if($status==='unknown' && ($evidence!==null || $freshness!=='unknown' || $confidence!==0.0))
                throw new InvalidArgumentException('Unknown dimension must fail closed.');
            if($name==='churn_risk' && $nature!=='inferred')
                throw new InvalidArgumentException('Churn risk must remain inferred.');

            $out[]=[
                'name'=>$name,'status'=>$status,'value_ref'=>$value,'evidence_ref'=>$evidence,
                'freshness'=>$freshness,'confidence'=>$confidence,'nature'=>$nature,
            ];
        }
        usort($out,static fn(array $a,array $b): int=>$a['name']<=>$b['name']);
        if(array_column($out,'name')!==self::DIMENSIONS) throw new InvalidArgumentException('dimensions incomplete.');
        return $out;
    }

    private static function venture(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
        return $value;
    }

    private static function opaqueRef(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableOpaqueRef(mixed $value,string $label,string $namespace): ?string
    {
        return $value===null?null:self::opaqueRef($value,$label,$namespace);
    }

    private static function confidence(mixed $value): float
    {
        if(!is_int($value)&&!is_float($value)) throw new InvalidArgumentException('confidence invalid.');
        $value=(float)$value;
        if(!is_finite($value)||$value<0.0||$value>1.0) throw new InvalidArgumentException('confidence invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableTimestamp(mixed $value,string $label): ?int
    {
        return $value===null?null:self::timestamp($value,$label);
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_fill_keys(array_keys($row),true);
        $wanted=array_fill_keys($expected,true);
        if(count($actual)!==count($wanted)||$actual!=$wanted) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
