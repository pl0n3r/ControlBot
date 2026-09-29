<?php
declare(strict_types=1);
require __DIR__.'/../src/IncidentLesson.php';
use ControlBot\Business\IncidentLesson;

const A='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const B='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const C='cccccccccccccccccccccccccccccccc';
const D='dddddddddddddddddddddddddddddddd';
const E='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

function finding(string $ref,string $class,string $relation,string $summary,string $evidence='supported',bool $owner=false): array {
    return ['finding_ref'=>'finding:'.$ref,'classification'=>$class,'evidence_ref'=>'evidence:'.$ref,
        'evidence_state'=>$evidence,'evidence_relation'=>$relation,'summary'=>$summary,'owner_action_required'=>$owner];
}
function postmortem(bool $uncertain=false,bool $reverse=false): array {
    $rows=[
        finding(A,'root_cause','necessary_cause','Private Actions capacity was exhausted for the incident period.'),
        finding(B,'independent_bug','independent_defect','Coordination caller lacked checks write permission.'),
        finding(C,'contributing_factor','contributor','Hourly coordination fan out increased pressure during recovery.'),
        finding(D,'preventive_change','preventive_only','Production observer cadence moved from one hour to six hours.'),
        $uncertain
            ? finding(E,'unknown','unresolved','Billing hard stop mechanism remains unverified.','incomplete',true)
            : finding(E,'contributing_factor','contributor','Recovery queue was serialized after the canary.'),
    ];
    if($reverse) $rows=array_reverse($rows);
    return ['version'=>1,'incident_ref'=>'incident:'.A,'duration_seconds'=>110,'findings'=>$rows,
        'recovery'=>['canary_issue'=>86,'mode'=>'serial','fan_out'=>false,'serial_queue'=>[72,77,80,71,84,73,75]]];
}
function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
$out=match($case){
    'valid'=>IncidentLesson::candidate(postmortem()),
    'deterministic'=>[
        'first'=>IncidentLesson::candidate(postmortem()),
        'permuted'=>IncidentLesson::candidate(postmortem(false,true)),
    ],
    'secret'=>(function():array{
        $token=postmortem();$token['findings'][0]['summary']='Bearer super-secret-token';
        $email=postmortem();$email['findings'][0]['summary']='Contact owner@example.com for details.';
        return ['token'=>rejected(fn()=>IncidentLesson::candidate($token)),'email'=>rejected(fn()=>IncidentLesson::candidate($email))];
    })(),
    'uncertain'=>IncidentLesson::candidate(postmortem(true)),
    'independent'=>IncidentLesson::candidate(postmortem()),
    'incident78'=>IncidentLesson::candidate(postmortem(true)),
    default=>throw new InvalidArgumentException('Unknown scenario.'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
