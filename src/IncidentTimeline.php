<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class IncidentTimeline
{
    public static function build(array $raw): array
    {
        self::fields($raw,['version','incident_ref','opened_at','detected_at','recovered_at','events']);
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('IncidentTimeline version invalid.');
        $incident=self::ref($raw['incident_ref'],'incident');
        $opened=self::time($raw['opened_at'],'opened_at');
        $detected=self::time($raw['detected_at'],'detected_at');
        $recovered=$raw['recovered_at']===null?null:self::time($raw['recovered_at'],'recovered_at');
        if($detected<$opened||($recovered!==null&&$recovered<$detected))
            throw new InvalidArgumentException('IncidentTimeline chronology invalid.');
        if(!is_array($raw['events'])||!array_is_list($raw['events'])||$raw['events']===[]||count($raw['events'])>256)
            throw new InvalidArgumentException('IncidentTimeline events invalid.');

        $seen=[];$events=[];
        foreach($raw['events'] as $i=>$row){
            self::fields($row,['event_id','timestamp','sequence','kind','source','evidence_ref']);
            $id=self::eventId($row['event_id']);
            if(isset($seen[$id])) throw new InvalidArgumentException('IncidentTimeline event duplicated.');
            $seen[$id]=true;
            $events[]=[
                'event_id'=>$id,
                'timestamp'=>self::time($row['timestamp'],'timestamp'),
                'sequence'=>self::sequence($row['sequence']),
                'kind'=>self::kind($row['kind']),
                'source'=>self::kind($row['source']),
                'evidence_ref'=>self::ref($row['evidence_ref'],'evidence'),
                'source_index'=>$i,
            ];
        }
        usort($events,static fn(array $a,array $b): int =>
            [$a['timestamp'],$a['sequence'],$a['event_id']] <=> [$b['timestamp'],$b['sequence'],$b['event_id']]
        );
        return [
            'version'=>1,'incident_ref'=>$incident,'opened_at'=>$opened,'detected_at'=>$detected,'recovered_at'=>$recovered,
            'duration_seconds'=>$recovered===null?null:$recovered-$detected,'events'=>$events,
        ];
    }

    private static function eventId(mixed $v): string
    { if(is_string($v)&&preg_match('/^event-[a-z0-9][a-z0-9-]{1,79}$/D',$v)===1) return $v; throw new InvalidArgumentException('event_id invalid.'); }
    private static function time(mixed $v,string $label): int
    { if(is_int($v)&&$v>=0) return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function sequence(mixed $v): int
    { if(is_int($v)&&$v>=0) return $v; throw new InvalidArgumentException('sequence invalid.'); }
    private static function kind(mixed $v): string
    { if(is_string($v)&&preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$v)===1) return $v; throw new InvalidArgumentException('kind invalid.'); }
    private static function ref(mixed $v,string $ns): string
    { if(is_string($v)&&preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{32}$/D',$v)===1) return $v; throw new InvalidArgumentException($ns.' ref invalid.'); }
    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('IncidentTimeline row invalid.');
        $a=array_keys($row);sort($a);sort($expected);if($a!==$expected) throw new InvalidArgumentException('IncidentTimeline fields invalid.');
    }
}
