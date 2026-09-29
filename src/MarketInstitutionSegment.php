<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MarketInstitutionSegment
{
    private const INSTITUTIONS=['capital','momentum'];
    private const FRESHNESS=['fresh','stale','unknown'];

    public static function project(array $marketRaw,array $signalsRaw): array
    {
        $ventureRaw=$marketRaw['venture_id']??null;
        if(!is_string($ventureRaw))
            throw new InvalidArgumentException('Market venture_id invalid.');

        $market=MarketScope::market($marketRaw,$ventureRaw);
        if(!is_array($signalsRaw)||!array_is_list($signalsRaw)||count($signalsRaw)>128)
            throw new InvalidArgumentException('signals invalid.');

        $signals=[];
        $seen=[];
        foreach($signalsRaw as $raw){
            self::exactFields(
                $raw,
                ['institution','venture_id','signal_ref','evidence_refs','observed_at','freshness'],
                'Market institution signal'
            );
            $institution=self::choice($raw['institution'],self::INSTITUTIONS,'institution');
            $venture=self::venture($raw['venture_id']);
            if($venture!==$market['venture_id'])
                throw new InvalidArgumentException('Signal venture mismatch.');

            $signalRef=self::signalRef($raw['signal_ref'],$institution);
            if(isset($seen[$signalRef]))
                throw new InvalidArgumentException('signal_ref duplicated.');
            $seen[$signalRef]=true;

            $freshness=self::choice($raw['freshness'],self::FRESHNESS,'freshness');
            $observedAt=self::observedAt($raw['observed_at'],$freshness);

            $signals[]=[
                'institution'=>$institution,
                'venture_id'=>$venture,
                'market_id'=>$market['market_id'],
                'geography'=>$market['geography'],
                'signal_ref'=>$signalRef,
                'evidence_refs'=>self::evidenceRefs($raw['evidence_refs']),
                'observed_at'=>$observedAt,
                'freshness'=>$freshness,
            ];
        }

        usort(
            $signals,
            static fn(array $left,array $right): int=>
                [$left['institution'],$left['signal_ref']]<=>[$right['institution'],$right['signal_ref']]
        );

        return [
            'version'=>1,
            'venture_id'=>$market['venture_id'],
            'market_id'=>$market['market_id'],
            'geography'=>$market['geography'],
            'signals'=>$signals,
        ];
    }

    private static function signalRef(mixed $value,string $institution): string
    {
        $prefix='controlbot:'.$institution.'/';
        if(!is_string($value)
            ||preg_match('/^'.preg_quote($prefix,'/').'[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException('signal_ref invalid.');
        return $value;
    }

    private static function evidenceRefs(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>32)
            throw new InvalidArgumentException('evidence_refs invalid.');

        $refs=[];
        foreach($values as $value){
            if(!is_string($value)
                ||preg_match('/^controlbot:evidence\/[a-f0-9]{32}$/D',$value)!==1)
                throw new InvalidArgumentException('evidence_ref invalid.');
            if(in_array($value,$refs,true))
                throw new InvalidArgumentException('evidence_ref duplicated.');
            $refs[]=$value;
        }
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function observedAt(mixed $value,string $freshness): ?int
    {
        if($freshness==='unknown'){
            if($value!==null)
                throw new InvalidArgumentException('Unknown signal cannot invent observed_at.');
            return null;
        }
        if(!is_int($value)||$value<0)
            throw new InvalidArgumentException('observed_at invalid.');
        return $value;
    }

    private static function venture(mixed $value): string
    {
        if(!is_string($value)
            ||preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
        return $value;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function exactFields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row))
            throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);
        sort($actual,SORT_STRING);
        sort($expected,SORT_STRING);
        if($actual!==$expected)
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}
