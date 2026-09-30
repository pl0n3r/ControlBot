<?php
declare(strict_types=1);

require __DIR__.'/../src/PauseControl.php';
require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/RunnerGateway.php';
require __DIR__.'/../src/PauseResumePlan.php';

use ControlBot\Runtime\PauseControl;
use ControlBot\Runtime\PauseResumePlan;

function rejected(callable $fn): bool {
    try {$fn();return false;} catch(InvalidArgumentException){return true;}
}
function pauseState(array $r=[]): array { return array_replace([
    'version'=>1,'pause_id'=>'pause-project','scope_type'=>'project','scope_id'=>'controlbot',
    'state'=>'active','reason'=>'Operational pause','source'=>'owner',
    'created_at'=>100,'activated_at'=>101,'released_at'=>null,
    'preemptibility'=>'immediate','safe_point_at'=>null,
    'policy_version'=>null,'incident_id'=>null,'evidence_ref'=>null,
],$r); }
function released(array $before=null): array {
    return PauseControl::release($before??pauseState(),120);
}
function work(array $r=[]): array { return array_replace([
    'version'=>1,'work_item_id'=>'work-a','project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#385',
    'type'=>'engineering','priority'=>'critical','state'=>'running','dependency_ids'=>[],
    'required_capabilities'=>['deploy.status'],'generation'=>4,'attempt'=>2,
    'reservation_id'=>'reservation-1','assigned_session_id'=>'session-main',
],$r); }
function order(array $r=[]): array { return array_replace([
    'version'=>1,'order_id'=>'11111111-1111-4111-8111-111111111111',
    'attempt_id'=>'22222222-2222-4222-8222-222222222222','generation'=>4,
    'work_item_id'=>'work-a','runner_id'=>'33333333-3333-4333-8333-333333333333',
    'capability'=>'deploy.status','attempt'=>2,'scope'=>'project:controlbot',
    'issued_at'=>110,'expires_at'=>1000,'instruction_ref'=>'controlbot:resume/385',
],$r); }
function plan(array $before=null,array $after=null,array $w=null,array $o=null): array {
    $before=$before??pauseState();
    return PauseResumePlan::plan($before,$after??released($before),$w??work(),$o??order());
}
function withFingerprint(array $plan,array $changes): array {
    $plan=array_replace($plan,$changes);
    unset($plan['fingerprint']);
    $plan['fingerprint']=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    return $plan;
}

$scenario=$argv[1]??'';
if($scenario==='valid'){
    echo json_encode(plan(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='drift'){
    $other=pauseState(['pause_id'=>'pause-other']);
    echo json_encode([
        'work_id'=>rejected(fn()=>plan(null,null,work(['work_item_id'=>'work-b']))),
        'generation'=>rejected(fn()=>plan(null,null,work(['generation'=>5]))),
        'attempt'=>rejected(fn()=>plan(null,null,work(['attempt'=>3]))),
        'order_work'=>rejected(fn()=>plan(null,null,null,order(['work_item_id'=>'work-b']))),
        'pause_identity'=>rejected(fn()=>plan(pauseState(),released($other))),
        'pause_provenance'=>rejected(fn()=>plan(pauseState(),released(pauseState(['reason'=>'Different reason'])))),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='state'){
    echo json_encode([
        'done'=>rejected(fn()=>plan(null,null,work(['state'=>'done']))),
        'unowned'=>rejected(fn()=>plan(null,null,work(['state'=>'queued','reservation_id'=>null,'assigned_session_id'=>null]))),
        'unreleased'=>rejected(fn()=>plan(pauseState(),pauseState())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='replay'){
    $base=plan();
    PauseResumePlan::assertReplay($base,$base);
    $conflict=withFingerprint($base,['scope'=>'project:other']);
    echo json_encode([
        'exact'=>true,
        'conflict'=>rejected(fn()=>PauseResumePlan::assertReplay($base,$conflict)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='deterministic'){
    $one=plan();$two=plan();
    $source=strtolower(file_get_contents(__DIR__.'/../src/PauseResumePlan.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','file_put_contents','factoryrunner'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode([
        'same'=>$one===$two,'fingerprint'=>$one['fingerprint']===$two['fingerprint'],'hits'=>$hits,
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown pause resume scenario\n");exit(2);
