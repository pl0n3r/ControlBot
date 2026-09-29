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
            if(array_is_list($entry)||count($entry)!==2||!array_key_exists('stage',$entry)||!array_key_exists('event',$entry))
                throw new InvalidArgumentException('Lifecycle entry fields invalid.');
            $stage=$entry['stage'];
            if(!is_string($stage)||(!in_array($stage,self::STAGES,true)&&!in_array($stage,self::AUXILIARY,true)))
                throw new InvalidArgumentException('Lifecycle stage invalid.');
            if(!is_array($entry['event'])) throw new InvalidArgumentException('Lifecycle event invalid.');

            $event=AgentReplayCore::event($entry['event']);
            self::sourceForStage($stage,$event['source']);
            if($workItem===null) $workItem=$event['work_item_id'];
            elseif($workItem!==$event['work_item_id']) throw new InvalidArgumentException('Lifecycle work_item_id mismatch.');

            $key=hash('sha256',serialize($event));
            if(isset($bindings[$key])&&$bindings[$key]!==$stage)
                throw new InvalidArgumentException('Lifecycle event cannot belong to multiple stages.');
            $bindings[$key]=$stage;$events[$key]=$event;
        }

        $replay=AgentReplayCore::build(array_values($events));
        $buckets=array_fill_keys(self::STAGES,[]);$aux=[];
        foreach($replay['events'] as $event){
            $stage=$bindings[hash('sha256',serialize($event))]??null;
            if($stage===null) throw new InvalidArgumentException('Lifecycle stage binding missing.');
            if(array_key_exists($stage,$buckets)) $buckets[$stage][]=$event;
            else $aux[]=['stage'=>$stage,'event'=>$event];
        }

        $stages=[];$missing=[];
        foreach(self::STAGES as $name){
            $rows=$buckets[$name];$state=$rows===[]?'missing':'observed';
            if($state==='missing')$missing[]=$name;
            $stages[]=['name'=>$name,'state'=>$state,'events'=>$rows];
        }

        $canonical=[
            'version'=>1,'work_item_id'=>$workItem,'replay_fingerprint'=>$replay['fingerprint'],
            'stages'=>$stages,'missing'=>$missing,'auxiliary_evidence'=>$aux,'conflicts'=>$replay['conflicts'],
        ];
        return $canonical+['fingerprint'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
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
}
