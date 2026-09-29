<?php
declare(strict_types=1);

require __DIR__.'/../src/CapacityUi.php';

use ControlBot\Ui\CapacityUi;

function session(string $id,string $fresh='healthy',string $state='idle'): array {
    return ['session_id'=>$id,'state'=>$state,'freshness'=>$fresh];
}
function account(string $id,string $state='healthy',int $free=1,int $idle=1,string $provider='chatgpt-web'): array {
    return ['account_id'=>$id,'provider_id'=>$provider,'eligible'=>($free+$idle)>0,'free_capacity'=>$free,'idle_sessions'=>$idle,'observed_state'=>$state,'observed_at'=>1000];
}
function presence(array $sessions,array $accounts,int $healthy,int $idle,string $capacity='idle_capacity'): array {
    return ['version'=>1,'policy_ref'=>'factory-dispatcher-v2','observed_at'=>1000,'presence_state'=>$healthy===0?'unknown':($healthy===1?'solo':'multi'),
        'capacity_state'=>$capacity,'healthy_sessions'=>$healthy,'idle_capacity'=>$idle,'sessions'=>$sessions,'accounts'=>$accounts];
}
function scheduler(int $idle,int $dispatch): array {
    return ['version'=>1,'policy_ref'=>'factory-dispatcher-v2','authoritative_idle_capacity'=>$idle,'dispatchable_capacity'=>$dispatch,
        'ready_work_items'=>$dispatch,'claim_lanes'=>$dispatch,'concurrency_slots'=>$dispatch,'joint_constraint_slots'=>$dispatch,'reasons'=>[],'work_items'=>[]];
}
function renderReady(array $p,array $s): string {
    return CapacityUi::render(['state'=>'ready','presence'=>$p,'scheduler'=>$s,'message'=>null]);
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='metrics'){
    $html=renderReady(
        presence([session('s1'),session('s2','healthy','working'),session('s3','stale','working')],[account('a1','healthy',2,1)],2,3),
        scheduler(3,1)
    );
    echo json_encode(['html'=>$html],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='mixed'){
    $sessions=[session('s1','healthy','idle')];
    $healthy=account('a1','healthy',1,1,'chatgpt-web');
    $degraded=renderReady(presence($sessions,[$healthy,account('a2','rate_limited',0,0,'claude-web')],1,2,'degraded'),scheduler(2,1));
    $unknown=renderReady(presence($sessions,[$healthy,account('a3','unknown',0,0,'claude-web')],1,2,'unknown'),scheduler(2,1));
    echo json_encode(compact('degraded','unknown'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='degraded'){
    $html=renderReady(
        presence([session('s1','stale'),session('s2','unknown')],[account('a1','rate_limited',0,0),account('a2','requires_login',0,0)],0,0,'degraded'),
        scheduler(0,0)
    );
    echo json_encode(['html'=>$html],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='declared'){
    $p=presence([session('s1')],[account('a1','healthy',1,0)],1,1);
    $p['accounts'][0]['declared_capacity']=999;
    echo json_encode(['rejected'=>blocked(fn()=>renderReady($p,scheduler(1,1)))],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='responsive'){
    $html=renderReady(presence([session('s1')],[account('a1')],1,2),scheduler(2,1));
    echo json_encode(['html'=>$html],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='states'){
    $out=[];
    foreach(['loading','empty','error'] as $state){
        $out[$state]=CapacityUi::render(['state'=>$state,'presence'=>null,'scheduler'=>null,'message'=>$state==='error'?'retry later':null]);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='authority'){
    $p=presence([session('s1')],[account('a1')],1,2);
    echo json_encode([
        'mismatch'=>blocked(fn()=>renderReady($p,scheduler(3,1))),
        'overflow'=>blocked(fn()=>renderReady($p,scheduler(2,3))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"unknown capacity ui scenario\n"); exit(2);
