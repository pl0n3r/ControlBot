<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
require __DIR__.'/../src/FactoryOrchestratorSnapshotSource.php';
use ControlBot\Business\{FactoryOrchestratorEvidenceCollector,FactoryOrchestratorSnapshotSource};

$scenario=$argv[1]??'';$calls=[];

function issue(int $n,array $labels=[]):array
{return ['number'=>$n,'title'=>'Issue '.$n,'labels'=>array_map(fn($x)=>['name'=>$x],$labels)];}

function response(array $json,int $status=200,array $headers=['x-ratelimit-remaining'=>'100']):array
{
 $body=json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 return ['status'=>$status,'headers'=>$headers,'bytes'=>strlen($body),'json'=>$json];
}

function canonicalKillSwitch():array
{
 return [
  'number'=>767,
  'user'=>['login'=>'pl0n3r'],
  'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->',
 ];
}

$transport=static function(string $method,string $url,array $headers)use(&$calls):array{
 $calls[]=[
  'method'=>$method,
  'url'=>$url,
  'authorized'=>isset($headers['Authorization'])&&str_starts_with($headers['Authorization'],'Bearer '),
  'user_agent'=>$headers['User-Agent']??null,
  'api_version'=>$headers['X-GitHub-Api-Version']??null,
 ];
 $path=parse_url($url,PHP_URL_PATH)?:'';$json=[];
 if(str_ends_with($path,'/issues/767'))$json=canonicalKillSwitch();
 elseif(str_ends_with($path,'/issues'))$json=[
  issue(10,['estado: disponible']),
  issue(11,['estado: bloqueado']),
  issue(12,['decisión: dueño']),
  ['number'=>13,'title'=>'PR 13','labels'=>[],'pull_request'=>[]],
 ];
 elseif(str_ends_with($path,'/pulls'))$json=[['number'=>9,'merged_at'=>'2026-10-04T20:00:00Z']];
 return response($json);
};

function runCollector(callable $transport):array
{
 $dir=sys_get_temp_dir().'/cb692-'.bin2hex(random_bytes(4));mkdir($dir);
 $credential=$dir.'/credential';$evidence=$dir.'/evidence.json';
 file_put_contents($credential,'sentinel-read-value');chmod($credential,0600);
 $result=FactoryOrchestratorEvidenceCollector::run([
  'CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
  'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$credential,
  'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence,
 ],$transport,200);
 return [$result,json_decode(file_get_contents($evidence),true,64,JSON_THROW_ON_ERROR)];
}

if($scenario==='canonical'){
 $x=runCollector($transport);
 $snapshot=FactoryOrchestratorSnapshotSource::fromInjectedEvidence($x[1],220);
 echo json_encode(['result'=>$x[0],'evidence'=>$x[1],'snapshot'=>$snapshot,'calls'=>$calls],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 exit;
}

if($scenario==='failure'){
 $dir=sys_get_temp_dir().'/cb692-fail-'.bin2hex(random_bytes(4));mkdir($dir);
 $credential=$dir.'/credential';$evidence=$dir.'/evidence.json';
 file_put_contents($credential,'sentinel-read-value');chmod($credential,0600);
 file_put_contents($evidence,'{"old":true}');
 $bad=static fn()=>['status'=>429,'headers'=>['retry-after'=>'60','x-ratelimit-remaining'=>'0'],'bytes'=>0];
 $failed=false;
 try{
  FactoryOrchestratorEvidenceCollector::run([
   'CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
   'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$credential,
   'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence,
  ],$bad,200);
 }catch(Throwable){$failed=true;}
 echo json_encode(['failed'=>$failed,'previous'=>file_get_contents($evidence)]);
 exit;
}

if($scenario==='transport-contract'){
 $retryCalls=0;
 $retry=static function(string $method,string $url,array $headers)use(&$retryCalls):array{
  $retryCalls++;
  if($retryCalls===1)return ['status'=>502,'headers'=>['x-ratelimit-remaining'=>'100'],'bytes'=>18];
  $path=parse_url($url,PHP_URL_PATH)?:'';
  $json=str_ends_with($path,'/issues/767')?canonicalKillSwitch():[];
  return response($json);
 };
 $ok=runCollector($retry);

 $dir=sys_get_temp_dir().'/cb692-missing-bytes-'.bin2hex(random_bytes(4));mkdir($dir);
 $credential=$dir.'/credential';$evidence=$dir.'/evidence.json';
 file_put_contents($credential,'sentinel-read-value');chmod($credential,0600);
 file_put_contents($evidence,'{"old":true}');
 $missingBytes=static fn()=>['status'=>200,'headers'=>['x-ratelimit-remaining'=>'100'],'json'=>[]];
 $missingFailed=false;
 try{
  FactoryOrchestratorEvidenceCollector::run([
   'CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
   'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$credential,
   'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence,
  ],$missingBytes,200);
 }catch(Throwable){$missingFailed=true;}

 echo json_encode([
  'retry_result'=>$ok[0],
  'retry_calls'=>$retryCalls,
  'missing_bytes_failed'=>$missingFailed,
  'previous'=>file_get_contents($evidence),
 ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 exit;
}

if($scenario==='kill-switch-cases'){
 $collect=static function(array $kill):array{
  $fake=static function(string $method,string $url,array $headers)use($kill):array{
   $path=parse_url($url,PHP_URL_PATH)?:'';
   return response(str_ends_with($path,'/issues/767')?$kill:[]);
  };
  return runCollector($fake)[1];
 };
 $canonical=canonicalKillSwitch();
 $wrongNumber=$canonical;$wrongNumber['number']=766;
 $wrongAuthor=$canonical;$wrongAuthor['user']['login']='someone-else';
 $duplicate=$canonical;$duplicate['body'].=' <!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->';
 $invalid=$canonical;$invalid['body']='<!-- factory-unattended-kill-switch {"version":1,"state":"PAUSED","owner":"pl0n3r"} -->';
 $rows=[
  'canonical'=>$collect($canonical),
  'wrong_number'=>$collect($wrongNumber),
  'wrong_author'=>$collect($wrongAuthor),
  'duplicate'=>$collect($duplicate),
  'invalid'=>$collect($invalid),
 ];
 $out=[];
 foreach($rows as $key=>$evidence)
  $out[$key]=array_values(array_filter($evidence['blockers'],static fn(array $row):bool=>($row['id']??null)==='blocker:factory-767'));
 echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 exit;
}

if($scenario==='recent-pulls'){
 $recentCalls=[];
 $fake=static function(string $method,string $url,array $headers)use(&$recentCalls):array{
  $recentCalls[]=$url;$path=parse_url($url,PHP_URL_PATH)?:'';
  if(str_ends_with($path,'/issues/767'))return response(canonicalKillSwitch());
  if(str_ends_with($path,'/issues'))return response([]);
  if(str_ends_with($path,'/pulls')){
   $rows=[['number'=>900,'merged_at'=>'2026-10-04T20:00:00Z']];
   for($i=1;$i<100;$i++)$rows[]=['number'=>900+$i,'merged_at'=>null];
   return response($rows);
  }
  return response([]);
 };
 $x=runCollector($fake);
 echo json_encode(['result'=>$x[0],'evidence'=>$x[1],'calls'=>$recentCalls],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 exit;
}

fwrite(STDERR,"scenario invalid\n");exit(2);
