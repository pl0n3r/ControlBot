<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MarketReadiness
{
    private const DOMAINS=[
        'aegis','capital','infrastructure','lex','localization','momentum','observability',
        'ownership','payments','pricing','privacy','product','support',
    ];
    private const STATUSES=['ready','blocked','unknown','not_applicable'];
    private const FRESHNESS=['fresh','stale','unknown'];

    public static function forCountry(array $marketRaw,string $ventureId,string $country,array $gates): array
    {
        $market=MarketScope::market($marketRaw,$ventureId);
        if($market['geography']['kind']!=='country'||$market['geography']['code']!==$country)
            throw new InvalidArgumentException('Market readiness country scope mismatch.');
        if($market['freshness']!=='fresh')
            throw new InvalidArgumentException('Market readiness requires fresh Market context.');
        if(!is_array($gates)||!array_is_list($gates))
            throw new InvalidArgumentException('Market readiness gates invalid.');

        $byDomain=[];
        foreach($gates as $raw){
            $gate=self::gate($raw);
            if(isset($byDomain[$gate['domain']]))
                throw new InvalidArgumentException('Market readiness domain duplicated.');
            $byDomain[$gate['domain']]=$gate;
        }
        $domains=array_keys($byDomain); sort($domains,SORT_STRING);
        if($domains!==self::DOMAINS)
            throw new InvalidArgumentException('Market readiness domains incomplete or unexpected.');

        ksort($byDomain,SORT_STRING);
        $normalized=array_values($byDomain);
        $missing=[];$hasBlocked=false;$hasUnknown=false;
        foreach($normalized as $gate){
            if($gate['effective_status']==='blocked') $hasBlocked=true;
            if($gate['effective_status']==='unknown') $hasUnknown=true;
            if(in_array($gate['effective_status'],['blocked','unknown'],true)) $missing[]=$gate['domain'];
        }
        $launchState=$hasBlocked?'blocked':($hasUnknown?'unknown':'ready');

        return [
            'version'=>1,
            'venture_id'=>$market['venture_id'],
            'market_id'=>$market['market_id'],
            'country'=>$country,
            'market_status'=>$market['status'],
            'launch_state'=>$launchState,
            'missing_domains'=>$missing,
            'gates'=>$normalized,
            'execution'=>false,
        ];
    }

    private static function gate(mixed $raw): array
    {
        self::fields($raw,['domain','status','evidence_refs','observed_at','freshness'],'Market readiness gate');
        $domain=self::enum($raw['domain'],self::DOMAINS,'domain');
        $status=self::enum($raw['status'],self::STATUSES,'status');
        $freshness=self::enum($raw['freshness'],self::FRESHNESS,'freshness');
        $evidence=self::evidenceRefs($raw['evidence_refs']);
        $observed=self::observedAt($raw['observed_at'],$freshness);
        if($freshness!=='unknown'&&$evidence===[])
            throw new InvalidArgumentException('Observed readiness gate requires evidence.');
        $effective=$freshness==='fresh'?$status:'unknown';
        return [
            'domain'=>$domain,
            'status'=>$status,
            'effective_status'=>$effective,
            'evidence_refs'=>$evidence,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
        ];
    }

    private static function evidenceRefs(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>16)
            throw new InvalidArgumentException('evidence_refs invalid.');
        $out=[];
        foreach($values as $value){
            if(!is_string($value)||preg_match('#^controlbot:[a-z][a-z0-9-]{1,31}/[a-f0-9]{32}$#D',$value)!==1)
                throw new InvalidArgumentException('evidence_ref invalid.');
            if(in_array($value,$out,true)) throw new InvalidArgumentException('evidence_ref duplicated.');
            $out[]=$value;
        }
        sort($out,SORT_STRING); return $out;
    }

    private static function observedAt(mixed $value,string $freshness): ?int
    {
        if($freshness==='unknown'){
            if($value!==null) throw new InvalidArgumentException('Unknown gate observed_at invalid.');
            return null;
        }
        if(!is_int($value)||$value<1) throw new InvalidArgumentException('observed_at invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
