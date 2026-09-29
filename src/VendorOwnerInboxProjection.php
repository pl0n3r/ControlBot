<?php
declare(strict_types=1);

namespace ControlBot\Vendors;

use ControlBot\Business\OwnerInbox;
use InvalidArgumentException;

final class VendorOwnerInboxProjection
{
    public static function project(
        array $vendorRaw,
        int $now,
        int $horizonSeconds,
        string $signalRef,
        array $entryRaw,
    ): array {
        self::fields($entryRaw,[
            'class','title','summary','impact','actor_ref',
            'required_authority_level','decision_ref','options_ref','deadline_at',
        ],'VendorOwnerInboxInput');

        if(preg_match('/^vendor-exception:[a-f0-9]{64}$/D',$signalRef)!==1)
            throw new InvalidArgumentException('signal_ref invalid.');

        $selected=null;
        foreach(VendorExceptionSignal::project($vendorRaw,$now,$horizonSeconds) as $signal){
            if(hash_equals($signalRef,$signal['signal_ref'])){
                $selected=$signal;
                break;
            }
        }
        if($selected===null) throw new InvalidArgumentException('Vendor exception signal is not material.');

        $freshness=match($selected['freshness']){
            'fresh'=>'current',
            'stale'=>'stale',
            'unknown'=>'unknown',
            default=>throw new InvalidArgumentException('Vendor signal freshness invalid.'),
        };

        $observed=null;
        $source=null;
        $evidence=[];
        if($freshness!=='unknown'){
            if(!is_int($selected['observed_at'])||$selected['observed_at']<1
                ||!is_string($selected['source_ref'])||$selected['source_ref']==='')
                throw new InvalidArgumentException('Vendor signal provenance invalid.');
            $observed=$selected['observed_at'];
            $source='controlbot:vendor-exception/source/'.self::digest($selected['source_ref']);
            $evidence[]='controlbot:vendor-exception/signal/'.self::digest($selected['signal_ref']);
            foreach($selected['evidence_refs'] as $ref){
                if(!is_string($ref)||$ref==='') throw new InvalidArgumentException('Vendor evidence invalid.');
                $evidence[]='controlbot:evidence/vendor/'.self::digest($ref);
            }
            $evidence=array_values(array_unique($evidence));
            sort($evidence,SORT_STRING);
        }

        $venture=$selected['venture_id']??null;
        if(!is_string($venture)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$venture)!==1)
            throw new InvalidArgumentException('Vendor venture scope invalid.');

        return OwnerInbox::entry([
            'version'=>1,
            'entry_ref'=>'controlbot:vendor-exception/inbox/'.self::digest($selected['signal_ref']),
            'class'=>$entryRaw['class'],
            'scope'=>['kind'=>'venture','ref'=>'controlbot:venture/'.$venture],
            'title'=>$entryRaw['title'],
            'summary'=>$entryRaw['summary'],
            'impact'=>$entryRaw['impact'],
            'actor_ref'=>$entryRaw['actor_ref'],
            'required_authority_level'=>$entryRaw['required_authority_level'],
            'decision_ref'=>$entryRaw['decision_ref'],
            'options_ref'=>$entryRaw['options_ref'],
            'deadline_at'=>$entryRaw['deadline_at'],
            'source_ref'=>$source,
            'evidence_refs'=>$evidence,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
        ]);
    }

    private static function digest(string $value): string
    {
        $hex=hash('sha256',$value);
        return implode('',array_map(
            static fn(string $pair): string=>'h'.$pair,
            str_split($hex,2)
        ));
    }

    private static function safeDigest(string $value): string
    {
        return implode('x',str_split(hash('sha256',$value),4));
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
