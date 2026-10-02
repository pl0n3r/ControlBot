<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveOrchestratorSnapshot.php';
use ControlBot\Business\{FactoryLiveSnapshot,FactoryLiveOrchestratorSnapshot};
$scenario=$argv[1]??'';

function sig(string $id,string $auth,string $state='healthy',string $fresh='current',array $data=[],int $at=180):array{return ['id'=>$id,'authority'=>$auth,'state'=>$state,'source_ref'=>'controlbot:evidence/'.$id,'observed_at'=>$at,'freshness'=>$fresh,'data'=>$data];}
function proj(string $repo,string $state,int $a=0,int $r=0,int $b=0):array{return ['repository_ref'=>$repo,'state'=>$state,'counts'=>['completed'=>0,'available'=>$a,'reserved'=>$r,'blocked'=>$b,'unmaterialized'=>0,'decision_required'=>0,'live_only'=>0,'future_idea'=>0,'already_materialized'=>0],'next_work'=>$state==='READY'?$repo.'#next':null,'unmaterialized_identities'=>[],'parent_progress'=>[]];}
function inv(string $fresh='current'):array{return ['version'=>1,'source_ref'=>'github:pl0n3r/Factory@6c6f5f82','observed_at'=>195,'freshness'=>$fresh,'projects'=>[
 proj('pl0n3r/Factory','ALL_BLOCKED',0,0,2),proj('pl0n3r/Condor','NO_WORK'),proj('pl0n3r/GrindFlow','NO_WORK'),proj('pl0n3r/brvtal','NO_WORK'),
 proj('pl0n3r/ControlBot','READY',1,1,1),proj('pl0n3r/AutoFactory','NO_WORK'),proj('pl0n3r/FactoryRunner','NO_WORK')]];}
function raw(string $fresh='current'):array{return [
 'batches'=>[sig('batch:tanda-3','factory_plan')],
 'owner_decisions'=>[sig('decision:controlbot-700','owner_inbox','pending','current',['issue_ref'=>'github:pl0n3r/ControlBot#700'],150)],
 'releases'=>[sig('release:factory-v1','github_project_snapshot')],'blockers'=>[sig('blocker:factory-860','github_project_snapshot','blocked')],
 'production'=>[sig('production:controlbot','observability_project_status','pending')],'quality'=>[sig('quality:controlbot','quality_health')],
 'work'=>[
  sig('work:controlbot-620','github_project_snapshot','pending','current',['repository_ref'=>'pl0n3r/ControlBot','issue_ref'=>'github:pl0n3r/ControlBot#620','status'=>'reserved','progress_percent'=>40,'progress_evidence'=>'github:pl0n3r/ControlBot#620/lease'],190),
  sig('work:factory-856','github_project_snapshot','pending','current',['repository_ref'=>'pl0n3r/Factory','issue_ref'=>'github:pl0n3r/Factory#856','status'=>'in_review'],188)],
 'learning'=>[sig('learning:factory-860','incident_lesson')],'tool_usage'=>sig('tool-usage:github','tool_usage'),'work_inventory'=>inv($fresh)];}
function build(array $raw):array{return FactoryLiveOrchestratorSnapshot::build(FactoryLiveSnapshot::build($raw,200),220);}
function blocked(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}

if($scenario==='full'){echo json_encode(build(raw()),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;}
if($scenario==='fail_closed'){
 $stale=raw('stale');$stale['work'][0]['state']='degraded';$stale['work'][0]['freshness']='stale';
 $missing=raw();unset($missing['work']);
 $bad=raw();$bad['work'][0]['data']['repository_ref']='pl0n3r/UnknownRepo';
 echo json_encode(['stale'=>build($stale),'missing'=>build($missing),'mismatch_blocked'=>blocked(fn()=>build($bad))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='safety'){
 $many=raw();$many['work']=[];for($i=1;$i<=25;$i++)$many['work'][]=sig('work:controlbot-'.$i,'github_project_snapshot','pending','current',['repository_ref'=>'pl0n3r/ControlBot','issue_ref'=>'github:pl0n3r/ControlBot#'.(700+$i),'status'=>'reserved']);
 $secret=raw();$secret['work'][0]['data']['api_key']='nope';$a=build(raw());$b=build(raw());
 echo json_encode(['bounded'=>blocked(fn()=>build($many)),'secret_blocked'=>blocked(fn()=>build($secret)),'deterministic'=>$a===$b,'safe'=>$a],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"scenario invalid\n");exit(2);
