<?php
declare(strict_types=1);

require __DIR__.'/../src/PauseControl.php';
require __DIR__.'/../src/ProductionOperation.php';
require __DIR__.'/../src/PauseProductionGate.php';

use ControlBot\Runtime\PauseControl;
use ControlBot\Runtime\PauseProductionGate;

function rejected(callable $fn): bool {
    try {$fn(); return false;} catch(InvalidArgumentException) {return true;}
}
function context(): array { return [
    'version'=>1,'session_id'=>'session-main','account_id'=>'account-main','project_id'=>'controlbot',
]; }
function pauseState(string $scope,string $id,string $pauseId,string $state='active'): array {
    $active=in_array($state,['active','releasing','released'],true);
    return [
        'version'=>1,'pause_id'=>$pauseId,'scope_type'=>$scope,'scope_id'=>$id,'state'=>$state,
        'reason'=>'Operational freeze','source'=>'owner','created_at'=>100,
        'activated_at'=>$active?101:null,'released_at'=>$state==='released'?120:null,
        'preemptibility'=>'immediate','safe_point_at'=>null,
        'policy_version'=>null,'incident_id'=>null,'evidence_ref'=>null,
    ];
}
function projectPause(): array { return pauseState('project','controlbot','pause-project'); }
function gate(string $operation,array $states=null,array $ctx=null): array {
    return PauseProductionGate::evaluate($ctx??context(),$states??[projectPause()],$operation);
}

$scenario=$argv[1]??'';
if($scenario==='project'){
    echo json_encode([
        'write'=>gate('database.backup'),
        'read'=>gate('hostinger.read'),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='precedence'){
    $states=[
        pauseState('session','session-main','pause-session'),
        pauseState('account','account-main','pause-account'),
        pauseState('project','controlbot','pause-project'),
        pauseState('global','global','pause-global'),
    ];
    echo json_encode(gate('database.backup',$states),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='invalid'){
    echo json_encode([
        'unknown_write'=>gate('database.backup',[pauseState('project','controlbot','pause-unknown','unknown')]),
        'unknown_operation'=>rejected(fn()=>gate('unknown.operation')),
        'scope_mismatch'=>rejected(fn()=>gate('database.backup',[pauseState('project','other-project','pause-other')])),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='release'){
    $released=PauseControl::release(projectPause(),120);
    echo json_encode([
        'active'=>gate('database.backup'),
        'released'=>gate('database.backup',[$released]),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='deterministic'){
    $one=gate('database.backup');$two=gate('database.backup');
    $source=strtolower(file_get_contents(__DIR__.'/../src/PauseProductionGate.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','runnergateway','factoryrunner','file_put_contents'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode(['same'=>$one===$two,'fingerprint'=>$one['fingerprint']===$two['fingerprint'],'hits'=>$hits],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown pause production gate scenario\n");exit(2);
