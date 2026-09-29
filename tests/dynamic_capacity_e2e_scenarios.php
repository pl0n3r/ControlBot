<?php
declare(strict_types=1);

require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/PresenceAdapter.php';
require __DIR__.'/../src/SchedulerCore.php';

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Runtime\PresenceAdapter;
use ControlBot\Scheduler\SchedulerCore;

const NOW=1000;
const ISSUE=217;
const ASSIGNMENT='assignment-217';

function account(): array {
    return [
        'version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web',
        'account_alias'=>'Primary','plan'=>'declared-one','capacity'=>1,'status'=>'active',
    ];
}
function agent(string $session): array {
    return ['version'=>1,'agent_id'=>'agent-'.$session,'role'=>'engineer','capabilities'=>['php','review']];
}
function sessionRow(
    string $session,
    string $state,
    bool $assigned,
    int $heartbeat,
    int $generation,
): array {
    $sessionRecord=[
        'version'=>1,'session_id'=>$session,'agent_id'=>'agent-'.$session,'account_id'=>'account_main',
        'profile_alias'=>'Primary','tab_id'=>'tab-'.$session,'status'=>$state,
        'assignment_id'=>$assigned?ASSIGNMENT:null,'last_heartbeat_at'=>$heartbeat,'mode'=>'web',
        'repository'=>$assigned?'pl0n3r/ControlBot':null,'issue_number'=>$assigned?ISSUE:null,
    ];
    $assignment=$assigned?[
        'version'=>1,'assignment_id'=>ASSIGNMENT,'session_id'=>$session,'project_id'=>'project-controlbot',
        'source_ref'=>'pl0n3r/ControlBot#'.ISSUE,'status'=>'running',
    ]:null;
    return [
        'session'=>$sessionRecord,'agent'=>agent($session),'assignment'=>$assignment,
        'claims'=>['src/SchedulerCore.php'],'generation'=>$generation,'attempt'=>1,
        'safe_point'=>true,'non_preemptible'=>false,
    ];
}
function observation(string $state='healthy',int $occupied=0): array {
    return ['account_main'=>[
        'version'=>1,'state'=>$state,'total_capacity'=>4,'occupied_capacity'=>$occupied,'observed_at'=>NOW,
    ]];
}
function snapshot(array $rows,array $observations): array {
    return PresenceAdapter::snapshot([account()],$rows,NOW,90,300,$observations);
}
function work(int $generation=1): array {
    return [
        'work_item'=>[
            'version'=>1,'work_item_id'=>'work-217','project_id'=>'project-controlbot',
            'source_ref'=>'pl0n3r/ControlBot#217','type'=>'dynamic-capacity','priority'=>'high','state'=>'queued',
            'dependency_ids'=>[],'required_capabilities'=>['php'],'generation'=>$generation,'attempt'=>1,
            'reservation_id'=>null,'assigned_session_id'=>null,
        ],
        'dependency_states'=>[],'claims'=>['src/SchedulerCore.php'],
    ];
}
function dispatch(array $presence,int $generation=1): array {
    return SchedulerCore::dispatchableCapacity(
        $presence,[work($generation)],
        ['active_claims'=>[],'project_concurrency'=>[
            'project-controlbot'=>['state'=>'known','limit'=>1,'active'=>0],
        ]],
    );
}
function handoff(): array {
    return AgentRuntime::handoff([
        'version'=>1,'handoff_id'=>'handoff-217','assignment_id'=>ASSIGNMENT,
        'from_session_id'=>'session-a','to_session_id'=>'session-b',
        'objective'=>'Continue dynamic capacity validation','issue_ref'=>'pl0n3r/ControlBot#217',
        'pr_ref'=>null,'sha'=>str_repeat('a',40),'last_result'=>'Capacity degraded; replan required',
        'evidence_ref'=>'controlbot:dynamic-capacity-e2e','blocker'=>null,
        'next_action'=>'Continue with session-b generation 2',
    ]);
}

function lifecycle(): array {
    $joined=snapshot([sessionRow('session-a','idle',false,990,1)],observation('healthy',0));
    $working=snapshot([sessionRow('session-a','working',true,990,1)],observation('healthy',1));
    $rateLimited=snapshot([sessionRow('session-a','working',true,990,1)],observation('rate_limited',1));
    $stale=snapshot([sessionRow('session-a','working',true,850,1)],observation('healthy',1));

    $currentStale=snapshot([sessionRow('session-b','working',true,850,2)],observation('healthy',1));
    $recovered=snapshot([sessionRow('session-b','working',true,990,2)],observation('healthy',1));

    $owners=array_values(array_map(
        static fn(array $row): string=>$row['session_id'],
        array_filter($recovered['sessions'],static fn(array $row): bool=>$row['assignment_id']===ASSIGNMENT),
    ));

    return [
        'declared_capacity'=>AgentRuntime::account(account())['capacity'],
        'joined'=>$joined,
        'working'=>$working,
        'rate_limited'=>$rateLimited,
        'stale'=>$stale,
        'current_stale'=>$currentStale,
        'recovered'=>$recovered,
        'dispatch'=>[
            'joined'=>dispatch($joined,1),
            'working'=>dispatch($working,1),
            'rate_limited'=>dispatch($rateLimited,1),
            'stale'=>dispatch($stale,1),
            'recovered'=>dispatch($recovered,2),
        ],
        'handoff'=>handoff(),
        'old_recovery_guard'=>PresenceAdapter::replanGuard($stale,'session-a',2,'recover'),
        'stale_current_guard'=>PresenceAdapter::replanGuard($currentStale,'session-b',1,'recover'),
        'current_recovery_guard'=>PresenceAdapter::replanGuard($recovered,'session-b',2,'recover'),
        'current_owner_session_ids'=>$owners,
    ];
}

$case=$argv[1]??'';
if($case==='lifecycle'){
    echo json_encode(lifecycle(),JSON_THROW_ON_ERROR),PHP_EOL;
    exit;
}
fwrite(STDERR,"Unknown dynamic capacity E2E scenario\n");
exit(2);
