<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/SchedulerSelection.php';
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/PresenceAdapter.php';
require __DIR__.'/../src/SchedulerAssignmentPlan.php';
require __DIR__.'/../src/SchedulerPolicyGuard.php';

use ControlBot\Runtime\PresenceAdapter;
use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerPolicyGuard;
use ControlBot\Scheduler\SchedulerSelection;
use ControlBot\Scheduler\ValidatedSchedulerSelection;
use InvalidArgumentException;

const NOW=10_000;

function rejected(callable $work): bool
{
    try{$work();return false;}catch(InvalidArgumentException){return true;}
}

function work(
    string $id,
    int $issue,
    string $type='engineering',
    string $priority='high',
    string $state='queued',
    int $generation=3,
    ?string $reservationId=null,
    ?string $sessionId=null,
): array {
    return [
        'version'=>1,'work_item_id'=>$id,'project_id'=>'controlbot',
        'source_ref'=>'pl0n3r/ControlBot#'.$issue,'type'=>$type,'priority'=>$priority,'state'=>$state,
        'dependency_ids'=>[],'required_capabilities'=>['php','review'],
        'generation'=>$generation,'attempt'=>1,
        'reservation_id'=>$reservationId,'assigned_session_id'=>$sessionId,
    ];
}

function context(array $work,bool $ready=true): array
{
    $reservations=[];
    if($work['reservation_id']!==null){
        $reservations[]=[
            'reservation_id'=>$work['reservation_id'],
            'owner_session_id'=>$work['assigned_session_id'],
            'generation'=>$work['generation'],
            'active'=>true,
        ];
    }
    return [
        'dependency_states'=>[],
        'approval_state'=>'approved',
        'freeze_state'=>$ready?'clear':'active',
        'reservations'=>$reservations,
        'capacity'=>['account_id'=>'account_main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],
        'expected_generation'=>$work['generation'],
        'expected_owner_session_id'=>$work['assigned_session_id'],
    ];
}

function validated(array $rows,string $selectedId,array $blockedIds=[]): ValidatedSchedulerSelection
{
    $candidates=[];
    foreach($rows as $row){
        $candidates[]=SchedulerCore::candidate(
            $row,
            context($row,!in_array($row['work_item_id'],$blockedIds,true)),
        );
    }
    $request=SchedulerSelection::request($candidates);
    $ready=[];$excluded=[];
    foreach($request['candidates'] as $candidate){
        if($candidate['readiness']['ready']){
            if($candidate['key']!==$selectedId) $ready[]=$candidate['key'];
        }else{
            $excluded[$candidate['key']]=$candidate['readiness']['reasons'];
        }
    }
    sort($ready,SORT_STRING);ksort($excluded,SORT_STRING);
    $decision=[
        'version'=>1,
        'policy_ref'=>'factory-dispatcher-v2',
        'request_fingerprint'=>$request['fingerprint'],
        'selected_key'=>$selectedId,
        'selection_reason'=>'authoritative Factory dispatcher decision',
        'telemetry'=>['ready_not_selected'=>$ready,'excluded'=>$excluded],
    ];
    return ValidatedSchedulerSelection::fromDecision($request,$decision,$candidates);
}

function trace(ValidatedSchedulerSelection $selection,array $replace=[]): array
{
    $value=$selection->value();
    return array_replace([
        'version'=>1,
        'policy_ref'=>'factory-dispatcher-v2',
        'request_fingerprint'=>$value['request_fingerprint'],
        'selected_key'=>$value['selected_key'],
        'focus_version'=>null,
        'focus_position'=>null,
        'focus_influenced'=>false,
    ],$replace);
}

function account(): array
{
    return [
        'version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web',
        'account_alias'=>'Owner','plan'=>'plus','capacity'=>2,'status'=>'active',
    ];
}

function session(string $sourceRef): array
{
    $pos=strrpos($sourceRef,'#');
    return [
        'version'=>1,'session_id'=>'session_run','agent_id'=>'agent_run','account_id'=>'account_main',
        'profile_alias'=>'Main','tab_id'=>'tab-run','status'=>'working','assignment_id'=>'assignment-run',
        'last_heartbeat_at'=>NOW-10,'mode'=>'web',
        'repository'=>substr($sourceRef,0,$pos),'issue_number'=>(int)substr($sourceRef,$pos+1),
    ];
}

function agent(): array
{
    return ['version'=>1,'agent_id'=>'agent_run','role'=>'engineer','capabilities'=>['php','review']];
}

function assignment(string $sourceRef): array
{
    return [
        'version'=>1,'assignment_id'=>'assignment-run','session_id'=>'session_run',
        'project_id'=>'controlbot','source_ref'=>$sourceRef,'status'=>'running',
    ];
}

function presence(array $running,bool $safePoint,bool $nonPreemptible,int $generationOverride=0): array
{
    $generation=$generationOverride>0?$generationOverride:$running['generation'];
    $row=[
        'session'=>session($running['source_ref']),
        'agent'=>agent(),
        'assignment'=>assignment($running['source_ref']),
        'claims'=>['src/SchedulerPolicyGuard.php'],
        'generation'=>$generation,'attempt'=>1,
        'safe_point'=>$safePoint,'non_preemptible'=>$nonPreemptible,
    ];
    return PresenceAdapter::snapshot(
        [account()],
        [$row],
        NOW,
        90,
        300,
        ['account_main'=>[
            'version'=>1,'state'=>'healthy','total_capacity'=>2,'occupied_capacity'=>1,'observed_at'=>NOW,
        ]],
    );
}

$case=$argv[1]??'';

if($case==='preemption'){
    $selected=work('work-incident',395,'incident','high');
    $running=work('work-running',394,'engineering','high','running',4,'reservation-run','session_run');
    $selection=validated([$selected,$running],'work-incident');
    $policyTrace=trace($selection);
    $blocked=SchedulerPolicyGuard::preemptionIntent(
        $selection,[$selected,$running],$policyTrace,presence($running,false,true),
        'work-running','session_run',4,
    );
    $safe=SchedulerPolicyGuard::preemptionIntent(
        $selection,[$selected,$running],$policyTrace,presence($running,true,true),
        'work-running','session_run',4,
    );
    $stale=SchedulerPolicyGuard::preemptionIntent(
        $selection,[$selected,$running],$policyTrace,presence($running,true,true,5),
        'work-running','session_run',4,
    );
    echo json_encode(compact('blocked','safe','stale'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}

if($case==='focus'){
    $high=work('work-high',395,'engineering','high');
    $critical=work('work-critical',58,'engineering','critical');
    $badSelection=validated([$high,$critical],'work-high');
    $bad=rejected(fn()=>SchedulerPolicyGuard::focusGuard(
        $badSelection,[$high,$critical],trace($badSelection,[
            'focus_version'=>3,'focus_position'=>1,'focus_influenced'=>true,
        ]),
    ));
    $goodSelection=validated([$high,$critical],'work-critical');
    $good=SchedulerPolicyGuard::focusGuard(
        $goodSelection,[$high,$critical],trace($goodSelection,[
            'focus_version'=>3,'focus_position'=>2,'focus_influenced'=>true,
        ]),
    );
    $incident=work('work-incident',56,'incident','medium');
    $incidentSelection=validated([$high,$incident],'work-high');
    $incidentRejected=rejected(fn()=>SchedulerPolicyGuard::focusGuard(
        $incidentSelection,[$high,$incident],trace($incidentSelection,[
            'focus_version'=>3,'focus_position'=>1,'focus_influenced'=>true,
        ]),
    ));
    echo json_encode([
        'critical_rejected'=>$bad,
        'incident_rejected'=>$incidentRejected,
        'protected_selected'=>$good,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}

if($case==='blocked_focus'){
    $high=work('work-high',395,'engineering','high');
    $blockedCritical=work('work-critical',58,'engineering','critical');
    $selection=validated([$high,$blockedCritical],'work-high',['work-critical']);
    $result=SchedulerPolicyGuard::focusGuard(
        $selection,[$high,$blockedCritical],trace($selection,[
            'focus_version'=>4,'focus_position'=>1,'focus_influenced'=>true,
        ]),
    );
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}

if($case==='drift'){
    $selected=work('work-incident',395,'incident','high');
    $other=work('work-other',58,'engineering','high');
    $selection=validated([$selected,$other],'work-incident');
    $base=trace($selection);
    $wrongFingerprint=$base;$wrongFingerprint['request_fingerprint']=str_repeat('a',64);
    $wrongSelected=$base;$wrongSelected['selected_key']='work-other';
    $badFocus=$base;$badFocus['focus_influenced']=true;
    $changed=$selected;$changed['generation']=4;
    echo json_encode([
        'fingerprint'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($selection,[$selected,$other],$wrongFingerprint)),
        'selected'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($selection,[$selected,$other],$wrongSelected)),
        'focus'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($selection,[$selected,$other],$badFocus)),
        'work'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($selection,[$changed,$other],$base)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='deterministic'){
    $a=work('work-a',395,'engineering','high');
    $b=work('work-b',58,'engineering','high');
    $selection=validated([$a,$b],'work-a');
    $policyTrace=trace($selection,['focus_version'=>2,'focus_position'=>1,'focus_influenced'=>true]);
    $one=SchedulerPolicyGuard::focusGuard($selection,[$a,$b],$policyTrace);
    $two=SchedulerPolicyGuard::focusGuard($selection,[$b,$a],$policyTrace);
    $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerPolicyGuard.php'));
    $forbidden=['select_next','score','new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec('];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode([
        'same'=>$one===$two,
        'fingerprint_same'=>$one['fingerprint']===$two['fingerprint'],
        'hits'=>$hits,
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

fwrite(STDERR,"Unknown scheduler policy guard scenario\n");
exit(2);
