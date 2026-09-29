<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/SchedulerSelection.php';
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/SchedulerAssignmentPlan.php';
require __DIR__.'/../src/SchedulerAssignmentCommit.php';

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Scheduler\PreparedSchedulerAssignmentPlan;
use ControlBot\Scheduler\SchedulerAssignmentCommit;
use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerSelection;
use ControlBot\Scheduler\ValidatedSchedulerSelection;

const NOW=10_000;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function work(array $r=[]): array { return array_replace([
    'version'=>1,'work_item_id'=>'work-a','project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#354',
    'type'=>'engineering','priority'=>'critical','state'=>'queued','dependency_ids'=>[],
    'required_capabilities'=>['php','review'],'generation'=>4,'attempt'=>1,
    'reservation_id'=>null,'assigned_session_id'=>null,
],$r); }
function context(): array { return [
    'dependency_states'=>[],'approval_state'=>'approved','freeze_state'=>'clear','reservations'=>[],
    'capacity'=>['account_id'=>'account-main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],
    'expected_generation'=>4,'expected_owner_session_id'=>null,
]; }
function session(array $r=[]): array { return array_replace([
    'version'=>1,'session_id'=>'session-main','agent_id'=>'agent-main','account_id'=>'account-main',
    'profile_alias'=>'Main','tab_id'=>'tab-main','status'=>'idle','assignment_id'=>null,
    'last_heartbeat_at'=>NOW-10,'mode'=>'chat','repository'=>null,'issue_number'=>null,
],$r); }
function agent(array $r=[]): array { return array_replace([
    'version'=>1,'agent_id'=>'agent-main','role'=>'engineering','capabilities'=>['php','review','qa'],
],$r); }
function selection(): ValidatedSchedulerSelection {
    $candidate=SchedulerCore::candidate(work(),context());
    $request=SchedulerSelection::request([$candidate]);
    $decision=[
        'version'=>1,'policy_ref'=>'factory-dispatcher-v2','request_fingerprint'=>$request['fingerprint'],
        'selected_key'=>'work-a','selection_reason'=>'critical ready leaf selected by Factory dispatcher',
        'telemetry'=>['ready_not_selected'=>[],'excluded'=>[]],
    ];
    return ValidatedSchedulerSelection::fromDecision($request,$decision,[$candidate]);
}
function prepared(): PreparedSchedulerAssignmentPlan {
    return PreparedSchedulerAssignmentPlan::fromSelection(
        selection(),work(),session(),agent(),'reservation-0001','assignment-0001',NOW
    );
}
function commit(mixed $plan=null,array $w=null,array $s=null,array $a=null,int $now=NOW): array {
    return SchedulerAssignmentCommit::commit($plan??prepared(),$w??work(),$s??session(),$a??agent(),$now);
}

$scenario=$argv[1]??'';
if($scenario==='cas'){
    echo json_encode([
        'valid'=>commit(),
        'fabricated'=>rejected(fn()=>commit(prepared()->value())),
        'work_drift'=>rejected(fn()=>commit(null,work(['attempt'=>2]))),
        'session_drift'=>rejected(fn()=>commit(null,null,session(['last_heartbeat_at'=>NOW-11]))),
        'agent_drift'=>rejected(fn()=>commit(null,null,null,agent(['capabilities'=>['php','review','qa','sre']]))),
        'clock_stale'=>rejected(fn()=>commit(null,null,null,null,NOW+200)),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='ownership'){
    echo json_encode([
        'reservation'=>rejected(fn()=>commit(null,work(['state'=>'reserved','reservation_id'=>'reservation-other']))),
        'work_assignment'=>rejected(fn()=>commit(null,work([
            'state'=>'assigned','reservation_id'=>'reservation-other','assigned_session_id'=>'session-other',
        ]))),
        'session_assignment'=>rejected(fn()=>commit(null,null,session(['status'=>'assigned','assignment_id'=>'assignment-other']))),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='binding'){
    echo json_encode(commit(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='atomic'){
    $result=commit();
    echo json_encode([
        'write_keys'=>array_keys($result['writes']),
        'top_keys'=>array_keys($result),
        'has_partial'=>isset($result['operations'])||isset($result['applied'])||isset($result['status']),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='deterministic'){
    $one=commit();$two=commit();
    echo json_encode([
        'same'=>$one===$two,
        'same_fingerprint'=>$one['commit_fingerprint']===$two['commit_fingerprint'],
        'state_bound'=>rejected(fn()=>commit(null,work(['attempt'=>2]))),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='pure'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerAssignmentCommit.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','runnergateway','factoryrunner'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    $result=commit();
    echo json_encode([
        'hits'=>$hits,'pure'=>$hits===[],
        'generation_preserved'=>$result['writes']['work_item']['generation']===work()['generation'],
        'attempt_preserved'=>$result['writes']['work_item']['attempt']===work()['attempt'],
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown scheduler assignment commit scenario\n");exit(2);
