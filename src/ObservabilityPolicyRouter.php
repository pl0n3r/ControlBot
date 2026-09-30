<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use ControlBot\Business\IncidentTimeline;
use ControlBot\Business\OwnerInbox;
use ControlBot\ExternalApi\ExternalApiPushNotification;
use ControlBot\Runtime\PauseControl;
use InvalidArgumentException;

final class ObservabilityPolicyRouter
{
    private const STATUS=['open','monitoring','resolved'];
    private const SEVERITY=['info','warning','error','critical'];
    private const FRESHNESS=['fresh','stale','unknown'];

    public static function project(array $incident,array $policy,array $evidence): array
    {
        $incident=self::incident($incident);
        $policy=self::policy($policy,$incident);
        $evidence=self::evidence($evidence);

        $identity=hash('sha256',$incident['incident_id'].'|'.$incident['primary_fingerprint'].'|'.$policy['policy_ref']);
        $class=$incident['status']==='resolved'
            ? 'fyi'
            : $policy['inbox_class_by_severity'][$incident['severity']];
        $authority=$class==='critical'?'L3_GROUP_INSTITUTION':null;
        $freshness=match($evidence['freshness']){'fresh'=>'current','stale'=>'stale',default=>'unknown'};

        $entryToken=implode('.',str_split(substr($identity,0,24)));
        $inbox=OwnerInbox::entry([
            'version'=>1,'entry_ref'=>'controlbot:inbox/incident-'.$entryToken,'class'=>$class,
            'scope'=>['kind'=>$policy['owner_scope_kind'],'ref'=>$policy['owner_scope_ref']],
            'title'=>'Incident '.$incident['severity'].' in '.$incident['project_id'],
            'summary'=>'Incident '.$incident['status'].' from observability policy.',
            'impact'=>$incident['status']==='resolved'?'Incident resolved; no active alert.':'Operational attention may be required.',
            'actor_ref'=>null,'required_authority_level'=>$authority,'decision_ref'=>null,'options_ref'=>null,'deadline_at'=>null,
            'source_ref'=>$freshness==='unknown'?null:$evidence['source_ref'],
            'evidence_refs'=>$freshness==='unknown'?[]:[$evidence['evidence_ref']],
            'observed_at'=>$freshness==='unknown'?null:$evidence['observed_at'],'freshness'=>$freshness,
        ]);

        $push=null;
        if($incident['status']!=='resolved'
            &&in_array('push',$policy['channels_by_severity'][$incident['severity']],true)){
            $push=ExternalApiPushNotification::payload([
                'version'=>1,'notification_ref'=>'notification:'.substr(hash('sha256','notify|'.$identity),0,32),
                'category'=>'critical_incident','severity'=>$incident['severity']==='critical'?'critical':'attention',
                'entity_ref'=>'controlbot:incident/'.substr($incident['incident_id'],9),
                'detail_operation_id'=>'owner_inbox.read','localization_key'=>'push.critical_incident',
                'issued_at'=>max(1,$incident['last_event_at']),
                'expires_at'=>max(1,$incident['last_event_at'])+$policy['push_ttl_seconds'],
                'dedupe_key'=>'dedupe:'.substr(hash('sha256','dedupe|'.$identity),0,32),
            ]);
        }

        $freeze=null;
        if($incident['status']!=='resolved'&&$incident['severity']==='critical'
            &&$evidence['freshness']==='fresh'&&$policy['freeze_on_critical']){
            $freeze=PauseControl::state([
                'version'=>1,'pause_id'=>'pause:obs-'.substr($identity,0,24),
                'scope_type'=>'project','scope_id'=>$policy['project_scope_id'],'state'=>'unknown',
                'reason'=>'critical incident policy freeze intent','source'=>'policy',
                'created_at'=>$incident['last_event_at'],'activated_at'=>null,'released_at'=>null,
                'preemptibility'=>'safe_point','safe_point_at'=>null,'policy_version'=>$policy['policy_ref'],
                'incident_id'=>$incident['incident_id'],'evidence_ref'=>$evidence['evidence_ref'],
            ]);
        }

        $result=['version'=>1,'incident_ref'=>$incident['incident_id'],'policy_ref'=>$policy['policy_ref'],
            'owner_inbox'=>$inbox,'push_notification'=>$push,'freeze_intent'=>$freeze];
        return $result+['fingerprint'=>hash('sha256',json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
    }

    private static function incident(array $row): array
    {
        self::fields($row,['version','incident_id','project_id','environment_id','status','severity','primary_fingerprint',
            'event_fingerprints','correlation_reason','occurrence_count','first_seen_at','last_seen_at','opened_at',
            'last_event_at','resolved_at','timeline_events'],'Incident');
        if(($row['version']??null)!==1||preg_match('/^incident:[a-f0-9]{32}$/D',(string)$row['incident_id'])!==1)
            throw new InvalidArgumentException('Incident identity invalid.');
        self::ref($row['project_id'],'project_id'); self::nullableRef($row['environment_id'],'environment_id');
        self::choice($row['status'],self::STATUS,'status'); self::choice($row['severity'],self::SEVERITY,'severity');
        if(preg_match('/^[a-f0-9]{64}$/D',(string)$row['primary_fingerprint'])!==1) throw new InvalidArgumentException('Incident fingerprint invalid.');
        if(!is_int($row['occurrence_count'])||$row['occurrence_count']<1)
            throw new InvalidArgumentException('Incident occurrence invalid.');
        if(!is_array($row['event_fingerprints'])||!array_is_list($row['event_fingerprints'])||$row['event_fingerprints']===[])
            throw new InvalidArgumentException('Incident evidence invalid.');
        $seen=[];
        foreach($row['event_fingerprints'] as $fp){
            if(!is_string($fp)||preg_match('/^[a-f0-9]{64}$/D',$fp)!==1||isset($seen[$fp]))
                throw new InvalidArgumentException('Incident evidence invalid.');
            $seen[$fp]=true;
        }
        if(!isset($seen[$row['primary_fingerprint']])) throw new InvalidArgumentException('Primary fingerprint missing.');
        self::choice($row['correlation_reason'],['new_incident','repeated_fingerprint','shared_evidence','post_deploy_explicit'],'correlation_reason');
        foreach(['first_seen_at','last_seen_at','opened_at','last_event_at'] as $key)
            if(!is_int($row[$key])||$row[$key]<0) throw new InvalidArgumentException('Incident time invalid.');
        if($row['opened_at']!==$row['first_seen_at']||$row['first_seen_at']>$row['last_seen_at']
            ||$row['last_seen_at']>$row['last_event_at']) throw new InvalidArgumentException('Incident chronology invalid.');
        if($row['status']==='resolved'){
            if(!is_int($row['resolved_at'])||$row['resolved_at']!==$row['last_event_at'])
                throw new InvalidArgumentException('Resolved incident evidence invalid.');
        } elseif($row['resolved_at']!==null) throw new InvalidArgumentException('Open incident resolution invalid.');
        if(!is_array($row['timeline_events'])||!array_is_list($row['timeline_events'])||$row['timeline_events']===[])
            throw new InvalidArgumentException('Incident timeline invalid.');
        foreach($row['timeline_events'] as $index=>$event){
            if(!is_array($event)||($event['sequence']??null)!==$index
                ||!in_array($event['kind']??null,['incident.opened','incident.repeated','deploy.observed','incident.monitoring','incident.resolved'],true)
                ||!in_array($event['source']??null,['health','ci','deploy','agent'],true))
                throw new InvalidArgumentException('Incident timeline provenance invalid.');
        }
        IncidentTimeline::build(['version'=>1,'incident_ref'=>$row['incident_id'],'opened_at'=>$row['opened_at'],
            'detected_at'=>$row['opened_at'],'recovered_at'=>$row['resolved_at'],'events'=>$row['timeline_events']]);
        return $row;
    }

    private static function policy(array $row,array $incident): array
    {
        self::fields($row,['version','policy_ref','freeze_on_critical','project_scope_id','owner_scope_kind','owner_scope_ref',
            'push_ttl_seconds','inbox_class_by_severity','channels_by_severity'],'Policy');
        if(($row['version']??null)!==1||!is_bool($row['freeze_on_critical'])) throw new InvalidArgumentException('Policy invalid.');
        self::ref($row['policy_ref'],'policy_ref'); self::ref($row['project_scope_id'],'project_scope_id');
        if($row['project_scope_id']!==$incident['project_id']) throw new InvalidArgumentException('Policy project scope mismatch.');
        if(!in_array($row['owner_scope_kind'],['group','venture','project','institution'],true)) throw new InvalidArgumentException('Owner scope kind invalid.');
        if(!is_string($row['owner_scope_ref'])||!str_starts_with($row['owner_scope_ref'],'controlbot:'.$row['owner_scope_kind'].'/'))
            throw new InvalidArgumentException('Owner scope ref invalid.');
        if(!is_int($row['push_ttl_seconds'])||$row['push_ttl_seconds']<60||$row['push_ttl_seconds']>86400)
            throw new InvalidArgumentException('Push TTL invalid.');
        $classes=['info'=>'fyi','warning'=>'watch','error'=>'watch','critical'=>'critical'];
        if(!is_array($row['inbox_class_by_severity'])||array_is_list($row['inbox_class_by_severity']))
            throw new InvalidArgumentException('Policy inbox classes invalid.');
        self::fields($row['inbox_class_by_severity'],array_keys($classes),'Policy inbox classes');
        foreach($classes as $severity=>$class)
            if(($row['inbox_class_by_severity'][$severity]??null)!==$class)
                throw new InvalidArgumentException('Policy inbox classes invalid.');
        $expected=['info'=>['inbox'],'warning'=>['inbox'],'error'=>['inbox','push'],'critical'=>['inbox','push']];
        if(!is_array($row['channels_by_severity'])||array_is_list($row['channels_by_severity']))
            throw new InvalidArgumentException('Policy channels invalid.');
        self::fields($row['channels_by_severity'],array_keys($expected),'Policy channels');
        foreach($expected as $severity=>$channels){
            $actual=$row['channels_by_severity'][$severity]??null;
            if(!is_array($actual)||!array_is_list($actual)||count($actual)!==count(array_unique($actual)))
                throw new InvalidArgumentException('Policy channels invalid.');
            sort($actual,SORT_STRING);$wanted=$channels;sort($wanted,SORT_STRING);
            if($actual!==$wanted) throw new InvalidArgumentException('Policy channels invalid.');
        }
        return $row;
    }

    private static function evidence(array $row): array
    {
        self::fields($row,['version','freshness','source_ref','evidence_ref','observed_at'],'Evidence');
        if(($row['version']??null)!==1) throw new InvalidArgumentException('Evidence version invalid.');
        $fresh=self::choice($row['freshness'],self::FRESHNESS,'freshness');
        if($fresh==='unknown'){
            if($row['source_ref']!==null||$row['evidence_ref']!==null||$row['observed_at']!==null) throw new InvalidArgumentException('Unknown evidence provenance invalid.');
            return $row;
        }
        self::ref($row['source_ref'],'source_ref'); self::ref($row['evidence_ref'],'evidence_ref');
        if(!is_int($row['observed_at'])||$row['observed_at']<0) throw new InvalidArgumentException('observed_at invalid.');
        return $row;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,179}$/D',$value)!==1
            ||preg_match('/(?:password|passwd|bearer\s+|token\s*[:=]|secret\s*[:=]|cookie\s*[:=]|authorization\s*[:=]|@)/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function nullableRef(mixed $value,string $label): ?string{return $value===null?null:self::ref($value,$label);}
    private static function choice(mixed $value,array $allowed,string $label): string
    {if(!is_string($value)||!in_array($value,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function fields(array $row,array $expected,string $label): void
    {$keys=array_keys($row);sort($keys);sort($expected);if($keys!==$expected)throw new InvalidArgumentException($label.' fields invalid.');}
}
