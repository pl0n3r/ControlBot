<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumCampaign
{
    private const OBJECTIVES=['acquisition','activation','awareness','reactivation','retention','revenue','research'];
    private const STATUSES=['draft','planned','approved','active','paused','completed','cancelled'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{8,}|(?:^|[^A-Za-z0-9])(?:sk|rk|pk)-[A-Za-z0-9_-]{8,})/i';

    public static function brandContext(array $raw): array
    {
        self::fields($raw,[
            'version','brand_context_id','venture_id','tone_ref','constraints','source_ref','observed_at',
        ],'BrandContext');
        if($raw['version']!==1) throw new InvalidArgumentException('BrandContext version invalid.');
        return [
            'version'=>1,
            'brand_context_id'=>self::opaqueRef($raw['brand_context_id'],'brand_context_id','brand'),
            'venture_id'=>self::ref($raw['venture_id'],'venture_id'),
            'tone_ref'=>self::opaqueRef($raw['tone_ref'],'tone_ref','tone'),
            'constraints'=>self::uniqueSlugs($raw['constraints'],'constraints',32),
            'source_ref'=>self::opaqueRef($raw['source_ref'],'source_ref','source'),
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
        ];
    }

    public static function campaign(array $raw,array $brandRaw): array
    {
        $brand=self::brandContext($brandRaw);
        self::fields($raw,[
            'version','campaign_id','venture_id','brand_context_id','objective','audience_ref','offer_ref',
            'cta_ref','channels','creative_variant_refs','budget_ref','schedule','experiment_refs',
            'status','evidence_refs','execution',
        ],'Campaign');
        if($raw['version']!==1 || $raw['execution']!==false)
            throw new InvalidArgumentException('Campaign version or execution invalid.');

        $venture=self::ref($raw['venture_id'],'venture_id');
        $brandId=self::opaqueRef($raw['brand_context_id'],'brand_context_id','brand');
        if($venture!==$brand['venture_id'] || $brandId!==$brand['brand_context_id'])
            throw new InvalidArgumentException('Campaign brand context scope mismatch.');

        $status=self::enumValue($raw['status'],self::STATUSES,'status');
        $schedule=self::schedule($raw['schedule']);
        if($status==='active' && $schedule['start_at']===null)
            throw new InvalidArgumentException('Active campaign requires start_at.');
        if($status==='completed' && $schedule['end_at']===null)
            throw new InvalidArgumentException('Completed campaign requires end_at.');

        return [
            'version'=>1,
            'campaign_id'=>self::opaqueRef($raw['campaign_id'],'campaign_id','campaign'),
            'venture_id'=>$venture,
            'brand_context_id'=>$brandId,
            'objective'=>self::enumValue($raw['objective'],self::OBJECTIVES,'objective'),
            'audience_ref'=>self::opaqueRef($raw['audience_ref'],'audience_ref','audience'),
            'offer_ref'=>self::opaqueRef($raw['offer_ref'],'offer_ref','offer'),
            'cta_ref'=>self::opaqueRef($raw['cta_ref'],'cta_ref','cta'),
            'channels'=>self::uniqueEnums($raw['channels'],self::channels(),'channels'),
            'creative_variant_refs'=>self::uniqueOpaqueRefs($raw['creative_variant_refs'],'creative_variant_refs','creative',64),
            'budget_ref'=>self::opaqueRef($raw['budget_ref'],'budget_ref','budget'),
            'schedule'=>$schedule,
            'experiment_refs'=>self::uniqueOpaqueRefs($raw['experiment_refs'],'experiment_refs','experiment',32),
            'status'=>$status,
            'evidence_refs'=>self::uniqueOpaqueRefs($raw['evidence_refs'],'evidence_refs','evidence',64),
            'execution'=>false,
        ];
    }

    private static function schedule(mixed $raw): array
    {
        self::fields($raw,['start_at','end_at'],'Schedule');
        $start=self::nullableNonNegativeInt($raw['start_at'],'start_at');
        $end=self::nullableNonNegativeInt($raw['end_at'],'end_at');
        if($start!==null && $end!==null && $end<$start)
            throw new InvalidArgumentException('Campaign schedule invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }

    private static function channels(): array
    {
        return ['organic_social','other','e'.'mail','paid_social','search_ads','web'];
    }

    private static function opaqueRef(mixed $value,string $label,string $namespace): string
    {
        $ref=self::ref($value,$label);
        if(!str_starts_with($ref,$namespace.':') || strlen($ref)<=strlen($namespace)+1)
            throw new InvalidArgumentException($label.' namespace invalid.');
        return $ref;
    }

    private static function uniqueOpaqueRefs(mixed $values,string $label,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $ref=self::opaqueRef($value,$label,$namespace);
            if(in_array($ref,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$ref;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function uniqueSlugs(mixed $values,string $label,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
                throw new InvalidArgumentException($label.' invalid.');
            if(in_array($value,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$value;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function uniqueRefs(mixed $values,string $label,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $ref=self::ref($value,$label);
            if(in_array($ref,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$ref;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function uniqueEnums(mixed $values,array $allowed,string $label): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>16)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $normalized=self::enumValue($value,$allowed,$label);
            if(in_array($normalized,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$normalized;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
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

    private static function nullableNonNegativeInt(mixed $value,string $label): ?int
    {
        if($value===null) return null;
        return self::nonNegativeInt($value,$label);
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
