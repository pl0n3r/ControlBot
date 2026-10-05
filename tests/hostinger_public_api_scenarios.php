<?php
declare(strict_types=1);
require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/CapabilityGrant.php';
require __DIR__.'/../src/HostingerPublicApi.php';
require __DIR__.'/../src/HostingerPublicApiAdapter.php';

use ControlBot\Production\{CapabilityGrant,HostingerPublicApi,HostingerPublicApiAdapter};

$scenario=$argv[1]??'';
$calls=[];
$transport=static function(string $method,string $url,array $headers)use(&$calls):array{
 $calls[]=['method'=>$method,'url'=>$url];
 $path=parse_url($url,PHP_URL_PATH)?:'';
 $body=$path==='/api/hosting/v1/websites'
  ? ['data'=>[['domain'=>'control.example.test','website_type'=>'other']]]
  : (str_ends_with($path,'/output')
   ? ['output'=>'collector ok']
   : [['uid'=>'cron_1','time'=>'*/5 * * * *','command'=>'php collector.php']]);
 return ['status'=>200,'headers'=>['x-ratelimit-remaining'=>'89'],'body'=>json_encode($body,JSON_THROW_ON_ERROR)];
};
$token='sentinel-hostinger-token';
$now=strtotime('2026-10-05T00:10:00Z');

function grant():CapabilityGrant{
 return CapabilityGrant::issue([
  'version'=>1,'grant_id'=>'11111111-1111-4111-8111-111111111111',
  'capability'=>'cron.write','project'=>'controlbot','environment'=>'production',
  'resource'=>'cron:orchestrator','operation'=>'create','issue'=>'pl0n3r/ControlBot#707',
  'run_id'=>'22222222-2222-4222-8222-222222222222','subject'=>'controlbot-worker',
  'issued_at'=>'2026-10-05T00:00:00Z','expires_at'=>'2026-10-05T00:30:00Z',
  'backup_receipt_id'=>'33333333-3333-4333-8333-333333333333',
  'owner_approval_id'=>null,'idempotency_key'=>'hostinger-cron-707','revoked_at'=>null,
 ]);
}
function scope():array{return [
 'capability'=>'cron.write','project'=>'controlbot','environment'=>'production',
 'resource'=>'cron:orchestrator','operation'=>'create','issue'=>'pl0n3r/ControlBot#707',
 'run_id'=>'22222222-2222-4222-8222-222222222222','subject'=>'controlbot-worker',
];}

if($scenario==='canonical'){
 $web=HostingerPublicApiAdapter::websites($token,$transport,$now);
 $cron=HostingerPublicApiAdapter::cronSnapshot('u123456789',$token,$transport,$now);
 $output=HostingerPublicApiAdapter::cronOutput('u123456789','cron_1',$token,$transport,$now);
 $before=count($calls);
 $plan=HostingerPublicApiAdapter::planCronWrite(grant(),scope(),'u123456789',['time'=>'*/5 * * * *','command'=>'php collector.php'],$now);
 echo json_encode(compact('web','cron','output','plan','calls','before'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;
}
if($scenario==='guards'){
 $fail=[];
 foreach([
  ['POST','https://developers.hostinger.com/api/hosting/v1/websites'],
  ['GET','http://developers.hostinger.com/api/hosting/v1/websites'],
  ['GET','https://example.com/api/hosting/v1/websites'],
  ['GET','https://u:p@developers.hostinger.com/api/hosting/v1/websites'],
  ['GET','https://developers.hostinger.com:444/api/hosting/v1/websites'],
  ['GET','https://developers.hostinger.com/api/hosting/v1/unknown'],
 ] as $case){try{HostingerPublicApi::request($case[0],$case[1],$token,$transport);$fail[]=false;}catch(Throwable){$fail[]=true;}}
 echo json_encode(['failed'=>$fail],JSON_THROW_ON_ERROR);exit;
}
if($scenario==='url-semantics'){
 $fail=[];
 foreach([
  'https://developers.hostinger.com/api/hosting/v1/websites?limit=1',
  'https://developers.hostinger.com/api/hosting/v1/websites#fragment',
  'https://developers.hostinger.com/api/hosting/v1/accounts/u123456789/cron-jobs?cursor=next',
 ] as $url){
  try{HostingerPublicApi::request('GET',$url,$token,$transport);$fail[]=false;}catch(Throwable){$fail[]=true;}
 }
 echo json_encode(['failed'=>$fail],JSON_THROW_ON_ERROR);exit;
}
if($scenario==='secret-evidence'){
 $variants=[
  'php collector.php --token sentinel-cli-secret',
  'php collector.php --password=sentinel-password-secret',
  'HOSTINGER_TOKEN=sentinel-env-secret php collector.php',
  'api_key: sentinel-api-secret',
 ];
 $commandDenied=[];$outputDenied=[];$messagesSecretFree=[];
 foreach($variants as $value){
  $commandTransport=static fn()=>[
   'status'=>200,'headers'=>[],
   'body'=>json_encode([['uid'=>'cron_1','time'=>'*/5 * * * *','command'=>$value]],JSON_THROW_ON_ERROR),
  ];
  try{
   HostingerPublicApiAdapter::cronSnapshot('u123456789',$token,$commandTransport,$now);
   $commandDenied[]=false;$messagesSecretFree[]=false;
  }catch(Throwable $e){
   $commandDenied[]=true;$messagesSecretFree[]=!str_contains($e->getMessage(),'sentinel-');
  }
  $outputTransport=static fn()=>[
   'status'=>200,'headers'=>[],
   'body'=>json_encode(['output'=>$value],JSON_THROW_ON_ERROR),
  ];
  try{
   HostingerPublicApiAdapter::cronOutput('u123456789','cron_1',$token,$outputTransport,$now);
   $outputDenied[]=false;$messagesSecretFree[]=false;
  }catch(Throwable $e){
   $outputDenied[]=true;$messagesSecretFree[]=!str_contains($e->getMessage(),'sentinel-');
  }
 }
 echo json_encode(compact('commandDenied','outputDenied','messagesSecretFree'),JSON_THROW_ON_ERROR);exit;
}
if($scenario==='failures'){
 $out=[];
 foreach([
  ['status'=>401,'body'=>'{}'],['status'=>429,'body'=>'{}'],['status'=>500,'body'=>'{}'],
  ['status'=>200,'body'=>'{'],['status'=>200,'body'=>str_repeat('x',1_000_001)],
 ] as $fixture){
  $fake=static fn()=>['status'=>$fixture['status'],'headers'=>[],'body'=>$fixture['body']];
  try{HostingerPublicApi::request('GET',HostingerPublicApi::BASE_URL.'/api/hosting/v1/websites',$token,$fake);$out[]=false;}catch(Throwable){$out[]=true;}
 }
 $bad=scope();$bad['operation']='delete';$denied=false;
 try{HostingerPublicApiAdapter::planCronWrite(grant(),$bad,'u123456789',['time'=>'* * * * *','command'=>'php x.php'],$now);}catch(Throwable){$denied=true;}
 echo json_encode(['failed'=>$out,'denied'=>$denied],JSON_THROW_ON_ERROR);exit;
}
fwrite(STDERR,"scenario invalid\n");exit(2);
