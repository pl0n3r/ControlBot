<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MomentumCampaign
{
    private const CHANNELS=['organic_social','paid_social','search_ads','email','landing'];
    private const STATES=['draft','ready','active','paused','completed','cancelled'];
    private const FRESHNESS=['current','stale','unknown'];
    private const ATTRIBUTION=['observed','inferred','unknown'];
    private const SENSITIVE = '#(?:^|[:/])(?:password|passwd|secret|token|cookie|authorization|bearer|credential|otp|dsn|api[-_ ]?key|private[-_ ]?key)(?:$|[:/._-])#i';

    public static function normalize(array $raw): array
    {
        $expected=[
            'version','campaign_id','venture_ref','objective_ref','audience_ref','offer_ref','cta_ref',
            'channels','creative_variant_refs','status','budget_ref','authority_ref','source_ref',
            'observed_at','freshness','result_refs','attribution_state',
        ];
        self::fields($raw,$expected);
        if($raw['version']!==1) throw new InvalidArgumentException('Campaign version invalid.');

        $venture=self::ventureRef($raw['venture_ref']);
        $slug=substr($venture,strlen('controlbot:venture/'));
        $freshness=self::enum($raw['freshness'],self::FRESHNESS,'freshness');
        $attribution=self::enum($raw['attribution_state'],self::ATTRIBUTION,'attribution_state');

        if($freshness==='unknown'){
            if($raw['source_ref']!==null||$raw['observed_at']!==null)
                throw new InvalidArgumentException('Unknown freshness cannot invent provenance.');
            $source=null;$observed=null;
        }else{
            $source=self::scopedRef($raw['source_ref'],$slug,'source','source_ref');
            $observed=self::timestamp($raw['observed_at'],'observed_at');
        }

        $results=self::scopedRefList($raw['result_refs'],$slug,'result','result_refs',32,true);
        if($attribution==='unknown' && $results!==[])
            throw new InvalidArgumentException('Unknown attribution cannot invent results.');
        if($attribution!=='unknown' && $results===[])
            throw new InvalidArgumentException('Attribution evidence required.');

        return [
            'version'=>1,
            'campaign_id'=>self::opaqueCampaignId($raw['campaign_id']),
            'venture_ref'=>$venture,
            'objective_ref'=>self::scopedRef($raw['objective_ref'],$slug,'objective','objective_ref'),
            'audience_ref'=>self::scopedRef($raw['audience_ref'],$slug,'audience','audience_ref'),
            'offer_ref'=>self::scopedRef($raw['offer_ref'],$slug,'offer','offer_ref'),
            'cta_ref'=>self::scopedRef($raw['cta_ref'],$slug,'cta','cta_ref'),
            'channels'=>self::enumList($raw['channels'],self::CHANNELS,'channels',5),
            'creative_variant_refs'=>self::scopedRefList($raw['creative_variant_refs'],$slug,'creative','creative_variant_refs',32,false),
            'status'=>self::enum($raw['status'],self::STATES,'status'),
            'budget_ref'=>self::scopedRef($raw['budget_ref'],$slug,'budget','budget_ref'),
            'authority_ref'=>$raw['authority_ref']===null?null:self::scopedRef($raw['authority_ref'],$slug,'authority','authority_ref'),
            'source_ref'=>$source,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
            'result_refs'=>$results,
            'attribution_state'=>$attribution,
        ];
    }

    private static function fields(array $raw,array $expected): void
    {
        if(array_is_list($raw)) throw new InvalidArgumentException('Campaign invalid.');
        $actual=array_keys($raw); sort($actual,SORT_STRING); sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('Campaign fields invalid.');
    }

    private static function ventureRef(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>90
            ||preg_match('#^controlbot:venture/[a-z][a-z0-9-]{1,63}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('venture_ref invalid.');
        return $value;
    }

    private static function scopedRef(mixed $value,string $venture,string $kind,string $label): string
    {
        if(!is_string($value)||preg_match(self::SENSITIVE,$value)===1) throw new InvalidArgumentException($label.' invalid.');
        $pattern='#^controlbot:venture/'.preg_quote($venture,'#').'/'.preg_quote($kind,'#').'/[a-f0-9]{32}$#D';
        if(preg_match($pattern,$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function scopedRefList(mixed $values,string $venture,string $kind,string $label,int $max,bool $allowEmpty): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max||(!$allowEmpty&&$values===[]))
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $ref=self::scopedRef($value,$venture,$kind,$label);
            if(isset($out[$ref])) throw new InvalidArgumentException($label.' duplicated.');
            $out[$ref]=true;
        }
        $refs=array_keys($out); sort($refs,SORT_STRING); return $refs;
    }

    private static function enumList(mixed $values,array $allowed,string $label,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>$max)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $value=self::enum($value,$allowed,$label);
            if(isset($out[$value])) throw new InvalidArgumentException($label.' duplicated.');
            $out[$value]=true;
        }
        $values=array_keys($out); sort($values,SORT_STRING); return $values;
    }

    private static function opaqueCampaignId(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^campaign:[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException('campaign_id invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
}
