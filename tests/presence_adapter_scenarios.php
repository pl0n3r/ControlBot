<?php
declare(strict_types=1);
require __DIR__.'/../src/AgentRuntime.php';
require __DIR__.'/../src/PresenceAdapter.php';

use ControlBot\Runtime\PresenceAdapter;
use InvalidArgumentException;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function account(int $capacity=2,string $status='active',string $alias='Owner'): array {
    return ['version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web','account_alias'=>$alias,'plan'=>'plus','capacity'=>$capacity,'status'=>$status];
}
function session(string $id='session_1',string $status='working',?int $heartbeat=990,bool $assigned=true,int $issue=120,string $profile='Main'): array {
    return ['version'=>1,'session_id'=>$id,'agent_id'=>'agent-'.$id,'account_id'=>'account_main','profile_alias'=>$profile,'tab_id'=>'tab-'.$id,
        'status'=>$status,'assignment_id'=>$assigned?'work-'.$id:null,'last_heartbeat_at'=>$heartbeat,'mode'=>'web',
        'repository'=>$assigned?'pl0n3r/ControlBot':null,'issue_number'=>$assigned?$issue:null];
}
function agent(string $id='session_1'): array {
    return ['version'=>1,'agent_id'=>'agent-'.$id,'role'=>'engineer','capabilities'=>['review','php']];
}
function assignment(string $id='session_1',int $issue=120): array {
    return ['version'=>1,'assignment_id'=>'work-'.$id,'session_id'=>$id,'project_id'=>'project-controlbot',
        'source_ref'=>'pl0n3r/ControlBot#'.$issue,'status'=>'running'];
}
function row(string $id='session_1',string $status='working',?int $heartbeat=990,bool $assigned=true,int $issue=120,
    int $generation=1,bool $safe=true,bool $nonPreemptible=false,string $profile='Main'): array {
    return ['session'=>session($id,$status,$heartbeat,$assigned,$issue,$profile),'agent'=>agent($id),
        'assignment'=>$assigned?assignment($id,$issue):null,'claims'=>['src/PresenceAdapter.php'],
        'generation'=>$generation,'attempt'=>1,'safe_point'=>$safe,'non_preemptible'=>$nonPreemptible];
}
function capacity(string $state='healthy',int $total=2,int $occupied=1,int $observedAt=1000): array {
    return ['version'=>1,'state'=>$state,'total_capacity'=>$total,'occupied_capacity'=>$occupied,'observed_at'=>$observedAt];
}
function snap(array $rows,array $accounts=[null],?array $observations=null): array {
    if($accounts===[null]) $accounts=[account(max(1,count($rows)+1))];
    if($observations===null){
        $observations=[];
        foreach($accounts as $accountRow){
            $observations[$accountRow['account_id']]=capacity('healthy',max(1,count($rows)+1),count($rows));
        }
    }
    return PresenceAdapter::snapshot($accounts,$rows,1000,90,300,$observations);
}

$case=$argv[1]??'';
if($case==='states'){
    $solo=snap([row()],[account(2)]);
    $multi=snap([row('session_1'),row('session_2','working',990,true,121)],[account(3)]);
    $idle=snap([row('session_1','idle',990,false)],[account(1)]);
    $saturated=snap([row()],[account(1)],['account_main'=>capacity('saturated',1,1)]);
    $degraded=snap([row('session_1','working',850)],[account(1)]);
    $unknown=snap([row('session_1','working',null)],[account(1)]);
    $rateLimitedIdle=snap([row('session_1','idle',990,false)],[account(1,'rate_limited')],['account_main'=>capacity('rate_limited',1,1)]);
    echo json_encode(compact('solo','multi','idle','saturated','degraded','unknown','rateLimitedIdle'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}

if($case==='observed_capacity'){
    $declaredLow=[account(1)];
    $signal=['account_main'=>capacity('healthy',4,1)];
    $observed=snap([row()],$declaredLow,$signal);
    $stale=snap([row('session_1','idle',990,false)],[account(99)],['account_main'=>capacity('healthy',9,1,800)]);
    $unknown=snap([row('session_1','idle',990,false)],[account(99)],['account_main'=>capacity('unknown',9,0)]);
    echo json_encode(compact('observed','stale','unknown'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='provider_degradation'){
    $rows=[row('session_1','working',990,true,120)];
    $states=[];
    foreach(['rate_limited','requires_login','offline'] as $state){
        $states[$state]=snap($rows,[account(99)],['account_main'=>capacity($state,9,1)]);
    }
    echo json_encode($states,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='authoritative'){
    $rows=[row('session_1','idle',990,false)];
    $small=snap($rows,[account(1)],['account_main'=>capacity('healthy',5,1)]);
    $large=snap($rows,[account(999)],['account_main'=>capacity('healthy',5,1)]);
    echo json_encode(compact('small','large'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='heartbeat'){
    $stale=snap([row('session_1','working',850)],[account(1)]);
    $missing=snap([row('session_1','working',null)],[account(1)]);
    echo json_encode(compact('stale','missing'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='events'){
    $empty=snap([],[account(1)]);
    $healthy=snap([row()],[account(2)]);
    $stale=snap([row('session_1','working',850)],[account(2)]);
    $join=PresenceAdapter::transitionEvent($empty,$healthy,'session_1');
    $joinAgain=PresenceAdapter::transitionEvent($empty,$healthy,'session_1');
    $staleEvent=PresenceAdapter::transitionEvent($healthy,$stale,'session_1');
    $recovery=PresenceAdapter::transitionEvent($stale,$healthy,'session_1');
    $leave=PresenceAdapter::transitionEvent($healthy,$empty,'session_1');
    echo json_encode(compact('join','joinAgain','staleEvent','recovery','leave'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='sanitize'){
    $clean=snap([row('session_1','working',990,true,120,3,true,false,'person@example.com')],[account(2,'active','owner@example.com')]);
    $emailId=rejected(fn()=>snap([row('person@example.com')],[account(2)]));
    $secretClaim=row(); $secretClaim['claims']=['secrets/token.txt'];
    $secretPath=rejected(fn()=>snap([$secretClaim],[account(2)]));
    echo json_encode(compact('clean','emailId','secretPath'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='guard'){
    $base=snap([row()],[account(2)]);
    $ok=PresenceAdapter::replanGuard($base,'session_1',1,'continue');
    $stale=PresenceAdapter::replanGuard($base,'session_1',2,'recover');
    $blocked=snap([row('session_1','working',990,true,120,1,false,true)],[account(2)]);
    $safe=snap([row('session_1','working',990,true,120,1,true,true)],[account(2)]);
    $preemptBlocked=PresenceAdapter::replanGuard($blocked,'session_1',1,'preempt');
    $preemptSafe=PresenceAdapter::replanGuard($safe,'session_1',1,'preempt');
    $unhealthy=snap([row('session_1','working',850)],[account(2)]);
    $recoverUnhealthy=PresenceAdapter::replanGuard($unhealthy,'session_1',1,'recover');
    echo json_encode(compact('ok','stale','preemptBlocked','preemptSafe','recoverUnhealthy'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='deterministic'){
    $a=snap([row()],[account(2)]); $b=snap([row()],[account(2)]);
    $eventA=PresenceAdapter::transitionEvent(snap([],[account(1)]),$a,'session_1');
    $eventB=PresenceAdapter::transitionEvent(snap([],[account(1)]),$b,'session_1');
    echo json_encode(['snapshot_same'=>$a===$b,'event_same'=>$eventA===$eventB,'a'=>$a,'b'=>$b],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown presence scenario\n"); exit(2);
