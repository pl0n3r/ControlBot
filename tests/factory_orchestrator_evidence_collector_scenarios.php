<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
require __DIR__.'/../src/FactoryOrchestratorSnapshotSource.php';
use ControlBot\Business\{FactoryOrchestratorEvidenceCollector,FactoryOrchestratorSnapshotSource};
$scenario=$argv[1]??'';$calls=[];
function issue(int $n,array $labels=[]):array{return ['number'=>$n,'title'=>'Issue '.$n,'labels'=>array_map(fn($x)=>['name'=>$x],$labels)];}
$transport=static function(string $method,string $url,array $headers)use(&$calls):array{
 $calls[]=['method'=>$method,'url'=>$url,'authorized'=>isset($headers['Authorization'])&&str_starts_with($headers['Authorization'],'Bearer ')];$path=parse_url($url,PHP_URL_PATH)?:'';$json=[];
 if(str_ends_with($path,'/issues/767'))$json=['number'=>767,'user'=>['login'=>'pl0n3r'],'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];
 elseif(str_ends_with($path,'/issues'))$json=[issue(10,['estado: disponible']),issue(11,['estado: bloqueado']),issue(12,['decisión: dueño']),['number'=>13,'title'=>'PR 13','labels'=>[],'pull_request'=>[]]];
 elseif(str_ends_with($path,'/pulls'))$json=[['number'=>9,'merged_at'=>'2026-10-04T20:00:00Z']];
 $bytes=strlen(json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));return ['status'=>200,'headers'=>['x-ratelimit-remaining'=>'100'],'bytes'=>$bytes,'json'=>$json];
};
function runCollector(callable $transport):array{
 $dir=sys_get_temp_dir().'/cb685-'.bin2hex(random_bytes(4));mkdir($dir);$credential=$dir.'/credential';$evidence=$dir.'/evidence.json';file_put_contents($credential,'sentinel-read-value');chmod($credential,0600);
 $result=FactoryOrchestratorEvidenceCollector::run(['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1','CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$credential,'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence],$transport,200);
 return [$result,json_decode(file_get_contents($evidence),true,64,JSON_THROW_ON_ERROR)];
}
if($scenario==='canonical'){
 foreach([
  static fn()=>null,
  static fn()=>['status'=>'bad','headers'=>[],'bytes'=>0],
  static fn()=>['status'=>200,'headers'=>[]],
  static fn()=>['status'=>200,'headers'=>[],'bytes'=>2_000_001,'json'=>[]],
 ] as $invalid)try{runCollector($invalid);}catch(Throwable){}
 $x=runCollector($transport);$snapshot=FactoryOrchestratorSnapshotSource::fromInjectedEvidence($x[1],220);
 echo json_encode(['result'=>$x[0],'evidence'=>$x[1],'snapshot'=>$snapshot,'calls'=>$calls],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;
}
if($scenario==='failure'){$dir=sys_get_temp_dir().'/cb685-fail-'.bin2hex(random_bytes(4));mkdir($dir);$credential=$dir.'/credential';$evidence=$dir.'/evidence.json';file_put_contents($credential,'sentinel-read-value');chmod($credential,0600);file_put_contents($evidence,'{"old":true}');$bad=static fn()=>['status'=>429,'headers'=>['retry-after'=>'60','x-ratelimit-remaining'=>'0'],'bytes'=>0];$failed=false;try{FactoryOrchestratorEvidenceCollector::run(['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1','CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$credential,'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence],$bad,200);}catch(Throwable){$failed=true;}echo json_encode(['failed'=>$failed,'previous'=>file_get_contents($evidence)]);exit;}
if($scenario==='transport-negative-branches'){
 $results=[];
 foreach([
  ['POST','https://api.github.com/repos/x'],
  ['GET','http://api.github.com/repos/x'],
  ['GET','https://example.com/repos/x'],
  ['GET','https://u:p@api.github.com/repos/x'],
  ['GET','https://api.github.com:444/repos/x'],
 ] as $case){$failed=false;try{FactoryOrchestratorEvidenceCollector::validateLiveRequest($case[0],$case[1]);}catch(Throwable){$failed=true;}$results[]=$failed;}
 foreach([
  static fn()=>FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,[],false),
  static fn()=>FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,[],'not-json'),
 ] as $case){$failed=false;try{$case();}catch(Throwable){$failed=true;}$results[]=$failed;}
 $retry=FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(502,[],'<html>bad gateway</html>');
 echo json_encode(['failed'=>$results,'retry'=>$retry],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;
}
fwrite(STDERR,"scenario invalid\n");exit(2);
