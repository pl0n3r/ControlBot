<?php
declare(strict_types=1);
require __DIR__ . '/../src/AgentRuntime.php';
use ControlBot\Runtime\AgentRuntime;

function rejected(callable $work): bool { try { $work(); return false; } catch (InvalidArgumentException) { return true; } }
$provider = ['version'=>1,'provider_id'=>'chatgpt-web','adapter'=>'browser-bridge'];
$account = ['version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web','account_alias'=>'Principal','plan'=>'plus','capacity'=>2,'status'=>'active'];
$agent = ['version'=>1,'agent_id'=>'agent_runtime','role'=>'software-engineering','capabilities'=>['review-code','test-php']];
function observedCapacity(string $state='healthy', int $total=3, int $occupied=1): array {
    return ['version'=>1,'state'=>$state,'total_capacity'=>$total,'occupied_capacity'=>$occupied,'observed_at'=>1000];
}
function sessionRow(string $id, string $status='idle', ?int $heartbeat=1000, ?string $assignment=null): array {
    return ['version'=>1,'session_id'=>$id,'agent_id'=>'agent_runtime','account_id'=>'account_main','profile_alias'=>'Perfil principal','tab_id'=>'tab_'.$id,'status'=>$status,'assignment_id'=>$assignment,'last_heartbeat_at'=>$heartbeat,'mode'=>'web','repository'=>$assignment===null?null:'pl0n3r/ControlBot','issue_number'=>$assignment===null?null:116];
}
$scenario = $argv[1] ?? '';
if ($scenario === 'contract') {
    $a = AgentRuntime::capacitySnapshot($account, []);
    $other = array_replace($account, ['account_id'=>'account_other','provider_id'=>'claude-web','account_alias'=>'Secundaria']);
    $b = AgentRuntime::capacitySnapshot($other, []);
    $assignment=AgentRuntime::assignment(['version'=>1,'assignment_id'=>'work_116','session_id'=>'session_1','project_id'=>'project-controlbot','source_ref'=>'pl0n3r/ControlBot#116','status'=>'assigned']);
    echo json_encode(['provider'=>AgentRuntime::provider($provider),'agent'=>AgentRuntime::agent($agent),'assignment'=>$assignment,'keys_same'=>array_keys($a)===array_keys($b),'views'=>[$a,$b]], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'capacity') {
    $s1=sessionRow('session_1'); $s2=sessionRow('session_2'); $s3=sessionRow('session_3');
    echo json_encode(['one'=>AgentRuntime::capacitySnapshot($account,[$s1]),'two'=>AgentRuntime::capacitySnapshot($account,[$s1,$s2]),'overflow'=>rejected(fn()=>AgentRuntime::capacitySnapshot($account,[$s1,$s2,$s3])),'duplicate'=>rejected(fn()=>AgentRuntime::capacitySnapshot($account,[$s1,$s1]))], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'observed_capacity') {
    $s1=sessionRow('session_1'); $s2=sessionRow('session_2');
    $declaredOne=array_replace($account,['capacity'=>1]);
    $declaredHigh=array_replace($account,['capacity'=>999]);
    $sameSignal=observedCapacity('healthy',4,1);
    $claude=array_replace($declaredHigh,['account_id'=>'account_other','provider_id'=>'claude-web','account_alias'=>'Secundaria']);
    $states=[];
    foreach(['rate_limited','requires_login','offline','unknown'] as $state){
        $states[$state]=AgentRuntime::observedCapacitySnapshot($declaredHigh,[],observedCapacity($state,99,0));
    }
    echo json_encode([
        'over_declared'=>AgentRuntime::observedCapacitySnapshot($declaredOne,[$s1,$s2],observedCapacity('healthy',3,2)),
        'declared_only'=>AgentRuntime::observedCapacitySnapshot($declaredHigh,[],observedCapacity('unknown',99,0)),
        'states'=>$states,
        'chatgpt'=>AgentRuntime::observedCapacitySnapshot($declaredOne,[],$sameSignal),
        'claude'=>AgentRuntime::observedCapacitySnapshot($claude,[],$sameSignal),
        'large_declared'=>AgentRuntime::account($declaredHigh),
        'invalid_state'=>rejected(fn()=>AgentRuntime::observedCapacitySnapshot($account,[],observedCapacity('invented'))),
        'invalid_total'=>rejected(fn()=>AgentRuntime::observedCapacitySnapshot($account,[],['version'=>1,'state'=>'healthy','total_capacity'=>-1,'occupied_capacity'=>0,'observed_at'=>1000])),
        'invalid_occupancy'=>rejected(fn()=>AgentRuntime::observedCapacitySnapshot($account,[],observedCapacity('healthy',2,3))),
        'legacy'=>AgentRuntime::capacitySnapshot($account,[$s1]),
        'legacy_overflow_rejected'=>rejected(fn()=>AgentRuntime::capacitySnapshot($declaredOne,[$s1,$s2])),
    ], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'heartbeat') {
    $assigned=sessionRow('session_1','working',1000,'work_116');
    echo json_encode(['fresh'=>AgentRuntime::sessionHealth($assigned,1050),'stale'=>AgentRuntime::sessionHealth($assigned,1150),'offline'=>AgentRuntime::sessionHealth($assigned,1400),'missing'=>AgentRuntime::sessionHealth(array_replace($assigned,['last_heartbeat_at'=>null]),1050),'future'=>AgentRuntime::sessionHealth(array_replace($assigned,['last_heartbeat_at'=>1100]),1050)], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'availability') {
    $s=sessionRow('session_1');
    $rows=[]; foreach(['active','rate_limited','requires_login','offline'] as $status){$rows[$status]=AgentRuntime::capacitySnapshot(array_replace($account,['status'=>$status]),[$s]);}
    echo json_encode($rows, JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'handoff') {
    $row=['version'=>1,'handoff_id'=>'handoff_116','assignment_id'=>'work_116','from_session_id'=>'session_1','to_session_id'=>'session_2','objective'=>'Continuar implementación del runtime','issue_ref'=>'pl0n3r/ControlBot#116','pr_ref'=>'pl0n3r/ControlBot#117','sha'=>str_repeat('a',40),'last_result'=>'Tests relevantes en verde','evidence_ref'=>'controlbot:runtime/116/evidence','blocker'=>'Pendiente revisión','next_action'=>'Revisar diff y ejecutar AC exactos'];
    $parsed=AgentRuntime::handoff($row);
    echo json_encode([
        'handoff'=>$parsed,
        'no_transcript'=>!array_key_exists('transcript',$parsed),
        'secret'=>rejected(fn()=>AgentRuntime::handoff(array_replace($row,['last_result'=>'token=supersecret']))),
        'ambiguous_issue'=>rejected(fn()=>AgentRuntime::handoff(array_replace($row,['issue_ref'=>'issue-116']))),
        'ambiguous_pr'=>rejected(fn()=>AgentRuntime::handoff(array_replace($row,['pr_ref'=>'117']))),
        'external_evidence'=>rejected(fn()=>AgentRuntime::handoff(array_replace($row,['evidence_ref'=>'https://example.com/evidence']))),
        'sensitive_evidence'=>rejected(fn()=>AgentRuntime::handoff(array_replace($row,['evidence_ref'=>'https://github.com/pl0n3r/ControlBot/token/value']))),
    ], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'privacy') {
    echo json_encode(['account_password'=>rejected(fn()=>AgentRuntime::account($account+['password'=>'x'])),'session_token'=>rejected(fn()=>AgentRuntime::session(sessionRow('session_1')+['session_token'=>'x'])),'secret_alias'=>rejected(fn()=>AgentRuntime::account(array_replace($account,['account_alias'=>'token=supersecret']))),'handoff_transcript'=>rejected(fn()=>AgentRuntime::handoff(['version'=>1,'handoff_id'=>'handoff_116','assignment_id'=>'work_116','from_session_id'=>'session_1','to_session_id'=>null,'objective'=>'Continuar','issue_ref'=>'pl0n3r/ControlBot#116','pr_ref'=>null,'sha'=>str_repeat('a',40),'last_result'=>'ok','evidence_ref'=>'controlbot:runtime/116','blocker'=>null,'next_action'=>'seguir','transcript'=>'chat completo']))], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
fwrite(STDERR,"Unknown runtime scenario\n"); exit(2);
