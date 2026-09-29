<?php
declare(strict_types=1);
require __DIR__ . '/../src/SchedulerCore.php';
require __DIR__ . '/../src/SchedulerSelection.php';

use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerSelection;

function rejected(callable $work): bool { try { $work(); return false; } catch (InvalidArgumentException) { return true; } }
function item(string $id='work-a', int $issue=339, array $replace=[]): array {
    return array_replace([
        'version'=>1,'work_item_id'=>$id,'project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#'.$issue,
        'type'=>'engineering','priority'=>'critical','state'=>'queued','dependency_ids'=>[],
        'required_capabilities'=>['php','review'],'generation'=>2,'attempt'=>1,
        'reservation_id'=>null,'assigned_session_id'=>null,
    ],$replace);
}
function context(array $replace=[]): array {
    return array_replace([
        'dependency_states'=>[],'approval_state'=>'approved','freeze_state'=>'clear','reservations'=>[],
        'capacity'=>['account_id'=>'account_main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],
        'expected_generation'=>2,'expected_owner_session_id'=>null,
    ],$replace);
}
function candidate(string $id='work-a', int $issue=339, bool $ready=true): array {
    $ctx=$ready?context():context(['freeze_state'=>'active']);
    return SchedulerCore::candidate(item($id,$issue),$ctx);
}
function telemetry(array $request,string $selected): array {
    $ready=[];$excluded=[];
    foreach($request['candidates'] as $row){
        if($row['readiness']['ready']){if($row['key']!==$selected)$ready[]=$row['key'];}
        else{$excluded[$row['key']]=$row['readiness']['reasons'];}
    }
    sort($ready,SORT_STRING);ksort($excluded,SORT_STRING);
    return ['ready_not_selected'=>$ready,'excluded'=>$excluded];
}
function decision(array $request,string $selected='work-a',array $replace=[]): array {
    return array_replace([
        'version'=>1,'policy_ref'=>'factory-dispatcher-v2','request_fingerprint'=>$request['fingerprint'],
        'selected_key'=>$selected,'selection_reason'=>'critical ready leaf selected by Factory dispatcher',
        'telemetry'=>telemetry($request,$selected),
    ],$replace);
}

$scenario=$argv[1]??'';
if($scenario==='request'){
    $valid=candidate();
    $wrong=$valid;$wrong['policy_ref']='local-ranking';
    $extra=$valid;$extra['authority']='admin';
    $badKey=$valid;$badKey['key']='Work-A';
    $badCapability=$valid;$badCapability['required_capabilities']=['review:admin'];
    $secretAccount=$valid;$secretAccount['account_id']='ghp_123456789012345678901234567890';
    echo json_encode([
        'valid'=>SchedulerSelection::request([$valid]),
        'wrong_policy'=>rejected(fn()=>SchedulerSelection::request([$wrong])),
        'extra'=>rejected(fn()=>SchedulerSelection::request([$extra])),
        'bad_key'=>rejected(fn()=>SchedulerSelection::request([$badKey])),
        'bad_capability'=>rejected(fn()=>SchedulerSelection::request([$badCapability])),
        'secret_account'=>rejected(fn()=>SchedulerSelection::request([$secretAccount])),
        'duplicate'=>rejected(fn()=>SchedulerSelection::request([$valid,$valid])),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='order'){
    $a=candidate('work-a',339);$b=candidate('work-b',340);
    $one=SchedulerSelection::request([$a,$b]);$two=SchedulerSelection::request([$b,$a]);
    echo json_encode(['same'=>$one===$two,'one'=>$one,'two'=>$two],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='drift'){
    $a=candidate('work-a',339);$b=candidate('work-b',340,false);
    $request=SchedulerSelection::request([$a,$b]);$valid=decision($request);
    $wrongPolicy=$valid;$wrongPolicy['policy_ref']='other';
    $wrongFingerprint=$valid;$wrongFingerprint['request_fingerprint']=str_repeat('a',64);
    $unknown=$valid;$unknown['selected_key']='work-z';
    $blocked=decision($request,'work-b');
    $currentDrift=[candidate('work-a',339,false),$b];
    echo json_encode([
        'valid'=>SchedulerSelection::validateDecision($request,$valid,[$a,$b]),
        'wrong_policy'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$wrongPolicy,[$a,$b])),
        'wrong_fingerprint'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$wrongFingerprint,[$a,$b])),
        'unknown_key'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$unknown,[$a,$b])),
        'blocked_key'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$blocked,[$a,$b])),
        'readiness_drift'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$valid,$currentDrift)),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='authority'){
    $a=candidate();$request=SchedulerSelection::request([$a]);$valid=decision($request);
    $generation=$valid;$generation['generation']=999;
    $caps=$valid;$caps['required_capabilities']=['root'];
    $authority=$valid;$authority['authority']='admin';
    echo json_encode([
        'candidate'=>$a,
        'validated'=>SchedulerSelection::validateDecision($request,$valid,[$a]),
        'generation_rejected'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$generation,[$a])),
        'capabilities_rejected'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$caps,[$a])),
        'authority_rejected'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$authority,[$a])),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='telemetry'){
    $a=candidate('work-a',339);$b=candidate('work-b',340);$c=candidate('work-c',341,false);
    $request=SchedulerSelection::request([$c,$b,$a]);$valid=decision($request,'work-a');
    $fabricated=$valid;$fabricated['telemetry']['ready_not_selected']=[];
    $secret=$valid;$secret['selection_reason']='token=supersecret123456';
    $pii=$valid;$pii['selection_reason']='notify john@example.com';
    $first=SchedulerSelection::validateDecision($request,$valid,[$a,$b,$c]);
    $second=SchedulerSelection::validateDecision($request,$valid,[$c,$a,$b]);
    echo json_encode([
        'same'=>$first===$second,'validated'=>$first,
        'fabricated'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$fabricated,[$a,$b,$c])),
        'secret'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$secret,[$a,$b,$c])),
        'pii'=>rejected(fn()=>SchedulerSelection::validateDecision($request,$pii,[$a,$b,$c])),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='pure'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerSelection.php'));
    $forbidden=['authority_order','select_next','unlock_impact','transversal_impact','displaced_cycles','new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec('];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode(['hits'=>$hits,'pure'=>$hits===[]],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown scheduler selection scenario\n");exit(2);
