<?php
declare(strict_types=1);

require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/RuntimeCapacitySignal.php';
require __DIR__.'/../src/PresenceAdapter.php';
require __DIR__.'/../src/SchedulerCore.php';

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Runtime\RuntimeCapacitySignal;
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
function rawSignal(string $state,string $session,int $generation,string $source='autofactory'): array {
    $assigned=$state!=='idle';
    return [
        'version'=>1,'source'=>$source,'provider_id'=>'chatgpt-web','account_ref'=>'account_main',
        'session_ref'=>$session,'state'=>$state,'observed_at'=>NOW,
        'heartbeat_at'=>$state==='stale'?850:990,
        'total_capacity'=>4,'occupied_capacity'=>$state==='idle'?0:1,
        'assignment_ref'=>$assigned?ASSIGNMENT:null,'generation'=>$generation,'attempt'=>1,
        'capabilities'=>['php','review'],
    ];
}
function normalizeSignal(string $state,string $session,int $generation,string $source='autofactory'): array {
    return RuntimeCapacitySignal::normalize(rawSignal($state,$session,$generation,$source));
}
function seam(array $signal): array {
    $assigned=$signal['assignment_ref']!==null;
    $sessionState=$signal['state']==='idle'?'idle':'working';
    $session=[
        'version'=>1,'session_id'=>$signal['session_ref'],'agent_id'=>'agent-'.$signal['session_ref'],
        'account_id'=>$signal['account_ref'],'profile_alias'=>'Primary','tab_id'=>'tab-'.$signal['session_ref'],
        'status'=>$sessionState,'assignment_id'=>$signal['assignment_ref'],
        'last_heartbeat_at'=>$signal['heartbeat_at'],'mode'=>'web',
        'repository'=>$assigned?'pl0n3r/ControlBot':null,'issue_number'=>$assigned?ISSUE:null,
    ];
    $assignment=$assigned?[
        'version'=>1,'assignment_id'=>$signal['assignment_ref'],'session_id'=>$signal['session_ref'],
        'project_id'=>'project-controlbot','source_ref'=>'pl0n3r/ControlBot#'.ISSUE,'status'=>'running',
    ]:null;
    $providerState=match($signal['state']){
        'rate_limited'=>'rate_limited','requires_login'=>'requires_login','offline'=>'offline','unknown'=>'unknown',
        default=>'healthy',
    };
    return [
        'row'=>[
            'session'=>$session,'agent'=>agent($signal['session_ref']),'assignment'=>$assignment,
            'claims'=>['src/SchedulerCore.php'],'generation'=>$signal['generation'],'attempt'=>$signal['attempt'],
            'safe_point'=>true,'non_preemptible'=>false,
        ],
        'observation'=>['account_main'=>[
            'version'=>1,'state'=>$providerState,'total_capacity'=>$signal['total_capacity'],
            'occupied_capacity'=>$signal['occupied_capacity'],'observed_at'=>$signal['observed_at'],
        ]],
    ];
}
function snapshotFromSignal(array $signal): array {
    $mapped=seam($signal);
    return PresenceAdapter::snapshot([account()],[$mapped['row']],NOW,90,300,$mapped['observation']);
}
function dispatchWork(int $generation=1): array {
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
        $presence,[dispatchWork($generation)],
        ['active_claims'=>[],'project_concurrency'=>[
            'project-controlbot'=>['state'=>'known','limit'=>1,'active'=>0],
        ]],
    );
}
function readinessWork(int $generation,string $reservation,string $owner): array {
    return [
        'version'=>1,'work_item_id'=>'work-217','project_id'=>'project-controlbot',
        'source_ref'=>'pl0n3r/ControlBot#217','type'=>'dynamic-capacity','priority'=>'high','state'=>'queued',
        'dependency_ids'=>[],'required_capabilities'=>['php'],'generation'=>$generation,'attempt'=>1,
        'reservation_id'=>$reservation,'assigned_session_id'=>$owner,
    ];
}
function capacityForReadiness(array $presence): array {
    return [
        'account_id'=>'account_main',
        'eligible'=>$presence['idle_capacity']>0,
        'free_capacity'=>$presence['idle_capacity'],
        'session_ids'=>array_values(array_map(static fn(array $row): string=>$row['session_id'],$presence['sessions'])),
    ];
}
function readinessContext(array $presence,int $generation,string $reservation,string $owner,array $extra=[]): array {
    return array_replace([
        'dependency_states'=>[],'approval_state'=>'not_required','freeze_state'=>'clear',
        'reservations'=>[[
            'reservation_id'=>$reservation,'owner_session_id'=>$owner,'generation'=>$generation,'active'=>true,
        ]],
        'capacity'=>capacityForReadiness($presence),
        'expected_generation'=>$generation,'expected_owner_session_id'=>$owner,
    ],$extra);
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }
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
    $signals=[
        'joined'=>normalizeSignal('idle','session-a',1,'autofactory'),
        'working'=>normalizeSignal('working','session-a',1,'autofactory'),
        'rate_limited'=>normalizeSignal('rate_limited','session-a',1,'autofactory'),
        'stale'=>normalizeSignal('stale','session-a',1,'autofactory'),
        'current_stale'=>normalizeSignal('stale','session-b',2,'factoryrunner'),
        'recovered'=>normalizeSignal('working','session-b',2,'factoryrunner'),
    ];
    $snapshots=[];
    foreach($signals as $key=>$signal) $snapshots[$key]=snapshotFromSignal($signal);

    $previousReady=SchedulerCore::readiness(
        readinessWork(1,'reservation-1','session-a'),
        readinessContext($snapshots['working'],1,'reservation-1','session-a'),
    );
    $currentReady=SchedulerCore::readiness(
        readinessWork(2,'reservation-2','session-b'),
        readinessContext($snapshots['recovered'],2,'reservation-2','session-b'),
    );
    $staleOwner=SchedulerCore::readiness(
        readinessWork(2,'reservation-2','session-b'),
        readinessContext($snapshots['recovered'],2,'reservation-2','session-b',[
            'reservations'=>[[
                'reservation_id'=>'reservation-2','owner_session_id'=>'session-a','generation'=>1,'active'=>true,
            ]],
        ]),
    );
    $duplicateOwners=blocked(fn()=>SchedulerCore::readiness(
        readinessWork(2,'reservation-2','session-b'),
        readinessContext($snapshots['recovered'],2,'reservation-2','session-b',[
            'reservations'=>[
                ['reservation_id'=>'reservation-2','owner_session_id'=>'session-a','generation'=>1,'active'=>true],
                ['reservation_id'=>'reservation-3','owner_session_id'=>'session-b','generation'=>2,'active'=>true],
            ],
        ]),
    ));

    return [
        'declared_capacity'=>AgentRuntime::account(account())['capacity'],
        'signals'=>$signals,
        'joined'=>$snapshots['joined'],'working'=>$snapshots['working'],
        'rate_limited'=>$snapshots['rate_limited'],'stale'=>$snapshots['stale'],
        'current_stale'=>$snapshots['current_stale'],'recovered'=>$snapshots['recovered'],
        'dispatch'=>[
            'joined'=>dispatch($snapshots['joined'],1),'working'=>dispatch($snapshots['working'],1),
            'rate_limited'=>dispatch($snapshots['rate_limited'],1),'stale'=>dispatch($snapshots['stale'],1),
            'recovered'=>dispatch($snapshots['recovered'],2),
        ],
        'handoff'=>handoff(),
        'readiness'=>['previous'=>$previousReady,'current'=>$currentReady,'stale_owner'=>$staleOwner,'duplicate_owners_rejected'=>$duplicateOwners],
        'old_recovery_guard'=>PresenceAdapter::replanGuard($snapshots['stale'],'session-a',2,'recover'),
        'stale_current_guard'=>PresenceAdapter::replanGuard($snapshots['current_stale'],'session-b',1,'recover'),
        'current_recovery_guard'=>PresenceAdapter::replanGuard($snapshots['recovered'],'session-b',2,'recover'),
    ];
}

$case=$argv[1]??'';
if($case==='lifecycle'){
    echo json_encode(lifecycle(),JSON_THROW_ON_ERROR),PHP_EOL;
    exit;
}
fwrite(STDERR,"Unknown dynamic capacity E2E scenario\n");
exit(2);
