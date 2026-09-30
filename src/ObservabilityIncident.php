<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use ControlBot\Business\IncidentTimeline;
use InvalidArgumentException;

final class ObservabilityIncident
{
    private const RANK=['info'=>0,'warning'=>1,'error'=>2,'critical'=>3];
    private const FAIL=['failed','failure','error','unhealthy','down','unavailable','degraded'];
    private const RECOVER=['healthy','ok','recovered','success'];
    private const MONITOR=['recovering','monitoring'];

    public static function correlate(array $rows,int $now,array $ttl,int $dedupe=300,int $postDeploy=900): array
    {
        if(!array_is_list($rows)||$rows===[]||count($rows)>256) throw new InvalidArgumentException('Incident events invalid.');
        self::window($dedupe);self::window($postDeploy);
        $events=[];
        foreach($rows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Incident raw event invalid.');
            $events[]=ObservabilityEvent::normalize($raw,$now,$ttl);
        }
        usort($events,[self::class,'compare']);

        $incidents=[];$deploys=[];
        foreach($events as $event){
            if(self::deployContext($event)){$deploys[]=$event;continue;}
            if(self::monitoring($event)||self::recovery($event)){self::recover($incidents,$event);continue;}
            if(!self::failure($event))continue;

            $cause=$event['fingerprint'];
            $i=self::matchingIncident($incidents,$event,$cause,$dedupe);
            $deploy=self::matchingDeploy($deploys,$event,$postDeploy);
            if($i===null){
                $incidents[]=self::freshIncident($event,$cause);
                $i=array_key_last($incidents);
            }
            if($deploy!==null){
                self::evidence($incidents[$i],$deploy);
                self::timelineIfMissing($incidents[$i],$deploy,'deploy.observed');
                self::reason($incidents[$i],'post_deploy_explicit');
            } elseif($incidents[$i]['occurrence_count']>0) {
                self::reason($incidents[$i],
                    $incidents[$i]['primary_fingerprint']===$cause?'repeated_fingerprint':'shared_evidence');
            }

            $incident=&$incidents[$i];
            if($incident['status']==='monitoring')$incident['status']='open';
            $duplicate=in_array($event['fingerprint'],$incident['event_fingerprints'],true);
            $incident['occurrence_count']++;
            $incident['first_seen_at']=min($incident['first_seen_at'],$event['occurred_at']);
            $incident['last_seen_at']=max($incident['last_seen_at'],$event['occurred_at']);
            $incident['last_event_at']=max($incident['last_event_at'],$event['occurred_at']);
            if(self::RANK[$event['severity']]>self::RANK[$incident['severity']])$incident['severity']=$event['severity'];
            self::evidence($incident,$event);
            if(!$duplicate)self::timeline($incident,$event,$incident['occurrence_count']===1?'incident.opened':'incident.repeated');
            unset($incident);
        }

        foreach($incidents as &$incident){
            sort($incident['event_fingerprints'],SORT_STRING);
            $timeline=IncidentTimeline::build([
                'version'=>1,'incident_ref'=>$incident['incident_id'],'opened_at'=>$incident['opened_at'],
                'detected_at'=>$incident['opened_at'],'recovered_at'=>$incident['resolved_at'],
                'events'=>$incident['timeline_events'],
            ]);
            if(count($timeline['events'])!==count($incident['timeline_events']))
                throw new InvalidArgumentException('Incident timeline invalid.');
            unset($incident['_keys']);
        }
        unset($incident);
        usort($incidents,static fn(array $a,array $b):int=>[$a['opened_at'],$a['incident_id']]<=>[$b['opened_at'],$b['incident_id']]);
        $basis=['version'=>1,'incidents'=>$incidents];
        return $basis+['fingerprint'=>self::hash($basis)];
    }

    private static function freshIncident(array $event,string $cause): array
    {
        $opened=$event['occurred_at'];
        return [
            'version'=>1,'incident_id'=>'incident:'.substr(hash('sha256',
                $event['project_id'].'|'.($event['environment_id']??'-').'|'.$cause.'|'.$opened),0,32),
            'project_id'=>$event['project_id'],'environment_id'=>$event['environment_id'],'status'=>'open',
            'severity'=>$event['severity'],'primary_fingerprint'=>$cause,'event_fingerprints'=>[],
            'correlation_reason'=>'new_incident','occurrence_count'=>0,'first_seen_at'=>$opened,
            'last_seen_at'=>$opened,'opened_at'=>$opened,'last_event_at'=>$opened,'resolved_at'=>null,
            'timeline_events'=>[],'_keys'=>self::keys($event),
        ];
    }

    private static function matchingIncident(array $incidents,array $event,string $cause,int $window): ?int
    {
        $matches=[];$keys=self::keys($event);
        foreach($incidents as $i=>$incident){
            $distance=$event['occurred_at']-$incident['last_event_at'];
            if($incident['status']==='resolved'||!self::sameScope($incident,$event)||$distance<0||$distance>$window)continue;
            if($incident['primary_fingerprint']===$cause||array_intersect($incident['_keys'],$keys)!==[])$matches[]=$i;
        }
        return count($matches)===1?$matches[0]:null;
    }

    private static function recover(array &$incidents,array $event): void
    {
        if($event['freshness']!=='fresh')return;
        $matches=[];$keys=self::keys($event);
        foreach($incidents as $i=>$incident){
            if($incident['status']!=='resolved'&&self::sameScope($incident,$event)
                &&array_intersect($incident['_keys'],$keys)!==[])$matches[]=$i;
        }
        if(count($matches)!==1)return;
        $i=$matches[0];self::evidence($incidents[$i],$event);
        $incidents[$i]['last_event_at']=max($incidents[$i]['last_event_at'],$event['occurred_at']);
        if(self::monitoring($event)){
            $incidents[$i]['status']='monitoring';self::timeline($incidents[$i],$event,'incident.monitoring');return;
        }
        $incidents[$i]['status']='resolved';$incidents[$i]['resolved_at']=$event['occurred_at'];
        self::timeline($incidents[$i],$event,'incident.resolved');
    }

    private static function matchingDeploy(array $deploys,array $failure,int $window): ?array
    {
        $refs=self::deployKeys($failure);if($refs===[])return null;$matches=[];
        foreach($deploys as $deploy){
            $age=$failure['occurred_at']-$deploy['occurred_at'];
            if(self::sameEventScope($deploy,$failure)&&$age>=0&&$age<=$window
                &&array_intersect($refs,self::deployKeys($deploy))!==[])$matches[]=$deploy;
        }
        if($matches===[])return null;
        usort($matches,[self::class,'compare']);return $matches[array_key_last($matches)];
    }

    private static function evidence(array &$incident,array $event): void
    {
        if(!in_array($event['fingerprint'],$incident['event_fingerprints'],true))
            $incident['event_fingerprints'][]=$event['fingerprint'];
        $incident['_keys']=array_values(array_unique(array_merge($incident['_keys'],self::keys($event))));
        sort($incident['_keys'],SORT_STRING);
    }

    private static function timeline(array &$incident,array $event,string $kind): void
    {
        $sequence=count($incident['timeline_events']);
        $incident['timeline_events'][]=[
            'event_id'=>'event-'.substr(hash('sha256',$event['fingerprint'].'|'.$kind.'|'.$sequence),0,24),
            'timestamp'=>$event['occurred_at'],'sequence'=>$sequence,'kind'=>$kind,'source'=>$event['source'],
            'evidence_ref'=>self::evidenceRef($event),
        ];
    }

    private static function timelineIfMissing(array &$incident,array $event,string $kind): void
    {
        $ref=self::evidenceRef($event);
        foreach($incident['timeline_events'] as $row)if($row['evidence_ref']===$ref)return;
        self::timeline($incident,$event,$kind);
    }

    private static function keys(array $event): array
    {
        $keys=[];
        foreach($event['correlation_keys'] as $key)
            if(!str_starts_with($key,'project:')&&!str_starts_with($key,'environment:'))$keys[$key]=true;
        foreach(['deployment_ref'=>'deployment','release_ref'=>'release','sha'=>'sha'] as $field=>$prefix){
            $value=$event['payload_allowlisted'][$field]??null;
            if(is_string($value)&&$value!=='')$keys[$prefix.':'.$value]=true;
        }
        $out=array_keys($keys);sort($out,SORT_STRING);return $out;
    }

    private static function deployKeys(array $event): array
    {
        return array_values(array_filter(self::keys($event),static fn(string $key):bool=>
            str_starts_with($key,'deployment:')||str_starts_with($key,'release:')||str_starts_with($key,'sha:')
        ));
    }

    private static function failure(array $event): bool
    {
        if(in_array($event['severity'],['error','critical'],true))return true;
        foreach(['status','conclusion','runner_status','application_status'] as $field)
            if(in_array(strtolower((string)($event['payload_allowlisted'][$field]??'')),self::FAIL,true))return true;
        return ($event['payload_allowlisted']['startup_failure']??false)===true;
    }

    private static function recovery(array $event): bool
    { return in_array(strtolower((string)($event['payload_allowlisted']['status']??'')),self::RECOVER,true); }

    private static function monitoring(array $event): bool
    { return in_array(strtolower((string)($event['payload_allowlisted']['status']??'')),self::MONITOR,true); }

    private static function deployContext(array $event): bool
    {
        return $event['source']==='deploy'
            &&in_array(strtolower((string)($event['payload_allowlisted']['status']??'')),['success','succeeded','deployed','completed'],true);
    }

    private static function sameScope(array $incident,array $event): bool
    { return $incident['project_id']===$event['project_id']&&$incident['environment_id']===$event['environment_id']; }

    private static function sameEventScope(array $a,array $b): bool
    { return $a['project_id']===$b['project_id']&&$a['environment_id']===$b['environment_id']; }

    private static function evidenceRef(array $event): string
    { return 'evidence:'.substr(hash('sha256',$event['fingerprint']),0,32); }

    private static function reason(array &$incident,string $candidate): void
    {
        $rank=['new_incident'=>0,'repeated_fingerprint'=>1,'shared_evidence'=>2,'post_deploy_explicit'=>3];
        if($rank[$candidate]>$rank[$incident['correlation_reason']])$incident['correlation_reason']=$candidate;
    }

    private static function compare(array $a,array $b): int
    { return [$a['occurred_at'],$a['source'],$a['type'],$a['fingerprint'],$a['received_at']]
        <=>[$b['occurred_at'],$b['source'],$b['type'],$b['fingerprint'],$b['received_at']]; }

    private static function window(int $value): void
    { if($value<1||$value>86400)throw new InvalidArgumentException('Incident window invalid.'); }

    private static function hash(array $value): string
    { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); }
}
