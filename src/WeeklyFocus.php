<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use InvalidArgumentException;

final class WeeklyFocus
{
    public static function normalize(array $raw): array
    {
        self::fields($raw,['focus_id','week_start','scope','ordered_refs','version','created_at','updated_at','updated_by'],'WeeklyFocus');
        $created=self::ts($raw['created_at'],'created_at');
        $updated=self::ts($raw['updated_at'],'updated_at');
        if($updated<$created) throw new InvalidArgumentException('WeeklyFocus chronology invalid.');
        return [
            'focus_id'=>self::id($raw['focus_id'],'focus_id'),
            'week_start'=>self::day($raw['week_start']),
            'scope'=>self::ref($raw['scope'],'scope'),
            'ordered_refs'=>self::focusRefs($raw['ordered_refs']),
            'version'=>self::positive($raw['version'],'version'),
            'created_at'=>$created,'updated_at'=>$updated,
            'updated_by'=>self::ref($raw['updated_by'],'updated_by'),
        ];
    }

    public static function revise(array $raw,array $orderedRefs,int $expectedVersion,int $updatedAt,string $updatedBy): array
    {
        $current=self::normalize($raw);
        if($expectedVersion!==$current['version']) throw new InvalidArgumentException('WeeklyFocus version conflict.');
        if($current['version']===PHP_INT_MAX) throw new InvalidArgumentException('WeeklyFocus version overflow.');
        $updated=self::ts($updatedAt,'updated_at');
        if($updated<=$current['updated_at']) throw new InvalidArgumentException('WeeklyFocus update chronology invalid.');
        return self::normalize(array_replace($current,[
            'ordered_refs'=>self::focusRefs($orderedRefs),
            'version'=>$current['version']+1,
            'updated_at'=>$updated,
            'updated_by'=>self::ref($updatedBy,'updated_by'),
        ]));
    }

    public static function preference(array $focusRaw,array $candidateRows,array $metadataRaw): array
    {
        $focus=self::normalize($focusRaw);
        $request=SchedulerSelection::request($candidateRows);
        if(!is_array($metadataRaw)||array_is_list($metadataRaw)) throw new InvalidArgumentException('focus metadata invalid.');
        $keys=array_column($request['candidates'],'key'); sort($keys,SORT_STRING);
        $metaKeys=array_keys($metadataRaw); sort($metaKeys,SORT_STRING);
        if($keys!==$metaKeys) throw new InvalidArgumentException('focus metadata mismatch.');

        $positions=[]; foreach($focus['ordered_refs'] as $i=>$ref) $positions[$ref]=$i+1;
        $eligible=[];
        foreach($request['candidates'] as $candidate){
            $meta=self::meta($metadataRaw[$candidate['key']]);
            if(!$candidate['readiness']['ready']||$meta['ref_state']==='unavailable') continue;
            $eligible[]=[
                'candidate'=>$candidate,'focus_ref'=>$meta['focus_ref'],
                'focus_position'=>$positions[$meta['focus_ref']]??null,
            ];
        }
        if($eligible===[]) return [
            'version'=>1,'request_fingerprint'=>$request['fingerprint'],'preferred_key'=>null,'preferred_source_ref'=>null,
            'priority'=>null,'focus_version'=>null,'focus_position'=>null,
            'focus_influenced'=>false,'preference_reason'=>'no_eligible_candidate',
        ];

        $priorities=array_values(array_unique(array_column(array_column($eligible,'candidate'),'priority')));
        if(count($priorities)!==1) throw new InvalidArgumentException('WeeklyFocus canonical cohort mismatch.');
        $canonicalFirstKey=$eligible[0]['candidate']['key'];
        usort($eligible,static function(array $a,array $b):int{
            $ap=$a['focus_position']??PHP_INT_MAX; $bp=$b['focus_position']??PHP_INT_MAX;
            return [$ap,$a['candidate']['key']]<=>[$bp,$b['candidate']['key']];
        });
        $preferred=$eligible[0];
        $influenced=count($eligible)>1
            && $preferred['focus_position']!==null
            && $preferred['candidate']['key']!==$canonicalFirstKey;
        return [
            'version'=>1,'request_fingerprint'=>$request['fingerprint'],
            'preferred_key'=>$preferred['candidate']['key'],'preferred_source_ref'=>$preferred['candidate']['source_ref'],
            'priority'=>$preferred['candidate']['priority'],
            'focus_version'=>$preferred['focus_position']===null?null:$focus['version'],'focus_position'=>$preferred['focus_position'],
            'focus_influenced'=>$influenced,
            'preference_reason'=>$influenced?'weekly_focus_tiebreak':'canonical_cohort_order',
        ];
    }

    public static function activeAt(array $history,int $at): ?array
    {
        if(!array_is_list($history)||$history===[]||count($history)>64) throw new InvalidArgumentException('WeeklyFocus history invalid.');
        $at=self::ts($at,'at'); $rows=[];
        foreach($history as $raw){if(!is_array($raw))throw new InvalidArgumentException('WeeklyFocus history row invalid.');$rows[]=self::normalize($raw);}
        usort($rows,static fn(array $a,array $b):int=>$a['version']<=>$b['version']);
        $first=$rows[0]; $previous=null; $active=null;
        foreach($rows as $row){
            if($row['focus_id']!==$first['focus_id']||$row['week_start']!==$first['week_start']||$row['scope']!==$first['scope']
                ||$row['created_at']!==$first['created_at']) throw new InvalidArgumentException('WeeklyFocus history identity drift.');
            if($previous!==null&&($row['version']!==$previous['version']+1||$row['updated_at']<=$previous['updated_at']))
                throw new InvalidArgumentException('WeeklyFocus history sequence invalid.');
            if($row['updated_at']<=$at) $active=$row;
            $previous=$row;
        }
        return $active;
    }

    private static function meta(mixed $raw): array
    {
        self::fields($raw,['focus_ref','ref_state'],'FocusCandidateMetadata');
        if(!is_string($raw['ref_state'])||!in_array($raw['ref_state'],['available','unavailable'],true))
            throw new InvalidArgumentException('ref_state invalid.');
        return ['focus_ref'=>self::focusRef($raw['focus_ref']),'ref_state'=>$raw['ref_state']];
    }
    private static function focusRefs(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>64) throw new InvalidArgumentException('ordered_refs invalid.');
        $out=[]; foreach($values as $value){$ref=self::focusRef($value);if(in_array($ref,$out,true))throw new InvalidArgumentException('ordered_refs duplicated.');$out[]=$ref;} return $out;
    }
    private static function focusRef(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^controlbot:(?:project|epic)\/[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
            throw new InvalidArgumentException('focus_ref invalid.');
        return $value;
    }
    private static function day(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)!==1
            ||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new InvalidArgumentException('week_start invalid.');
        return $value;
    }
    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\/#@-]{0,179}$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|private[_-]?key|api[_-]?key|dsn)/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function id(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function positive(mixed $value,string $label): int
    {if(!is_int($value)||$value<1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function ts(mixed $value,string $label): int
    {if(!is_int($value)||$value<1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
