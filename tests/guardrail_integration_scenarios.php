<?php
declare(strict_types=1);
require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/ExecutionGuardrail.php';
require __DIR__.'/../src/PauseControl.php';
require __DIR__.'/../src/SchedulerRequeuePlan.php';
require __DIR__.'/../src/GuardrailIntegration.php';

use ControlBot\Guardrail\GuardrailIntegration;
use InvalidArgumentException;

const NOW=2000;
function rejected(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function thresholds(): array{return [
    'issue_stale_seconds'=>600,'reservation_stale_seconds'=>600,'heartbeat_stale_seconds'=>120,
    'rapid_retry_seconds'=>30,'state_timeouts'=>['working'=>300],
];}
function snap(array $r=[]): array{return array_replace([
    'issue_ref'=>'pl0n3r/ControlBot#111','pr_ref'=>'pl0n3r/ControlBot#482',
    'sha'=>str_repeat('a',40),'last_result'=>'same failure','next_approach'=>'use a different approach',
    'commits'=>2,'review_rounds'=>1,'progress_version'=>2,'previous_progress_version'=>1,
    'issue_updated_at'=>1950,'reservation_updated_at'=>1950,'heartbeat_at'=>1950,
    'state'=>'working','state_started_at'=>1900,
    'attempts'=>[
        ['approach'=>'retry-build','result'=>'failed','error'=>'same failure','evidence'=>'same evidence','at'=>1800],
        ['approach'=>'retry-build','result'=>'failed','error'=>'same failure','evidence'=>'same evidence','at'=>1850],
    ],
    'retry_events'=>[],'handoffs'=>[],'non_preemptible'=>false,
],$r);}
function runtime(): array{return [
    'session'=>[
        'version'=>1,'session_id'=>'session-main','agent_id'=>'agent-main','account_id'=>'account-main',
        'profile_alias'=>'Main','tab_id'=>'tab-main','status'=>'working','assignment_id'=>'assignment-111',
        'last_heartbeat_at'=>1950,'mode'=>'chat','repository'=>'pl0n3r/ControlBot','issue_number'=>111,
    ],
    'assignment'=>[
        'version'=>1,'assignment_id'=>'assignment-111','session_id'=>'session-main',
        'project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#111','status'=>'running',
    ],
];}
function work(array $r=[]): array{return array_replace([
    'version'=>1,'work_item_id'=>'work-111','project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#111',
    'type'=>'engineering','priority'=>'critical','state'=>'running','dependency_ids'=>[],
    'required_capabilities'=>['php'],'generation'=>4,'attempt'=>2,
    'reservation_id'=>'reservation-111','assigned_session_id'=>'session-main',
],$r);}
function plan(array $s=null,bool $safe=false,?string $alert=null): array{
    return GuardrailIntegration::plan($s??snap(),thresholds(),runtime(),work(),$safe,$alert,NOW);
}

$waiting=plan();
$safe=plan(safe:true);
$non=plan(snap(['non_preemptible'=>true]),true);
$changed=plan(snap([
    'attempts'=>[],'heartbeat_at'=>1000,'last_result'=>'heartbeat missing',
]),false,$waiting['guardrail']['alert_fingerprint']);
$healthy=plan(snap(['attempts'=>[]]),false,$waiting['guardrail']['alert_fingerprint']);
$oldGuard=GuardrailIntegration::ownerEventGuard(
    $safe['requeue_intent']['work_item'],'session-main',4
);
$partial=GuardrailIntegration::reconcile($safe,['pause'=>true,'checkpoint'=>false,'requeue'=>false]);
$done=GuardrailIntegration::reconcile($safe,['pause'=>true,'checkpoint'=>true,'requeue'=>true]);
$invalidOrder=rejected(fn()=>GuardrailIntegration::reconcile(
    $safe,['pause'=>false,'checkpoint'=>false,'requeue'=>true]
));
$terminalAssignment=runtime();
$terminalAssignment['assignment']['status']='done';
$staleAssignment=rejected(fn()=>GuardrailIntegration::plan(
    snap(),thresholds(),$terminalAssignment,work(),true,null,NOW
));
$terminalSession=runtime();
$terminalSession['session']['status']='failed';
$staleSession=rejected(fn()=>GuardrailIntegration::plan(
    snap(),thresholds(),$terminalSession,work(),true,null,NOW
));
$wrongTarget=runtime();
$wrongTarget['session']['issue_number']=999;
$wrongSessionTarget=rejected(fn()=>GuardrailIntegration::plan(
    snap(),thresholds(),$wrongTarget,work(),true,null,NOW
));

echo json_encode([
    'waiting'=>$waiting,'safe'=>$safe,'non_preemptible'=>$non,'changed'=>$changed,'healthy'=>$healthy,
    'old_guard'=>$oldGuard,'partial'=>$partial,'done'=>$done,'invalid_order'=>$invalidOrder,
    'stale_assignment'=>$staleAssignment,'stale_session'=>$staleSession,
    'wrong_session_target'=>$wrongSessionTarget,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
