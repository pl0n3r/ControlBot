<?php
declare(strict_types=1);
require __DIR__.'/../src/IncidentTimeline.php';require __DIR__.'/../src/Postmortem.php';require __DIR__.'/../src/IncidentLesson.php';require __DIR__.'/../src/IncidentUi.php';
use ControlBot\Business\IncidentTimeline;use ControlBot\Business\Postmortem;use ControlBot\Business\IncidentLesson;use ControlBot\Business\IncidentUi;
const A='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';const B='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';const C='cccccccccccccccccccccccccccccccc';const D='dddddddddddddddddddddddddddddddd';const E='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
function bundle(bool $unknown=false): array{
 $timeline=IncidentTimeline::build(['version'=>1,'incident_ref'=>'incident:'.A,'opened_at'=>100,'detected_at'=>110,'recovered_at'=>220,'events'=>[
  ['event_id'=>'event-opened','timestamp'=>100,'sequence'=>1,'kind'=>'incident.opened','source'=>'controlbot','evidence_ref'=>'evidence:'.B],
  ['event_id'=>'event-capacity','timestamp'=>120,'sequence'=>2,'kind'=>'capacity.exhausted','source'=>'github_actions','evidence_ref'=>'evidence:'.A],
  ['event_id'=>'event-recovered','timestamp'=>220,'sequence'=>3,'kind'=>'incident.recovered','source'=>'owner_report','evidence_ref'=>'evidence:'.C],]]);
 $findings=[
  ['finding_ref'=>'finding:'.A,'classification'=>'root_cause','evidence_ref'=>'evidence:'.A,'evidence_state'=>'supported','evidence_relation'=>'necessary_cause','summary'=>'Private Actions capacity was exhausted for the incident period.','owner_action_required'=>false],
  ['finding_ref'=>'finding:'.B,'classification'=>'independent_bug','evidence_ref'=>'evidence:'.B,'evidence_state'=>'supported','evidence_relation'=>'independent_defect','summary'=>'Coordination caller lacked checks write permission.','owner_action_required'=>false],
  ['finding_ref'=>'finding:'.C,'classification'=>'contributing_factor','evidence_ref'=>'evidence:'.C,'evidence_state'=>'supported','evidence_relation'=>'contributor','summary'=>'Hourly coordination fan out increased pressure during recovery.','owner_action_required'=>false],
  ['finding_ref'=>'finding:'.D,'classification'=>'preventive_change','evidence_ref'=>'evidence:'.D,'evidence_state'=>'supported','evidence_relation'=>'preventive_only','summary'=>'Production observer cadence moved from one hour to six hours.','owner_action_required'=>false],
  ['finding_ref'=>'finding:'.E,'classification'=>$unknown?'unknown':'contributing_factor','evidence_ref'=>'evidence:'.E,'evidence_state'=>$unknown?'incomplete':'supported','evidence_relation'=>$unknown?'unresolved':'contributor','summary'=>$unknown?'Billing mechanism remains unverified.':'Recovery queue was serialized after the canary.','owner_action_required'=>$unknown],];
 $post=Postmortem::analyze(['version'=>1,'incident_ref'=>'incident:'.A,'findings'=>$findings,'recovery'=>['canary_issue'=>86,'mode'=>'serial','fan_out'=>false,'serial_queue'=>[72,77,80,71,84,73,75]]],$timeline);
 return [$timeline,$post,IncidentLesson::candidate($post)];
}
function rejected(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
$name=$argv[1]??'';$b=bundle($name==='unknown');
if($name==='valid'||$name==='incident78'||$name==='unknown') echo IncidentUi::render(...$b);
elseif($name==='escape'){ $b[2]['title']='<script>alert(1)</script>';echo IncidentUi::render(...$b); }
elseif($name==='secret'){ $b[2]['summary']='Bearer should-not-render';echo json_encode(['rejected'=>rejected(fn()=>IncidentUi::render(...$b))]); }
else{fwrite(STDERR,"unknown scenario\n");exit(2);}
