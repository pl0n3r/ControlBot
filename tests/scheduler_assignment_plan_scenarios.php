<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/SchedulerSelection.php';
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/SchedulerAssignmentPlan.php';

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Scheduler\SchedulerAssignmentPlan;
use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerSelection;

const NOW = 10_000;

function rejected(callable $work): bool
{
    try { $work(); return false; } catch (InvalidArgumentException) { return true; }
}

function work(array $replace=[]): array
{
    return array_replace([
        'version'=>1,'work_item_id'=>'work-a','project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#350',
        'type'=>'engineering','priority'=>'critical','state'=>'queued','dependency_ids'=>[],
        'required_capabilities'=>['php','review'],'generation'=>3,'attempt'=>1,
        'reservation_id'=>null,'assigned_session_id'=>null,
    ],$replace);
}

function scheduler_context(array $replace=[]): array
{
    return array_replace([
        'dependency_states'=>[],'approval_state'=>'approved','freeze_state'=>'clear','reservations'=>[],
        'capacity'=>['account_id'=>'account-main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],
        'expected_generation'=>3,'expected_owner_session_id'=>null,
    ],$replace);
}

function validated_selection(array $workOverride=[],array $contextOverride=[]): array
{
    $candidate=SchedulerCore::candidate(work($workOverride),scheduler_context($contextOverride));
    $request=SchedulerSelection::request([$candidate]);
    $decision=[
        'version'=>1,'policy_ref'=>'factory-dispatcher-v2','request_fingerprint'=>$request['fingerprint'],
        'selected_key'=>$candidate['key'],'selection_reason'=>'critical ready leaf selected by Factory dispatcher',
        'telemetry'=>['ready_not_selected'=>[],'excluded'=>[]],
    ];
    return SchedulerSelection::validateDecision($request,$decision,[$candidate]);
}

function session(array $replace=[]): array
{
    return array_replace([
        'version'=>1,'session_id'=>'session-main','agent_id'=>'agent-main','account_id'=>'account-main',
        'profile_alias'=>'Main','tab_id'=>'tab-main','status'=>'idle','assignment_id'=>null,
        'last_heartbeat_at'=>NOW-10,'mode'=>'chat','repository'=>null,'issue_number'=>null,
    ],$replace);
}

function agent(array $replace=[]): array
{
    return array_replace([
        'version'=>1,'agent_id'=>'agent-main','role'=>'engineering','capabilities'=>['php','review','qa'],
    ],$replace);
}

function plan(array $selection=null,array $workRaw=null,array $sessionRaw=null,array $agentRaw=null,string $reservation='reservation-0001',string $assignment='assignment-0001'): array
{
    return SchedulerAssignmentPlan::plan(
        $selection??validated_selection(),$workRaw??work(),$sessionRaw??session(),$agentRaw??agent(),
        $reservation,$assignment,NOW
    );
}

$scenario=$argv[1]??'';
if($scenario==='selection'){
    $base=validated_selection();
    $fields=['key','source_ref','generation','required_capabilities','account_id','priority'];
    $rejected=[];
    foreach($fields as $field){
        $changed=$base;
        $changed['selected'][$field]=match($field){
            'key'=>'work-b','source_ref'=>'pl0n3r/ControlBot#351','generation'=>4,
            'required_capabilities'=>['php'],'account_id'=>'account-other','priority'=>'high',
        };
        if($field==='key') $changed['selected_key']='work-b';
        $rejected[$field]=rejected(fn()=>plan($changed));
    }
    echo json_encode(['valid'=>plan($base),'rejected'=>$rejected],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='target'){
    echo json_encode([
        'valid'=>plan(),
        'stale'=>rejected(fn()=>plan(null,null,session(['last_heartbeat_at'=>NOW-200]))),
        'busy'=>rejected(fn()=>plan(null,null,session(['status'=>'assigned','assignment_id'=>'assignment-old']))),
        'account'=>rejected(fn()=>plan(null,null,session(['account_id'=>'account-other']))),
        'agent_identity'=>rejected(fn()=>plan(null,null,null,agent(['agent_id'=>'agent-other']))),
        'capability'=>rejected(fn()=>plan(null,null,null,agent(['capabilities'=>['php']]))),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='binding'){
    $result=plan();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='ownership'){
    $reserved=work(['state'=>'reserved','reservation_id'=>'reservation-old']);
    $assigned=work(['state'=>'assigned','reservation_id'=>'reservation-old','assigned_session_id'=>'session-old']);
    $occupied=session(['assignment_id'=>'assignment-old']);
    $staleSelection=validated_selection();$staleSelection['selected']['generation']=2;
    echo json_encode([
        'reserved'=>rejected(fn()=>plan(null,$reserved)),
        'assigned'=>rejected(fn()=>plan(null,$assigned)),
        'occupied'=>rejected(fn()=>plan(null,null,$occupied)),
        'stale_generation'=>rejected(fn()=>plan($staleSelection)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='deterministic'){
    $one=plan();$two=plan();
    $workChanged=plan(null,work(['attempt'=>2]));
    $sessionChanged=plan(null,null,session(['last_heartbeat_at'=>NOW-11]));
    echo json_encode([
        'same'=>$one===$two,
        'fingerprint_same'=>$one['fingerprint']===$two['fingerprint'],
        'work_cas_changed'=>$one['preconditions']['cas_sha256']!==$workChanged['preconditions']['cas_sha256'],
        'session_cas_changed'=>$one['preconditions']['cas_sha256']!==$sessionChanged['preconditions']['cas_sha256'],
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='pure'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerAssignmentPlan.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','runnergateway','factoryrunner'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    $result=plan();
    echo json_encode([
        'hits'=>$hits,'pure'=>$hits===[],
        'generation_preserved'=>$result['work_item']['generation']===work()['generation'],
        'attempt_preserved'=>$result['work_item']['attempt']===work()['attempt'],
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown scheduler assignment plan scenario\n");exit(2);
