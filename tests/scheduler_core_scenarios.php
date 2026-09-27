<?php
declare(strict_types=1);
require __DIR__ . '/../src/AgentRuntime.php';
require __DIR__ . '/../src/SchedulerCore.php';

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Scheduler\SchedulerCore;
use InvalidArgumentException;

function rejected(callable $work): bool { try { $work(); return false; } catch (InvalidArgumentException) { return true; } }
function item(array $replace=[]): array {
    return array_replace([
        'version'=>1,'work_item_id'=>'work-118','project_id'=>'project-controlbot',
        'source_ref'=>'pl0n3r/ControlBot#118','type'=>'feature','priority'=>'critical','state'=>'queued',
        'dependency_ids'=>['dep-runtime'],'required_capabilities'=>['php','review'],
        'generation'=>2,'attempt'=>1,'reservation_id'=>null,'assigned_session_id'=>null,
    ], $replace);
}
function capacity(bool $available=true): array {
    $account=['version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web','account_alias'=>'Principal','plan'=>'plus','capacity'=>1,'status'=>$available?'active':'rate_limited'];
    $sessions=$available?[]:[];
    return AgentRuntime::capacitySnapshot($account,$sessions);
}
function context(array $replace=[]): array {
    return array_replace([
        'dependency_states'=>['dep-runtime'=>'satisfied'],'approval_state'=>'approved','freeze_state'=>'clear',
        'reservations'=>[],'capacity'=>capacity(),'expected_generation'=>2,'expected_owner_session_id'=>null,
    ], $replace);
}

$scenario=$argv[1]??'';
if($scenario==='schema'){
    $valid=SchedulerCore::workItem(item());
    echo json_encode([
        'valid'=>$valid,
        'extra'=>rejected(fn()=>SchedulerCore::workItem(item(['extra'=>'x']))),
        'generation'=>rejected(fn()=>SchedulerCore::workItem(item(['generation'=>0]))),
        'source'=>rejected(fn()=>SchedulerCore::workItem(item(['source_ref'=>'issue-118']))),
        'state'=>rejected(fn()=>SchedulerCore::workItem(item(['state'=>'mystery']))),
        'reserved_without_owner'=>rejected(fn()=>SchedulerCore::workItem(item(['state'=>'reserved']))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='readiness'){
    $open=SchedulerCore::readiness(item(),context(['dependency_states'=>['dep-runtime'=>'open']]));
    $unknown=SchedulerCore::readiness(item(),context(['dependency_states'=>['dep-runtime'=>'unknown']]));
    $pending=SchedulerCore::readiness(item(),context(['approval_state'=>'pending']));
    $approvalUnknown=SchedulerCore::readiness(item(),context(['approval_state'=>'unknown']));
    $freeze=SchedulerCore::readiness(item(),context(['freeze_state'=>'active']));
    $freezeUnknown=SchedulerCore::readiness(item(),context(['freeze_state'=>'unknown']));
    echo json_encode(compact('open','unknown','pending','approvalUnknown','freeze','freezeUnknown'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='fencing'){
    $reserved=item(['state'=>'reserved','reservation_id'=>'7c43b4b2-4ec5-4386-ab3b-95dcd9a4a076','assigned_session_id'=>'session_1']);
    $compatible=['reservation_id'=>'7c43b4b2-4ec5-4386-ab3b-95dcd9a4a076','owner_session_id'=>'session_1','generation'=>2,'active'=>true];
    echo json_encode([
        'ready'=>SchedulerCore::readiness($reserved,context(['reservations'=>[$compatible],'expected_owner_session_id'=>'session_1'])),
        'stale_generation'=>SchedulerCore::readiness($reserved,context(['reservations'=>[$compatible],'expected_generation'=>1,'expected_owner_session_id'=>'session_1'])),
        'stale_owner'=>SchedulerCore::readiness($reserved,context(['reservations'=>[$compatible],'expected_owner_session_id'=>'session_2'])),
        'conflict'=>SchedulerCore::readiness($reserved,context(['reservations'=>[array_replace($compatible,['reservation_id'=>'8d54c5c3-5fd6-5497-bc4c-a6edda15b187'])],'expected_owner_session_id'=>'session_1'])),
        'double_owner'=>rejected(fn()=>SchedulerCore::readiness($reserved,context(['reservations'=>[$compatible,array_replace($compatible,['reservation_id'=>'8d54c5c3-5fd6-5497-bc4c-a6edda15b187','owner_session_id'=>'session_2'])]]))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='capacity'){
    $work=item();
    $full=AgentRuntime::capacitySnapshot(
        ['version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web','account_alias'=>'Principal','plan'=>'plus','capacity'=>1,'status'=>'active'],
        [['version'=>1,'session_id'=>'session_1','agent_id'=>'agent-1','account_id'=>'account_main','profile_alias'=>'Main','tab_id'=>'tab-1','status'=>'idle','assignment_id'=>null,'last_heartbeat_at'=>100,'mode'=>'web','repository'=>null,'issue_number'=>null]]
    );
    $rate=capacity(false);
    echo json_encode([
        'before'=>$work,
        'full'=>SchedulerCore::readiness($work,context(['capacity'=>$full])),
        'rate_limited'=>SchedulerCore::readiness($work,context(['capacity'=>$rate])),
        'after'=>$work,
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='candidate'){
    echo json_encode(SchedulerCore::candidate(item(),context()),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='deterministic'){
    $a=SchedulerCore::candidate(item(),context());
    $b=SchedulerCore::candidate(item(),context());
    echo json_encode(['same'=>$a===$b,'first'=>$a,'second'=>$b],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown scheduler scenario\n"); exit(2);
