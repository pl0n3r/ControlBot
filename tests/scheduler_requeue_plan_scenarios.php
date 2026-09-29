<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/PresenceAdapter.php';
require __DIR__.'/../src/SchedulerRequeuePlan.php';

use ControlBot\Runtime\PresenceAdapter;
use ControlBot\Scheduler\SchedulerRequeuePlan;

const NOW=10_000;

function rejected(callable $fn): bool {
    try {$fn(); return false;} catch(InvalidArgumentException) {return true;}
}
function account(): array { return [
    'version'=>1,'account_id'=>'account-main','provider_id'=>'chatgpt','account_alias'=>'Main',
    'plan'=>'plus','capacity'=>1,'status'=>'active',
]; }
function agent(): array { return [
    'version'=>1,'agent_id'=>'agent-main','role'=>'engineering','capabilities'=>['php','review'],
]; }
function assignment(array $r=[]): array { return array_replace([
    'version'=>1,'assignment_id'=>'assignment-0001','session_id'=>'session-main',
    'project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#363','status'=>'running',
],$r); }
function session(array $r=[]): array { return array_replace([
    'version'=>1,'session_id'=>'session-main','agent_id'=>'agent-main','account_id'=>'account-main',
    'profile_alias'=>'Main','tab_id'=>'tab-main','status'=>'working','assignment_id'=>'assignment-0001',
    'last_heartbeat_at'=>NOW-10,'mode'=>'chat','repository'=>'pl0n3r/ControlBot','issue_number'=>363,
],$r); }
function work(array $r=[]): array { return array_replace([
    'version'=>1,'work_item_id'=>'work-a','project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#363',
    'type'=>'engineering','priority'=>'critical','state'=>'running','dependency_ids'=>[],
    'required_capabilities'=>['php','review'],'generation'=>4,'attempt'=>2,
    'reservation_id'=>'reservation-0001','assigned_session_id'=>'session-main',
],$r); }
function reservation(array $r=[]): array { return array_replace([
    'reservation_id'=>'reservation-0001','owner_session_id'=>'session-main','generation'=>4,'active'=>true,
],$r); }
function handoff(array $r=[]): array { return array_replace([
    'version'=>1,'handoff_id'=>'handoff-0001','assignment_id'=>'assignment-0001',
    'from_session_id'=>'session-main','to_session_id'=>null,
    'objective'=>'Continue scheduler issue safely','issue_ref'=>'pl0n3r/ControlBot#363','pr_ref'=>null,
    'sha'=>str_repeat('a',40),'last_result'=>'Heartbeat became stale before completion',
    'evidence_ref'=>'controlbot:heartbeat-stale','blocker'=>'Session heartbeat stale',
    'next_action'=>'Requeue with a new generation before selecting another session',
],$r); }
function snapshot(
    int $now,
    array $sessionOverrides=[],
    int $generation=4,
    int $attempt=2,
    bool $safePoint=true,
    bool $nonPreemptible=false,
    bool $include=true
): array {
    $rows=[];
    if($include){
        $rows[]=[
            'session'=>session($sessionOverrides),
            'agent'=>agent(),
            'assignment'=>assignment(),
            'claims'=>['src/SchedulerRequeuePlan.php'],
            'generation'=>$generation,
            'attempt'=>$attempt,
            'safe_point'=>$safePoint,
            'non_preemptible'=>$nonPreemptible,
        ];
    }
    return PresenceAdapter::snapshot(
        [account()],
        $rows,
        $now,
        90,
        300,
        ['account-main'=>[
            'version'=>1,'state'=>'healthy','total_capacity'=>1,'occupied_capacity'=>$include?1:0,'observed_at'=>$now,
        ]]
    );
}
function before(): array { return snapshot(NOW); }
function after(
    int $generation=4,
    bool $safePoint=true,
    bool $nonPreemptible=false
): array {
    return snapshot(NOW+100,[], $generation,2,$safePoint,$nonPreemptible);
}
function plan(
    array $beforePresence=null,array $afterPresence=null,array $w=null,array $a=null,array $r=null,array $h=null,string $sid='session-main'
): array {
    return SchedulerRequeuePlan::plan(
        $beforePresence??before(),$afterPresence??after(),$w??work(),$a??assignment(),$r??reservation(),$h??handoff(),$sid
    );
}

$scenario=$argv[1]??'';
if($scenario==='transition'){
    $noChange=snapshot(NOW);
    $stale=after();
    $recoveryBefore=$stale;
    $recoveryAfter=snapshot(NOW+101,['last_heartbeat_at'=>NOW+100]);
    echo json_encode([
        'valid'=>plan(),
        'no_change'=>rejected(fn()=>plan(before(),$noChange)),
        'leave'=>rejected(fn()=>plan(before(),snapshot(NOW+100,[],4,2,true,false,false))),
        'recovery'=>rejected(fn()=>plan($recoveryBefore,$recoveryAfter)),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='guard'){
    echo json_encode([
        'stale_generation'=>rejected(fn()=>plan(before(),after(3))),
        'unsafe_preemption'=>rejected(fn()=>plan(before(),after(4,false,true))),
        'safe_non_preemptible'=>!rejected(fn()=>plan(before(),after(4,true,true))),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ownership'){
    echo json_encode([
        'reservation_owner'=>rejected(fn()=>plan(null,null,null,null,reservation(['owner_session_id'=>'session-other']))),
        'reservation_generation'=>rejected(fn()=>plan(null,null,null,null,reservation(['generation'=>3]))),
        'assignment_source'=>rejected(fn()=>plan(null,null,null,assignment(['source_ref'=>'pl0n3r/ControlBot#362']))),
        'handoff_assignment'=>rejected(fn()=>plan(null,null,null,null,null,handoff(['assignment_id'=>'assignment-other']))),
        'handoff_target'=>rejected(fn()=>plan(null,null,null,null,null,handoff(['to_session_id'=>'session-next']))),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='requeue'){
    $out=plan();
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='owner_guard'){
    $out=plan();
    echo json_encode([
        'before'=>SchedulerRequeuePlan::ownerEventGuard(work(),'session-main',4),
        'old_after'=>SchedulerRequeuePlan::ownerEventGuard($out['work_item'],'session-main',4),
        'new_generation_unowned'=>SchedulerRequeuePlan::ownerEventGuard($out['work_item'],'session-main',5),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='deterministic'){
    $one=plan();$two=plan();
    $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerRequeuePlan.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','runnergateway','factoryrunner','selected_key','ranking','rank'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode([
        'same'=>$one===$two,
        'same_fingerprint'=>$one['fingerprint']===$two['fingerprint'],
        'hits'=>$hits,
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown scheduler requeue plan scenario\n");exit(2);
