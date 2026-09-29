<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumCreative
{
    private const FORMATS=['carousel','copy','landing_asset','other','short_video','static_image','story'];
    private const STATES=['approved','archived','draft','rejected','review'];

    /** Normalize one Venture-scoped creative brief against a canonical BrandContext. */
    public static function brief(array $raw,array $brandRaw): array
    {
        $brand=MomentumCampaign::brandContext($brandRaw);
        self::fields($raw,[
            'version','brief_id','venture_id','brand_context_id','campaign_ref','format',
            'audience_ref','offer_ref','cta_ref','constraint_refs',
        ],'CreativeBrief');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('CreativeBrief version invalid.');

        $venture=self::venture($raw['venture_id']);
        $brandId=self::opaque($raw['brand_context_id'],'brand');
        if($venture!==$brand['venture_id']||$brandId!==$brand['brand_context_id'])
            throw new InvalidArgumentException('CreativeBrief brand scope mismatch.');

        return [
            'version'=>1,
            'brief_id'=>self::opaque($raw['brief_id'],'brief'),
            'venture_id'=>$venture,
            'brand_context_id'=>$brandId,
            'campaign_ref'=>self::opaque($raw['campaign_ref'],'campaign'),
            'format'=>self::enumValue($raw['format'],self::FORMATS,'format'),
            'audience_ref'=>self::opaque($raw['audience_ref'],'audience'),
            'offer_ref'=>self::opaque($raw['offer_ref'],'offer'),
            'cta_ref'=>self::opaque($raw['cta_ref'],'cta'),
            'constraint_refs'=>self::opaqueList($raw['constraint_refs'],'constraint',32),
        ];
    }

    /** Normalize one creative variant and bind it to its brief and BrandContext. */
    public static function variant(array $raw,array $briefRaw,array $brandRaw): array
    {
        $brief=self::brief($briefRaw,$brandRaw);
        self::fields($raw,[
            'version','variant_id','brief_id','venture_id','brand_context_id','variant_key','format',
            'content_ref','asset_ref','hypothesis_ref','metric_ref','provenance_refs','status',
        ],'CreativeVariant');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('CreativeVariant version invalid.');

        $row=[
            'version'=>1,
            'variant_id'=>self::opaque($raw['variant_id'],'creative'),
            'brief_id'=>self::opaque($raw['brief_id'],'brief'),
            'venture_id'=>self::venture($raw['venture_id']),
            'brand_context_id'=>self::opaque($raw['brand_context_id'],'brand'),
            'variant_key'=>self::variantKey($raw['variant_key']),
            'format'=>self::enumValue($raw['format'],self::FORMATS,'format'),
            'content_ref'=>self::nullableOpaque($raw['content_ref'],'content'),
            'asset_ref'=>self::nullableOpaque($raw['asset_ref'],'asset'),
            'hypothesis_ref'=>self::nullableOpaque($raw['hypothesis_ref'],'hypothesis'),
            'metric_ref'=>self::nullableOpaque($raw['metric_ref'],'metric'),
            'provenance_refs'=>self::opaqueList($raw['provenance_refs'],'source',32),
            'status'=>self::enumValue($raw['status'],self::STATES,'status'),
        ];

        if($row['brief_id']!==$brief['brief_id']||$row['venture_id']!==$brief['venture_id']
            ||$row['brand_context_id']!==$brief['brand_context_id']||$row['format']!==$brief['format'])
            throw new InvalidArgumentException('CreativeVariant brief scope mismatch.');
        if($row['content_ref']===null&&$row['asset_ref']===null)
            throw new InvalidArgumentException('CreativeVariant requires content_ref or asset_ref.');
        if($row['status']==='approved'&&$row['provenance_refs']===[])
            throw new InvalidArgumentException('Approved CreativeVariant requires provenance.');

        return $row;
    }

    /** Normalize and deterministically order one variant set. */
    public static function variantSet(array $rows,array $briefRaw,array $brandRaw): array
    {
        if(!array_is_list($rows)||$rows===[]||count($rows)>26)
            throw new InvalidArgumentException('CreativeVariant set invalid.');
        $out=[];$keys=[];$ids=[];
        foreach($rows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('CreativeVariant invalid.');
            $row=self::variant($raw,$briefRaw,$brandRaw);
            if(isset($keys[$row['variant_key']])||isset($ids[$row['variant_id']]))
                throw new InvalidArgumentException('CreativeVariant duplicated.');
            $keys[$row['variant_key']]=true;$ids[$row['variant_id']]=true;$out[]=$row;
        }
        usort($out,static fn(array $a,array $b): int=>[$a['variant_key'],$a['variant_id']]<=>[$b['variant_key'],$b['variant_id']]);
        return $out;
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

    private static function variantKey(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[A-Z][A-Z0-9]{0,3}$/D',$value)!==1)
            throw new InvalidArgumentException('variant_key invalid.');
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
