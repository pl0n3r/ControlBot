<?php
declare(strict_types=1);
require __DIR__.'/../src/ObservabilityEvent.php';
require __DIR__.'/../src/IncidentTimeline.php';
require __DIR__.'/../src/ObservabilityIncident.php';
require __DIR__.'/../src/OwnerInbox.php';
require __DIR__.'/../src/ExternalApiContract.php';
require __DIR__.'/../src/ExternalApiPushNotification.php';
require __DIR__.'/../src/ProductionOperation.php';
require __DIR__.'/../src/PauseControl.php';
require __DIR__.'/../src/PauseProductionGate.php';
require __DIR__.'/../src/ObservabilityPolicyRouter.php';

use ControlBot\Observability\ObservabilityIncident;
use ControlBot\Observability\ObservabilityPolicyRouter;
use ControlBot\Runtime\PauseProductionGate;

function bad(callable $fn): bool {try{$fn();return false;}catch(Throwable){return true;}}
function ev(string $severity,string $status,string $key='78',int $at=100): array {
 return ['version'=>1,'source'=>'health','project_id'=>'controlbot','environment_id'=>'production',
  'repository_id'=>'pl0n3r/ControlBot','severity'=>$severity,'type'=>'health_probe','payload'=>['status'=>$status],
  'occurred_at'=>$at,'received_at'=>$at+1,'correlation_keys'=>['project:controlbot','issue:'.$key]];
}
function inc(string $severity='critical',bool $resolved=false,string $key='78'): array {
 $rows=[ev($severity,$severity==='warning'?'degraded':'down',$key)];
 if($resolved)$rows[]=ev('info','healthy',$key,120);
 return ObservabilityIncident::correlate($rows,130,['health'=>300,'ci'=>300,'deploy'=>300,'agent'=>300])['incidents'][0];
}
function policy(array $o=[]): array {return array_replace(['version'=>1,'policy_ref'=>'policy:observability-v1',
 'freeze_on_critical'=>true,'project_scope_id'=>'controlbot','owner_scope_kind'=>'project',
 'owner_scope_ref'=>'controlbot:project/controlbot','push_ttl_seconds'=>900,
 'inbox_class_by_severity'=>['info'=>'fyi','warning'=>'watch','error'=>'watch','critical'=>'critical'],
 'channels_by_severity'=>['info'=>['inbox'],'warning'=>['inbox'],'error'=>['inbox','push'],'critical'=>['inbox','push']]],$o);}
function evidence(string $fresh='fresh'): array {return $fresh==='unknown'
 ? ['version'=>1,'freshness'=>'unknown','source_ref'=>null,'evidence_ref'=>null,'observed_at'=>null]
 : ['version'=>1,'freshness'=>$fresh,'source_ref'=>'controlbot:source/observability','evidence_ref'=>'controlbot:evidence/incident-78','observed_at'=>130];}
function project(array $i,array $p=null,array $e=null): array {return ObservabilityPolicyRouter::project($i,$p??policy(),$e??evidence());}

$case=$argv[1]??'';
if($case==='classes'){
 $out=[];foreach(['info','warning','error','critical'] as $s)$out[$s]=project(inc($s));
 $out['resolved']=project(inc('error',true));
}elseif($case==='push'){
 $out=[];foreach(['info','warning','error','critical'] as $s)$out[$s]=project(inc($s))['push_notification'];
 $out['retry_same']=project(inc('critical'))['push_notification']===project(inc('critical'))['push_notification'];
}elseif($case==='freeze'){
 $r=project(inc('critical'));$pause=$r['freeze_intent'];
 $ctx=['version'=>1,'session_id'=>'session-1','account_id'=>'account-1','project_id'=>'controlbot'];
 $out=['freeze'=>$pause,'write'=>PauseProductionGate::evaluate($ctx,[$pause],'database.backup'),
  'read'=>PauseProductionGate::evaluate($ctx,[$pause],'health.check')];
}elseif($case==='blocked'){
 $resolved=inc('critical',true);$bad=evidence();$bad['evidence_ref']=null;
 $out=['stale'=>project(inc('critical'),policy(),evidence('stale'))['freeze_intent'],
  'unknown'=>project(inc('critical'),policy(),evidence('unknown'))['freeze_intent'],
  'disabled'=>project(inc('critical'),policy(['freeze_on_critical'=>false]))['freeze_intent'],
  'resolved'=>project($resolved)['freeze_intent'],'bad_provenance'=>bad(fn()=>project(inc('critical'),policy(),$bad)),
  'scope_mismatch'=>bad(fn()=>project(inc('critical'),policy(['project_scope_id'=>'other'])))];
}elseif($case==='deterministic'){
 $a=project(inc('critical'));$b=project(inc('critical'));$c=project(inc('critical',false,'79'));
 $out=['same'=>$a===$b,'fingerprint_same'=>$a['fingerprint']===$b['fingerprint'],
  'material_change'=>$a['fingerprint']!==$c['fingerprint'],'dedupe_same'=>$a['push_notification']['dedupe_key']===$b['push_notification']['dedupe_key']];
}elseif($case==='invalid'){
 $extra=inc('critical');$extra['manual_state']='green';
 $secret=policy(['owner_scope_ref'=>'controlbot:project/token:secret']);
 $occ=inc('critical');$occ['occurrence_count']=0;
 $empty=inc('critical');$empty['timeline_events']=[];
 $reason=inc('critical');$reason['correlation_reason']='manual';
 $missing=inc('critical');$missing['event_fingerprints']=[];
 $dupe=inc('critical');$dupe['event_fingerprints'][]=$dupe['event_fingerprints'][0];
 $time=inc('critical');$time['first_seen_at']=$time['last_event_at']+1;
 $classes=policy();$classes['inbox_class_by_severity']['critical']='watch';
 $channels=policy();$channels['channels_by_severity']['warning']=['inbox','push'];
 $out=['extra'=>bad(fn()=>project($extra)),'secret'=>bad(fn()=>project(inc('critical'),$secret)),
  'occurrence'=>bad(fn()=>project($occ)),'empty_timeline'=>bad(fn()=>project($empty)),
  'reason'=>bad(fn()=>project($reason)),'missing_primary'=>bad(fn()=>project($missing)),
  'duplicate_fingerprint'=>bad(fn()=>project($dupe)),'chronology'=>bad(fn()=>project($time)),
  'class_drift'=>bad(fn()=>project(inc('critical'),$classes)),'channel_drift'=>bad(fn()=>project(inc('critical'),$channels)),
  'unknown_provenance'=>bad(fn()=>project(inc('critical'),policy(),['version'=>1,'freshness'=>'unknown',
   'source_ref'=>'controlbot:source/observability','evidence_ref'=>null,'observed_at'=>null]))];
}elseif($case==='pure'){
 $src=strtolower((string)file_get_contents(__DIR__.'/../src/ObservabilityPolicyRouter.php'));
 $hits=[];foreach(['new pdo','mysqli','curl_','file_put_contents','fopen(','shell_exec','proc_open','passthru(','system(','exec(','mail(','enqueue(','http://','https://'] as $n)if(str_contains($src,$n))$hits[]=$n;
 $out=['hits'=>$hits];
}else{fwrite(STDERR,"Unknown policy router scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
