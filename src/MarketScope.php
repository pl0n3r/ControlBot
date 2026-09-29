<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MarketScope
{
    private const MODES=['single_country','multi_country','global'];
    private const STATES=['researching','validating','preparing','launch_ready','live','paused'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const COUNTRIES='AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';
    private const CURRENCIES='AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BOV BRL BSD BTN BWP BYN BZD CAD CDF CHE CHF CHW CLF CLP CNY COP COU CRC CUC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HRK HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MXV MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SLL SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD USN UYI UYU UYW UZS VED VES VND VUV WST XAF XAG XAU XBA XBB XBC XBD XCD XCG XDR XOF XPD XPF XPT XSU XTS XUA XXX YER ZAR ZMW ZWG ZWL';
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function scope(array $raw): array
    {
        self::fields($raw,['version','mode','primary_country','target_countries','excluded_countries','launch_countries','expansion_candidates','default_currency','default_locale'],'MarketScope');
        if($raw['version']!==1) throw new InvalidArgumentException('MarketScope version invalid.');
        $mode=self::enum($raw['mode'],self::MODES,'mode');
        $primary=self::nullableCountry($raw['primary_country'],'primary_country');
        $targets=self::countries($raw['target_countries'],'target_countries');
        $excluded=self::countries($raw['excluded_countries'],'excluded_countries');
        $launch=self::countries($raw['launch_countries'],'launch_countries');
        $expansion=self::countries($raw['expansion_candidates'],'expansion_candidates');
        if($mode==='single_country' && ($primary===null || $targets!==[$primary]))
            throw new InvalidArgumentException('single_country semantics invalid.');
        if($mode==='multi_country' && (count($targets)<2 || ($primary!==null && !in_array($primary,$targets,true))))
            throw new InvalidArgumentException('multi_country semantics invalid.');
        if($mode!=='global' && array_diff($launch,$targets)!==[])
            throw new InvalidArgumentException('launch countries must be targets.');
        if($primary!==null && in_array($primary,$excluded,true))
            throw new InvalidArgumentException('primary country excluded.');
        if(self::overlap($targets,$excluded)||self::overlap($launch,$excluded)
            ||self::overlap($expansion,$targets)||self::overlap($expansion,$launch)||self::overlap($expansion,$excluded))
            throw new InvalidArgumentException('MarketScope country sets overlap.');
        return [
            'version'=>1,'mode'=>$mode,'primary_country'=>$primary,
            'target_countries'=>$targets,'excluded_countries'=>$excluded,
            'launch_countries'=>$launch,'expansion_candidates'=>$expansion,
            'default_currency'=>self::currency($raw['default_currency'],'default_currency'),
            'default_locale'=>self::locale($raw['default_locale'],'default_locale'),
        ];
    }

    public static function market(array $raw,string $expectedVentureId): array
    {
        self::fields($raw,['version','market_id','venture_id','geography','status','priority','currencies','locales','source_ref','observed_at','freshness'],'Market');
        if($raw['version']!==1) throw new InvalidArgumentException('Market version invalid.');
        $venture=self::id($raw['venture_id'],'venture_id','venture-');
        if($venture!==self::id($expectedVentureId,'expected_venture_id','venture-'))
            throw new InvalidArgumentException('Market venture mismatch.');
        return [
            'version'=>1,
            'market_id'=>self::id($raw['market_id'],'market_id','market-'),
            'venture_id'=>$venture,
            'geography'=>self::geography($raw['geography']),
            'status'=>self::enum($raw['status'],self::STATES,'status'),
            'priority'=>self::priority($raw['priority']),
            'currencies'=>self::currencies($raw['currencies']),
            'locales'=>self::locales($raw['locales']),
            'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
            'observed_at'=>self::nonNegativeInt($raw['observed_at'],'observed_at'),
            'freshness'=>self::enum($raw['freshness'],self::FRESHNESS,'freshness'),
        ];
    }

    private static function geography(mixed $raw): array
    {
        self::fields($raw,['kind','code'],'Market geography');
        $kind=self::enum($raw['kind'],['country','region','global'],'geography.kind');
        if($kind==='global'){
            if($raw['code']!==null) throw new InvalidArgumentException('Global geography code invalid.');
            return ['kind'=>'global','code'=>null];
        }
        if($kind==='country') return ['kind'=>'country','code'=>self::country($raw['code'],'geography.code')];
        if(!is_string($raw['code'])||preg_match('/^[A-Z][A-Z0-9_-]{1,15}$/D',$raw['code'])!==1)
            throw new InvalidArgumentException('geography.code invalid.');
        return ['kind'=>'region','code'=>$raw['code']];
    }

    private static function countries(mixed $raw,string $label): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>64) throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($raw as $value){
            $code=self::country($value,$label);
            if(in_array($code,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$code;
        }
        sort($out,SORT_STRING); return $out;
    }

    private static function currencies(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>8) throw new InvalidArgumentException('currencies invalid.');
        $out=[];
        foreach($raw as $value){
            $code=self::currency($value,'currencies');
            if(in_array($code,$out,true)) throw new InvalidArgumentException('currencies duplicated.');
            $out[]=$code;
        }
        sort($out,SORT_STRING); return $out;
    }

    private static function locales(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>16) throw new InvalidArgumentException('locales invalid.');
        $out=[];
        foreach($raw as $value){
            $locale=self::locale($value,'locales');
            if(in_array($locale,$out,true)) throw new InvalidArgumentException('locales duplicated.');
            $out[]=$locale;
        }
        sort($out,SORT_STRING); return $out;
    }

    private static function country(mixed $value,string $label): string
    {
        if(!is_string($value)||!in_array($value,explode(' ',self::COUNTRIES),true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableCountry(mixed $value,string $label): ?string
    {
        return $value===null?null:self::country($value,$label);
    }

    private static function currency(mixed $value,string $label): string
    {
        if(!is_string($value)||!in_array($value,explode(' ',self::CURRENCIES),true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function locale(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2})?$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label,string $prefix): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($prefix,'/').'[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
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

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function priority(mixed $value): int
    {
        if(!is_int($value)||$value<1||$value>5) throw new InvalidArgumentException('priority invalid.');
        return $value;
    }

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function overlap(array $a,array $b): bool
    {
        return array_intersect($a,$b)!==[];
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
