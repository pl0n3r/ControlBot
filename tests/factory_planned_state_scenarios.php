<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
require __DIR__.'/../src/FactoryAgentActivity.php';
require __DIR__.'/../src/FactoryAgentActivityUi.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as Collector;
use ControlBot\Business\FactoryAgentActivity as Activity;
use ControlBot\Business\FactoryAgentActivityUi as Ui;

$mode=$argv[1]??'valid';
$now=1900000000; // Injected deterministic UTC clock; no live calls.
$stamp='2026-10-11T08:00:00Z';
function workIssue(int $number,array $labels,string $stamp):array {
    return ['number'=>$number,'updated_at'=>$stamp,
        'labels'=>array_map(static fn(string $name):array=>['name'=>$name],$labels)];
}
$issues=[
    workIssue(1,['estado: planificado'],$stamp),
    workIssue(2,['estado: bloqueado'],$stamp),
    workIssue(3,['estado: disponible'],$stamp),
    workIssue(4,['estado: reservado'],$stamp),
    workIssue(5,['status: planned','estado: planificado'],$stamp),
];
if($mode==='available-planned')$issues[2]['labels']=[['name'=>'estado: disponible'],['name'=>'status: planned']];
if($mode==='available-blocked')$issues[2]['labels']=[['name'=>'estado: disponible'],['name'=>'estado: bloqueado']];
if($mode==='blocked-planned')$issues[2]['labels']=[['name'=>'estado: bloqueado'],['name'=>'estado: planificado']];
if($mode==='bad-clock')$issues[1]['updated_at']='2039-12-01T00:00:00Z';
if($mode==='bad-label')$issues[2]['labels']=[['not_name'=>'estado: disponible']];
if($mode==='legacy')$issues=[
    workIssue(1,['status: planned'],$stamp),
    workIssue(2,['status: blocked'],$stamp),
    workIssue(3,['status: available'],$stamp),
    workIssue(4,['status: reserved'],$stamp),
    workIssue(5,['status: in review'],$stamp),
    workIssue(6,['estado: planificado','<script>alert(1)</script>'],$stamp),
];
$calls=[];
$transport=static function(string $method,string $url,array $headers)use(&$calls,$issues,$mode,$stamp):array{
    $path=parse_url($url,PHP_URL_PATH)?:'';
    $query=[];parse_str(parse_url($url,PHP_URL_QUERY)?:'',$query);
    $calls[]=['method'=>$method,'path'=>$path,'page'=>$query['page']??null];
    if(str_ends_with($path,'/issues/767')) {
        $json=['number'=>767,'state'=>'closed','user'=>['login'=>'pl0n3r'],
            'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];
    } elseif(preg_match('~^/repos/pl0n3r/[^/]+/issues$~',$path)===1) {
        $json=$mode==='bad-page'?array_fill(0,100,workIssue(1,['estado: planificado'],$stamp)):$issues;
    } elseif(str_ends_with($path,'/pulls')) {
        $json=[];
    } elseif(str_ends_with($path,'/actions/runs')) {
        $json=['workflow_runs'=>[]];
    } else {
        throw new RuntimeException('fake url unexpected.');
    }
    return ['status'=>200,'headers'=>['x-ratelimit-remaining'=>'100'],
        'bytes'=>strlen(json_encode($json,JSON_THROW_ON_ERROR)),'json'=>$json];
};
$dir=sys_get_temp_dir().'/cb-planned-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$token=$dir.'/token';$out=$dir.'/evidence.json';
file_put_contents($token,'synthetic-read-only');chmod($token,0600);
try {
    $run=Collector::run(['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
        'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$token,
        'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$out],$transport,$now);
    $evidence=json_decode(file_get_contents($out),true,64,JSON_THROW_ON_ERROR);
    $view=Activity::build($evidence['agent_activity'],$now);
    $html=Ui::renderSection($view);
    echo json_encode(['failed'=>false,'run'=>$run,'evidence'=>$evidence,
        'view'=>$view,'html'=>$html,'calls'=>$calls],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch(Throwable $e) {
    echo json_encode(['failed'=>true,'diagnostic'=>Collector::diagnosticFor($e),
        'artifact_written'=>is_file($out),'calls'=>$calls],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}
