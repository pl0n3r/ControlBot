<?php
declare(strict_types=1);

namespace ControlBot\Replay;

use InvalidArgumentException;

final class AgentReplayLifecycle
{
    private const STAGES=['issue','reservation','plan','commit','review','check','merge','deploy'];
    private const AUXILIARY=['manual','documental'];

    public static function build(array $entries): array
    {
        if(!array_is_list($entries)||$entries===[]||count($entries)>256)
            throw new InvalidArgumentException('Lifecycle entries invalid.');

        $events=[];$bindings=[];$workItem=null;
        foreach($entries as $entry){
            self::entryFields($entry);
            $stage=self::stage($entry['stage']);
            if(!is_array($entry['event'])) throw new InvalidArgumentException('Lifecycle event invalid.');
            $event=AgentReplayCore::event($entry['event']);
            self::sourceForStage($stage,$event['source']);

            if($workItem===null) $workItem=$event['work_item_id'];
            elseif($workItem!==$event['work_item_id']) throw new InvalidArgumentException('Lifecycle work_item_id mismatch.');

            $key=self::eventKey($event);
            if(isset($bindings[$key]) && $bindings[$key]!==$stage)
                throw new InvalidArgumentException('Lifecycle event cannot belong to multiple stages.');
            $bindings[$key]=$stage;
            $events[$key]=$event;
        }

        $replay=AgentReplayCore::build(array_values($events));
        $buckets=[];foreach(self::STAGES as $name)$buckets[$name]=[];
        $aux=[];

        foreach($replay['events'] as $event){
            $stage=$bindings[self::eventKey($event)]??null;
            if($stage===null) throw new InvalidArgumentException('Lifecycle stage binding missing.');
            if(in_array($stage,self::STAGES,true)) $buckets[$stage][]=$event;
            else $aux[]=['stage'=>$stage,'event'=>$event];
        }

        $stages=[];$missing=[];
        foreach(self::STAGES as $name){
            $rows=$buckets[$name];
            $state=$rows===[]?'missing':'observed';
            if($state==='missing') $missing[]=$name;
            $stages[]=['name'=>$name,'state'=>$state,'events'=>$rows];
        }

        $canonical=[
            'version'=>1,
            'work_item_id'=>$workItem,
            'replay_fingerprint'=>$replay['fingerprint'],
            'stages'=>$stages,
            'missing'=>$missing,
            'auxiliary_evidence'=>$aux,
            'conflicts'=>$replay['conflicts'],
        ];
        return $canonical+['fingerprint'=>self::fingerprint($canonical)];
    }

    private static function entryFields(array $entry): void
    {
        if(array_is_list($entry)) throw new InvalidArgumentException('Lifecycle entry invalid.');
        $keys=array_keys($entry);sort($keys,SORT_STRING);
        if($keys!==['event','stage']) throw new InvalidArgumentException('Lifecycle entry fields invalid.');
    }

    private static function stage(mixed $value): string
    {
        if(!is_string($value)||(!in_array($value,self::STAGES,true)&&!in_array($value,self::AUXILIARY,true)))
            throw new InvalidArgumentException('Lifecycle stage invalid.');
        return $value;
    }

    private static function sourceForStage(string $stage,string $source): void
    {
        $allowed=match($stage){
            'issue'=>['github_issue'],
            'reservation','plan'=>['controlbot','factory'],
            'commit'=>['github_commit'],
            'review','merge'=>['github_pr'],
            'check'=>['github_actions'],
            'deploy'=>['production'],
            'manual','documental'=>['github_issue','github_pr','github_commit','controlbot','factory','factoryrunner','production','external'],
        };
        if(!in_array($source,$allowed,true)) throw new InvalidArgumentException('Lifecycle source invalid for stage '.$stage.'.');
    }

    private static function eventKey(array $event): string
    {
        return hash('sha256',json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
