<?php
declare(strict_types=1);
namespace ControlBot\AutoFactory;
use InvalidArgumentException;
final class AutoFactoryLearningEvent
{
    private const FIELDS=['schemaVersion','eventId','installationId','browserFamily','extensionVersion','problemCode','interfaceState','action','durationMs','attempt','outcome','policyVersion','observedAt'];
    private const ACTIONS=['wait','retry','reload','stop_wait','open_replacement_chat'];
    private const OUTCOMES=['success','failure','abandoned'];
    private const MAX_AGE_MS=2592000000;
    public static function ingest(array $batch,array $state,int $now): array
    {
        if($batch===[]||count($batch)>100||strlen(json_encode($batch,JSON_THROW_ON_ERROR))>16384) throw new InvalidArgumentException('Learning batch invalid.');
        $events=[]; foreach($batch as $raw) $events[]=self::event($raw,$now);
        $next=self::state($state,$now);
        foreach($events as $event){
            if(isset($next['seen'][$event['eventId']])) continue;
            if($event['observedAt']<$now-self::MAX_AGE_MS) continue;
            $next['seen'][$event['eventId']]=$event['observedAt'];
            $context=$event['problemCode'].':'.$event['interfaceState']; $action=$event['action'];
            $row=$next['aggregates'][$context][$action]??['samples'=>0,'successes'=>0,'durationMs'=>0,'lastAt'=>0];
            $row['samples']++; $row['successes']+=($event['outcome']==='success'?1:0);
            $row['durationMs']+=$event['durationMs']; $row['lastAt']=max($row['lastAt'],$event['observedAt']);
            $next['aggregates'][$context][$action]=$row;
        }
        return $next;
    }
    private static function state(array $raw,int $now): array
    {
        $seen=is_array($raw['seen']??null)?$raw['seen']:[];
        $seen=array_filter($seen,static fn($at): bool=>is_int($at)&&$at>=$now-self::MAX_AGE_MS);
        $aggregates=is_array($raw['aggregates']??null)?$raw['aggregates']:[];
        return ['schemaVersion'=>1,'seen'=>$seen,'aggregates'=>$aggregates];
    }
    private static function event(mixed $raw,int $now): array
    {
        if(!is_array($raw)||array_is_list($raw)||array_keys($raw)!==self::FIELDS) throw new InvalidArgumentException('Learning event fields invalid.');
        if($raw['schemaVersion']!==1) throw new InvalidArgumentException('Learning schema invalid.');
        foreach(['eventId','installationId','extensionVersion','problemCode','interfaceState'] as $field) self::token($raw[$field],$field);
        if(!in_array($raw['browserFamily'],['chrome','safari','edge','other'],true)) throw new InvalidArgumentException('Browser invalid.');
        if(!in_array($raw['action'],self::ACTIONS,true)) throw new InvalidArgumentException('Action invalid.');
        if(!in_array($raw['outcome'],self::OUTCOMES,true)) throw new InvalidArgumentException('Outcome invalid.');
        foreach(['durationMs','attempt','policyVersion','observedAt'] as $field) if(!is_int($raw[$field])||$raw[$field]<0) throw new InvalidArgumentException($field.' invalid.');
        if($raw['observedAt']>$now+300000) throw new InvalidArgumentException('Clock invalid.');
        return $raw;
    }
    private static function token(mixed $value,string $label): void
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
    }
}
