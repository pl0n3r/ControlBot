<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryLiveSnapshot.php';
use ControlBot\Business\FactoryLiveSnapshot;
$scenario=$argv[1]??'';

function sig(string $id,string $authority,string $state='healthy',string $fresh='current',array $data=[],int $at=180): array {
 return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'controlbot:evidence/'.$id,'observed_at'=>$at,'freshness'=>$fresh,'data'=>$data];
}
function fixture(): array {
 return [
  'batches'=>[sig('batch:tanda-1','factory_plan','healthy','current',['source_kind'=>'factory','tanda'=>1])],
  'owner_decisions'=>[sig('decision:controlbot-100','owner_inbox','pending','current',['issue_ref'=>'https://github.com/pl0n3r/ControlBot/issues/100','source_kind'=>'github'])],
  'releases'=>[sig('release:factory-v1','github_project_snapshot','healthy','current',['source_kind'=>'github','tag'=>'v1'])],
  'blockers'=>[sig('blocker:controlbot-65','github_project_snapshot','blocked','current',['issue'=>65,'source_kind'=>'github'])],
  'production'=>[sig('production:condor','observability_project_status','healthy','current',['project'=>'condor','source_kind'=>'observability'])],
  'quality'=>[sig('quality:condor','quality_health','healthy','current',['quality_gate'=>'PASS','source_kind'=>'sonar'])],
  'work'=>[
   sig('work:controlbot-562','github_project_snapshot','pending','current',['issue'=>562,'source_kind'=>'github'],186),
   sig('work:grindflow-201','github_project_snapshot','healthy','current',['issue'=>201,'source_kind'=>'github'],187),
  ],
  'learning'=>[sig('learning:incident-739','incident_lesson','healthy','current',['preventive_rules'=>2,'source_kind'=>'learning'],188)],
  'tool_usage'=>sig('tool-usage:remote-desktop','tool_usage','degraded','current',['limit'=>10000,'source_kind'=>'tool_usage','used'=>4200],189),
 ];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

if($scenario==='full'){echo json_encode(FactoryLiveSnapshot::build(fixture(),200),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;}
if($scenario==='fail_closed'){
 $missing=fixture();unset($missing['quality']);
 $stale=fixture();$stale['production'][0]['freshness']='stale';
 $dup=fixture();$dup['work'][]=$dup['work'][0];
 $auth=fixture();$auth['quality'][0]['authority']='github_project_snapshot';
 echo json_encode([
  'missing'=>FactoryLiveSnapshot::build($missing,200)['sections']['quality'][0],
  'blocked'=>[
   'stale'=>blocked(fn()=>FactoryLiveSnapshot::build($stale,200)),
   'duplicate'=>blocked(fn()=>FactoryLiveSnapshot::build($dup,200)),
   'authority'=>blocked(fn()=>FactoryLiveSnapshot::build($auth,200)),
  ],
 ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='safety'){
 $secret=fixture();$secret['quality'][0]['data']['api_key']='nope';
 $many=fixture();$many['work']=[];for($i=1;$i<=51;$i++)$many['work'][]=sig('work:item-'.$i,'github_project_snapshot','pending','current',['issue'=>$i]);
 $a=fixture();$b=fixture();$b['work']=array_reverse($b['work']);
 echo json_encode([
  'secret'=>blocked(fn()=>FactoryLiveSnapshot::build($secret,200)),
  'bounded'=>blocked(fn()=>FactoryLiveSnapshot::build($many,200)),
  'deterministic'=>FactoryLiveSnapshot::build($a,200)===FactoryLiveSnapshot::build($b,200),
 ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='simulated'){
 $s=FactoryLiveSnapshot::build(fixture(),200);$auth=[];$kinds=[];
 foreach($s['sections'] as $name=>$signals){$auth[$name]=$signals[0]['authority'];foreach($signals as $row)if(isset($row['data']['source_kind']))$kinds[$row['data']['source_kind']]=true;}
 $kinds[$s['tool_usage']['data']['source_kind']]=true;ksort($auth);ksort($kinds);
 echo json_encode(['authorities'=>$auth,'source_kinds'=>array_keys($kinds)],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"scenario inválido\n");exit(2);
