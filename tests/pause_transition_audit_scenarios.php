<?php
declare(strict_types=1);

require __DIR__.'/../src/PauseControl.php';
require __DIR__.'/../src/PauseTransitionAudit.php';

use ControlBot\Runtime\PauseTransitionAudit;

function rejected(callable $fn): bool {
    try {$fn(); return false;} catch(InvalidArgumentException) {return true;}
}
function pauseState(string $state,array $replace=[]): array {
    $base=[
        'version'=>1,'pause_id'=>'pause-project','scope_type'=>'project','scope_id'=>'controlbot',
        'state'=>$state,'reason'=>'Operational freeze','source'=>'owner','created_at'=>100,
        'activated_at'=>null,'released_at'=>null,'preemptibility'=>'immediate','safe_point_at'=>null,
        'policy_version'=>null,'incident_id'=>null,'evidence_ref'=>null,
    ];
    if(in_array($state,['active','releasing','released'],true)) $base['activated_at']=101;
    if($state==='released') $base['released_at']=120;
    return array_replace($base,$replace);
}
function active(array $replace=[]): array { return pauseState('active',$replace); }
function releasing(array $replace=[]): array { return pauseState('releasing',$replace); }
function released(array $replace=[]): array { return pauseState('released',$replace); }
function forged(array $event,array $changes): array {
    $row=array_replace($event,$changes);
    $basis=$row;unset($basis['fingerprint']);
    $row['fingerprint']=hash('sha256',json_encode($basis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    return $row;
}

$scenario=$argv[1]??'';
if($scenario==='transition'){
    $create=PauseTransitionAudit::event(null,active(),'owner-main',101);
    $release=PauseTransitionAudit::event(active(),releasing(),'owner-main',110);
    echo json_encode([
        'create'=>$create,'release'=>$release,
        'same_create'=>$create===PauseTransitionAudit::event(null,active(),'owner-main',101),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='invalid'){
    echo json_encode([
        'identity'=>rejected(fn()=>PauseTransitionAudit::event(active(),releasing(['pause_id'=>'pause-other']),'owner-main',110)),
        'scope'=>rejected(fn()=>PauseTransitionAudit::event(active(),releasing(['scope_id'=>'other-project']),'owner-main',110)),
        'provenance'=>rejected(fn()=>PauseTransitionAudit::event(active(),releasing(['reason'=>'Different reason']),'owner-main',110)),
        'activation_drift'=>rejected(fn()=>PauseTransitionAudit::event(active(),releasing(['activated_at'=>102]),'owner-main',110)),
        'safe_point_drift'=>rejected(fn()=>PauseTransitionAudit::event(
            active(['preemptibility'=>'safe_point','safe_point_at'=>100]),
            releasing(['preemptibility'=>'safe_point','safe_point_at'=>99]),
            'owner-main',110
        )),
        'invalid_state'=>rejected(fn()=>PauseTransitionAudit::event(active(),active(),'owner-main',110)),
        'invalid_creation'=>rejected(fn()=>PauseTransitionAudit::event(null,released(),'owner-main',120)),
        'future_evidence'=>rejected(fn()=>PauseTransitionAudit::event(null,active(['activated_at'=>110]),'owner-main',105)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='append'){
    $first=PauseTransitionAudit::event(null,active(),'owner-main',101);
    $second=PauseTransitionAudit::event(active(),releasing(),'owner-main',110);
    $history=PauseTransitionAudit::append([], $first);
    $history=PauseTransitionAudit::append($history,$second);
    $replay=PauseTransitionAudit::append($history,$second);
    $conflict=PauseTransitionAudit::event(active(),releasing(),'owner-other',110);
    $drift=PauseTransitionAudit::event(active(['scope_id'=>'other-project']),releasing(['scope_id'=>'other-project']),'owner-main',115);
    echo json_encode([
        'count'=>count($history),'replay_same'=>$replay===$history,
        'conflict'=>rejected(fn()=>PauseTransitionAudit::append($history,$conflict)),
        'tail_event'=>$history[1],
        'null_identity'=>rejected(fn()=>PauseTransitionAudit::append([],forged($first,['pause_id'=>null]))),
        'invalid_pair'=>rejected(fn()=>PauseTransitionAudit::append([],forged($first,['before_state'=>'active']))),
        'chain_gap'=>rejected(fn()=>PauseTransitionAudit::append($history,PauseTransitionAudit::event(active(),released(),'owner-main',120))),
        'second_creation'=>rejected(fn()=>PauseTransitionAudit::append($history,PauseTransitionAudit::event(null,pauseState('unknown'),'owner-main',130))),
        'history_provenance'=>rejected(fn()=>PauseTransitionAudit::append([$first],$drift)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='history'){
    $first=PauseTransitionAudit::event(null,active(),'owner-main',101);
    $second=PauseTransitionAudit::event(active(),releasing(),'owner-main',110);
    $third=PauseTransitionAudit::event(releasing(),released(),'owner-main',120);
    echo json_encode([
        'regressive_new'=>rejected(fn()=>PauseTransitionAudit::append([$second],$first)),
        'reordered_existing'=>rejected(fn()=>PauseTransitionAudit::append([$second,$first],$third)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='secret'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/PauseTransitionAudit.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','runnergateway','factoryrunner','file_put_contents','fopen('];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode([
        'actor_secret'=>rejected(fn()=>PauseTransitionAudit::event(null,active(),'token:abcd',101)),
        'actor_pii'=>rejected(fn()=>PauseTransitionAudit::event(null,active(),'3001234567',101)),
        'reason_secret'=>rejected(fn()=>PauseTransitionAudit::event(null,active(['reason'=>'password=abcd']),'owner-main',101)),
        'reason_pii'=>rejected(fn()=>PauseTransitionAudit::event(null,active(['reason'=>'contact owner@example.com']),'owner-main',101)),
        'evidence_pii'=>rejected(fn()=>PauseTransitionAudit::event(
            null,
            active(['source'=>'policy','policy_version'=>'policy:v1','incident_id'=>'incident:374','evidence_ref'=>'controlbot:owner@example.com']),
            'owner-main',101
        )),
        'hits'=>$hits,
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown pause transition audit scenario\n");exit(2);
