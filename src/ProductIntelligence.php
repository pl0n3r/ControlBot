<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductIntelligence
{
    private const CATEGORIES=['activation','adoption','funnel_conversion','retention','churn_signal','usage_recency','completion_rate','time_to_value','support_rate','satisfaction','revenue_outcome'];
    private const STATUSES=['measured','unknown','insufficient_data'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const NATURE=['observed','inferred'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|email|e-mail|user[_ -]?id|customer[_ -]?id|member[_ -]?id|ip[_ -]?address|full[_ -]?name)/i';

    public static function metric(array $raw,string $expectedVentureId,string $expectedProductId): array
    {
        self::fields($raw,['version','metric_id','venture_id','product_id','surface','category','period','status','value','unit','source_ref','evidence_ref','sample_size','freshness','confidence','nature'],'Metric');
        if($raw['version']!==1) throw new InvalidArgumentException('Metric version invalid.');
        [$venture,$product]=self::scope($raw,$expectedVentureId,$expectedProductId);
        $status=self::enumValue($raw['status'],self::STATUSES,'status');
        $sample=self::nonNegativeInt($raw['sample_size'],'sample_size');
        $value=self::nullableNumber($raw['value'],'value');
        if($status==='measured' && ($value===null || $sample<1))
            throw new InvalidArgumentException('Measured metric requires value and sample.');
        if($status!=='measured' && $value!==null)
            throw new InvalidArgumentException('Unknown metric states cannot carry a value.');
        if($status==='insufficient_data' && $sample<1)
            throw new InvalidArgumentException('Insufficient data requires observed sample.');
        $category=self::enumValue($raw['category'],self::CATEGORIES,'category');
        $source=self::ref($raw['source_ref'],'source_ref');
        $evidence=self::ref($raw['evidence_ref'],'evidence_ref');
        if($category==='revenue_outcome' && !str_starts_with($source,'aggregate:finance/'))
            throw new InvalidArgumentException('Revenue outcome requires aggregate finance source.');
        return [
            'version'=>1,'metric_id'=>self::id($raw['metric_id'],'metric_id','metric-'),
            'venture_id'=>$venture,'product_id'=>$product,'surface'=>self::slug($raw['surface'],'surface'),
            'category'=>$category,'period'=>self::period($raw['period']),'status'=>$status,'value'=>$value,
            'unit'=>self::slug($raw['unit'],'unit'),'source_ref'=>$source,'evidence_ref'=>$evidence,
            'sample_size'=>$sample,'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'confidence'=>self::confidence($raw['confidence']),'nature'=>self::enumValue($raw['nature'],self::NATURE,'nature'),
        ];
    }

    public static function funnel(array $raw,string $expectedVentureId,string $expectedProductId): array
    {
        self::fields($raw,['version','funnel_id','venture_id','product_id','surface','period','stages','source_ref','evidence_ref','freshness','confidence','nature'],'Funnel');
        if($raw['version']!==1) throw new InvalidArgumentException('Funnel version invalid.');
        [$venture,$product]=self::scope($raw,$expectedVentureId,$expectedProductId);
        $stages=self::stages($raw['stages']);
        return [
            'version'=>1,'funnel_id'=>self::id($raw['funnel_id'],'funnel_id','funnel-'),
            'venture_id'=>$venture,'product_id'=>$product,'surface'=>self::slug($raw['surface'],'surface'),
            'period'=>self::period($raw['period']),'stages'=>$stages,'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
            'evidence_ref'=>self::ref($raw['evidence_ref'],'evidence_ref'),'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'confidence'=>self::confidence($raw['confidence']),'nature'=>self::enumValue($raw['nature'],self::NATURE,'nature'),
        ];
    }

    public static function cohort(array $raw,string $expectedVentureId,string $expectedProductId): array
    {
        self::fields($raw,['version','cohort_id','venture_id','product_id','surface','period','cohort_key','sample_size','retained_count','source_ref','evidence_ref','freshness','confidence','nature'],'Cohort');
        if($raw['version']!==1) throw new InvalidArgumentException('Cohort version invalid.');
        [$venture,$product]=self::scope($raw,$expectedVentureId,$expectedProductId);
        $sample=self::positiveInt($raw['sample_size'],'sample_size');
        $retained=self::nonNegativeInt($raw['retained_count'],'retained_count');
        if($retained>$sample) throw new InvalidArgumentException('retained_count invalid.');
        return [
            'version'=>1,'cohort_id'=>self::id($raw['cohort_id'],'cohort_id','cohort-'),
            'venture_id'=>$venture,'product_id'=>$product,'surface'=>self::slug($raw['surface'],'surface'),
            'period'=>self::period($raw['period']),'cohort_key'=>self::slug($raw['cohort_key'],'cohort_key'),
            'sample_size'=>$sample,'retained_count'=>$retained,'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
            'evidence_ref'=>self::ref($raw['evidence_ref'],'evidence_ref'),'freshness'=>self::enumValue($raw['freshness'],self::FRESHNESS,'freshness'),
            'confidence'=>self::confidence($raw['confidence']),'nature'=>self::enumValue($raw['nature'],self::NATURE,'nature'),
        ];
    }

    private static function scope(array $raw,string $expectedVentureId,string $expectedProductId): array
    {
        $venture=self::id($raw['venture_id'],'venture_id','venture-');
        $product=self::id($raw['product_id'],'product_id','product-');
        if($venture!==self::id($expectedVentureId,'expected_venture_id','venture-')
            ||$product!==self::id($expectedProductId,'expected_product_id','product-'))
            throw new InvalidArgumentException('Product intelligence scope mismatch.');
        return [$venture,$product];
    }

    private static function period(mixed $raw): array
    {
        self::fields($raw,['start_at','end_at'],'period');
        $start=self::nonNegativeInt($raw['start_at'],'period.start_at');
        $end=self::nonNegativeInt($raw['end_at'],'period.end_at');
        if($end<=$start) throw new InvalidArgumentException('period invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }

    private static function stages(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)<2||count($raw)>16)
            throw new InvalidArgumentException('stages invalid.');
        $out=[];$previous=null;$names=[];
        foreach($raw as $stage){
            self::fields($stage,['name','count'],'stage');
            $name=self::slug($stage['name'],'stage.name');
            $count=self::nonNegativeInt($stage['count'],'stage.count');
            if(in_array($name,$names,true)) throw new InvalidArgumentException('stage duplicated.');
            if($previous!==null && $count>$previous) throw new InvalidArgumentException('funnel counts invalid.');
            $names[]=$name;$previous=$count;$out[]=['name'=>$name,'count'=>$count];
        }
        return $out;
    }

    private static function confidence(mixed $value): float
    {
        if(!is_float($value)&&!is_int($value)) throw new InvalidArgumentException('confidence invalid.');
        $value=(float)$value;
        if(!is_finite($value)||$value<0.0||$value>1.0) throw new InvalidArgumentException('confidence invalid.');
        return $value;
    }

    private static function nullableNumber(mixed $value,string $label): int|float|null
    {
        if($value===null) return null;
        if(!is_int($value)&&!is_float($value)) throw new InvalidArgumentException($label.' invalid.');
        if(is_float($value)&&!is_finite($value)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label,string $prefix): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($prefix,'/').'[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<3||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row);sort($keys);sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
