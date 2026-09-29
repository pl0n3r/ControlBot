<?php
declare(strict_types=1);
require __DIR__ . '/../src/PauseControl.php';
use ControlBot\Runtime\PauseControl;

function rejected(callable $work): bool { try { $work(); return false; } catch (InvalidArgumentException) { return true; } }
function contextRow(): array { return ['version'=>1,'session_id'=>'session_1','account_id'=>'account_main','project_id'=>'project-controlbot']; }
function pauseRow(string $scope, string $state='active', string $preemptibility='immediate', string $source='owner'): array {
    $ids=['session'=>'session_1','account'=>'account_main','project'=>'project-controlbot','global'=>'global'];
    $needsSafe=$preemptibility!=='immediate' && $state!=='unknown';
    $activated=$state==='unknown'?null:110;
    $released=$state==='released'?150:null;
    return [
        'version'=>1,'pause_id'=>'pause_'.$scope,'scope_type'=>$scope,'scope_id'=>$ids[$scope],'state'=>$state,
        'reason'=>'maintenance window','source'=>$source,'created_at'=>100,'activated_at'=>$activated,'released_at'=>$released,
        'preemptibility'=>$preemptibility,'safe_point_at'=>$needsSafe?105:null,
        'policy_version'=>$source==='policy'?'policy:v1':null,'incident_id'=>$source==='policy'?'incident:330':null,
        'evidence_ref'=>$source==='policy'?'controlbot:evidence/330':null,
    ];
}
$scenario=$argv[1]??'';
if($scenario==='precedence'){
    $rows=[pauseRow('session'),pauseRow('account'),pauseRow('project'),pauseRow('global')];
    echo json_encode(['forward'=>PauseControl::effective(contextRow(),$rows),'reverse'=>PauseControl::effective(contextRow(),array_reverse($rows)),'without_global'=>PauseControl::effective(contextRow(),array_slice($rows,0,3))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='invalid'){
    $invalid=pauseRow('session');$invalid['state']='invented';
    $foreign=pauseRow('account');$foreign['scope_id']='account_other';
    $unknown=pauseRow('global','unknown');
    echo json_encode(['invalid'=>rejected(fn()=>PauseControl::state($invalid)),'foreign'=>rejected(fn()=>PauseControl::effective(contextRow(),[$foreign])),'unknown'=>PauseControl::effective(contextRow(),[$unknown])],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='preemptibility'){
    $bad=pauseRow('session','active','non_preemptible');$bad['safe_point_at']=null;
    $badSafe=pauseRow('account','active','safe_point');$badSafe['safe_point_at']=null;
    echo json_encode(['non_preemptible_missing'=>rejected(fn()=>PauseControl::state($bad)),'safe_point_missing'=>rejected(fn()=>PauseControl::state($badSafe)),'non_preemptible'=>PauseControl::state(pauseRow('session','active','non_preemptible')),'immediate'=>PauseControl::state(pauseRow('session'))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='release'){
    $active=pauseRow('session');$released=PauseControl::release($active,150);$again=PauseControl::release($released,999);$extra=$active+['authority'=>'allow'];
    echo json_encode(['released'=>$released,'idempotent'=>$released===$again,'same_keys'=>array_keys($active)===array_keys($released),'extra_rejected'=>rejected(fn()=>PauseControl::state($extra)),'effective'=>PauseControl::effective(contextRow(),[$released])],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='policy'){
    $valid=pauseRow('project','active','safe_point','policy');$missing=[];
    foreach(['policy_version','incident_id','evidence_ref'] as $key){$row=$valid;$row[$key]=null;$missing[$key]=rejected(fn()=>PauseControl::state($row));}
    echo json_encode(['valid'=>PauseControl::state($valid),'missing'=>$missing],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='scopes'){
    $out=[];foreach(['session','account','project','global'] as $scope){$out[$scope]=PauseControl::effective(contextRow(),[pauseRow($scope)]);}echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown pause scenario\n");exit(2);
