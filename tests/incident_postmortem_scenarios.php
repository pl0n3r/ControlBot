<?php
declare(strict_types=1);

require __DIR__.'/../src/IncidentTimeline.php';
require __DIR__.'/../src/Postmortem.php';

use ControlBot\Business\IncidentTimeline;
use ControlBot\Business\Postmortem;

const A='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const B='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const C='cccccccccccccccccccccccccccccccc';
const D='dddddddddddddddddddddddddddddddd';
const E='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const RECOVERY_QUEUE=[72,77,80,71,84,73,75];

function timeline(?int $recovered=220,array $events=[]): array {
    if($events===[]) $events=[
        ['event_id'=>'event-capacity','timestamp'=>120,'sequence'=>2,'kind'=>'capacity.exhausted','source'=>'github_actions','evidence_ref'=>'evidence:'.A],
        ['event_id'=>'event-opened','timestamp'=>100,'sequence'=>1,'kind'=>'incident.opened','source'=>'controlbot','evidence_ref'=>'evidence:'.B],
        ['event_id'=>'event-recovered','timestamp'=>220,'sequence'=>3,'kind'=>'incident.recovered','source'=>'owner_report','evidence_ref'=>'evidence:'.C],
    ];
    return IncidentTimeline::build([
        'version'=>1,'incident_ref'=>'incident:'.A,'opened_at'=>100,'detected_at'=>110,'recovered_at'=>$recovered,'events'=>$events,
    ]);
}
function findings(bool $unknown=false): array {
    return [
        ['finding_ref'=>'finding:'.A,'classification'=>'root_cause','evidence_ref'=>'evidence:'.A,'evidence_state'=>'supported',
         'evidence_relation'=>'necessary_cause','summary'=>'Private Actions capacity was exhausted for the incident period.','owner_action_required'=>false],
        ['finding_ref'=>'finding:'.B,'classification'=>'independent_bug','evidence_ref'=>'evidence:'.B,'evidence_state'=>'supported',
         'evidence_relation'=>'independent_defect','summary'=>'Coordination caller lacked checks write permission.','owner_action_required'=>false],
        ['finding_ref'=>'finding:'.C,'classification'=>'contributing_factor','evidence_ref'=>'evidence:'.C,'evidence_state'=>'supported',
         'evidence_relation'=>'contributor','summary'=>'Hourly coordination fan out increased pressure during recovery.','owner_action_required'=>false],
        ['finding_ref'=>'finding:'.D,'classification'=>'preventive_change','evidence_ref'=>'evidence:'.D,'evidence_state'=>'supported',
         'evidence_relation'=>'preventive_only','summary'=>'Production observer cadence moved from one hour to six hours.','owner_action_required'=>false],
        ['finding_ref'=>'finding:'.E,'classification'=>$unknown?'unknown':'contributing_factor','evidence_ref'=>'evidence:'.E,
         'evidence_state'=>$unknown?'incomplete':'supported','evidence_relation'=>$unknown?'unresolved':'contributor',
         'summary'=>$unknown?'Billing hard stop mechanism remains unverified.':'Recovery queue was serialized after the canary.',
         'owner_action_required'=>$unknown],
    ];
}
function postmortem(bool $unknown=false,array $recovery=[],?array $customFindings=null): array {
    return Postmortem::analyze([
        'version'=>1,'incident_ref'=>'incident:'.A,'findings'=>$customFindings??findings($unknown),
        'recovery'=>$recovery?:['canary_issue'=>86,'mode'=>'serial','fan_out'=>false,'serial_queue'=>RECOVERY_QUEUE],
    ],timeline());
}
function bad(callable $fn): bool { try{$fn();return false;}catch(\InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='timeline'){
    $out=timeline();
}elseif($case==='missing_recovery'){
    $out=timeline(null,[
        ['event_id'=>'event-opened','timestamp'=>100,'sequence'=>1,'kind'=>'incident.opened','source'=>'controlbot','evidence_ref'=>'evidence:'.B],
    ]);
}elseif($case==='postmortem'){
    $out=postmortem();
}elseif($case==='unknown'){
    $incomplete=postmortem(true);
    $contradictory=findings(true);
    $contradictory[4]['evidence_state']='contradictory';
    $out=['incomplete'=>$incomplete,'contradictory'=>postmortem(false,[],$contradictory)];
}elseif($case==='observer_reclassified'){
    $rows=findings();
    $rows[3]['classification']='root_cause';
    $out=['rejected'=>bad(fn()=>postmortem(false,[],$rows))];
}elseif($case==='dated_summary'){
    $rows=findings();
    $rows[3]['summary']='Production observer cadence changed on 2026-07-30.';
    $out=['accepted'=>!bad(fn()=>postmortem(false,[],$rows))];
}elseif($case==='bad_recovery'){
    $base=['canary_issue'=>86,'mode'=>'serial','fan_out'=>false,'serial_queue'=>RECOVERY_QUEUE];
    $fanout=$base;$fanout['fan_out']=true;
    $parallel=$base;$parallel['mode']='parallel';
    $duplicate=$base;$duplicate['serial_queue']=[72,72];
    $canary=$base;$canary['serial_queue']=[86,72];
    $out=[
        'fanout'=>bad(fn()=>postmortem(false,$fanout)),
        'parallel'=>bad(fn()=>postmortem(false,$parallel)),
        'duplicate'=>bad(fn()=>postmortem(false,$duplicate)),
        'canary_repeated'=>bad(fn()=>postmortem(false,$canary)),
    ];
}elseif($case==='source'){
    $out=['timeline'=>file_get_contents(__DIR__.'/../src/IncidentTimeline.php'),
          'postmortem'=>file_get_contents(__DIR__.'/../src/Postmortem.php')];
}else{fwrite(STDERR,"unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
