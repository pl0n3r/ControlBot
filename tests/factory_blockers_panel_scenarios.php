<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
require __DIR__.'/../src/FactoryOrchestratorSnapshotSource.php';
require __DIR__.'/../src/FactoryOrchestratorLiveUi.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as Collector;
use ControlBot\Business\FactoryOrchestratorSnapshotSource as Source;
use ControlBot\Business\FactoryOrchestratorLiveUi as Ui;

$mode=$argv[1]??'grounded';
$now=1900000000;
$stamp='2026-10-11T08:00:00Z';
function blocker(int $n,array $labels,?array $marker=null,string $updated='2026-10-11T08:00:00Z'):array {
 $body=$marker===null?'No independent evidence.':'<!-- factory-blocker-evidence '.json_encode($marker,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).' -->';
 return ['number'=>$n,'updated_at'=>$updated,'labels'=>array_map(static fn($x)=>['name'=>$x],$labels),'body'=>$body];
}
function evidence(string $cause,string $action,string $actor='agent',bool $impact=false,string $time='2026-10-11T08:00:00Z'):array {
 return ['version'=>1,'cause'=>$cause,'actor'=>$actor,'next_action'=>$action,'blocked_at'=>$time,'mvp_impact'=>$impact];
}
$issues=[
 blocker(10,['estado: bloqueado'],evidence('Pruebas CI sin terminar','Reparar verificacion exacta','agent',true,'2026-10-10T06:00:00Z')),
 blocker(11,['estado: bloqueado'],null),
 blocker(12,['estado: planificado'],evidence('No es bloqueo real','Solo planificado','owner',true)),
 blocker(13,['estado: bloqueado','estado: planificado'],evidence('Estado contradictorio','No inferir accion','owner',true)),
 blocker(14,['status: blocked'],evidence('Dependencia por verificar','Verificar dependencia','external',false,'2026-10-10T05:00:00Z')),
];
if($mode==='no-provenance')$issues[0]['body']='<!-- factory-blocker-evidence {bad -->';
if($mode==='invalid-time')$issues[0]['body']='<!-- factory-blocker-evidence '.json_encode(evidence('Pruebas CI sin terminar','Reparar verificacion exacta','agent',true,'2039-01-01T00:00:00Z'),JSON_THROW_ON_ERROR).' -->';
if($mode==='unsafe')$issues[0]['body']='<!-- factory-blocker-evidence '.json_encode(evidence('<script>injection</script>','Reparar verificacion exacta'),JSON_THROW_ON_ERROR).' -->';
if($mode==='duplicate')$issues[0]['body'].="\n<!-- factory-blocker-evidence {bad -->";
if($mode==='invalid-page')$issues=array_fill(0,100,blocker(30,['estado: bloqueado']));
$calls=[];
$transport=static function(string $method,string $url,array $headers)use(&$calls,$issues):array {
 $path=parse_url($url,PHP_URL_PATH)?:'';
 $query=[];parse_str(parse_url($url,PHP_URL_QUERY)?:'',$query);
 $calls[]=['method'=>$method,'url'=>$path,'page'=>$query['page']??null];
 if(str_ends_with($path,'/issues/767'))$json=['number'=>767,'state'=>'closed','user'=>['login'=>'pl0n3r'],'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];
 elseif(str_ends_with($path,'/issues'))$json=$issues;
 elseif(str_ends_with($path,'/pulls'))$json=[];
 elseif(str_ends_with($path,'/actions/runs'))$json=['workflow_runs'=>[]];
 else throw new RuntimeException('GET URL unsupported.');
 return ['status'=>200,'headers'=>['x-ratelimit-remaining'=>'100'],
 'bytes'=>strlen(json_encode($json,JSON_THROW_ON_ERROR)),'json'=>$json];
};
$dir=sys_get_temp_dir().'/cb753-'.bin2hex(random_bytes(4));mkdir($dir,0700);
$token=$dir.'/token';$file=$dir.'/snapshot.json';
file_put_contents($token,'fake-only');chmod($token,0600);
try{
 $result=Collector::run(['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
 'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$token,'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$file],$transport,$now);
 $raw=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
 if($mode==='malformed-labels')$raw['blockers'][0]['data']['labels']='estado: bloqueado';
if($mode==='after-source-observation'){
 // Synthetic signed-source contradiction: the declared event follows its observation.
 $raw['blockers'][0]['observed_at']=$now-3600;
 $raw['blockers'][0]['data']['blocker_evidence']['blocked_at']=$now-1800;
}
 $view=Source::fromInjectedEvidence($raw,$now);
 $html=Ui::render($view);
 echo json_encode(['failed'=>false,'result'=>$result,'blockers'=>$view['blocker_explanations']??null,
 'html'=>$html,'calls'=>$calls,'source'=>$raw['blockers']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){
 echo json_encode(['failed'=>true,'reason'=>Collector::diagnosticFor($error),
 'artifact_written'=>is_file($file),'calls'=>$calls],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}
