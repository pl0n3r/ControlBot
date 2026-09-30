<?php
declare(strict_types=1);
require __DIR__.'/../src/SchedulerCore.php'; require __DIR__.'/../src/SchedulerSelection.php'; require __DIR__.'/../src/AgentRuntime.php'; require __DIR__.'/../src/PresenceAdapter.php'; require __DIR__.'/../src/SchedulerAssignmentPlan.php'; require __DIR__.'/../src/SchedulerPolicyGuard.php';
use ControlBot\Runtime\PresenceAdapter; use ControlBot\Scheduler\SchedulerCore; use ControlBot\Scheduler\SchedulerPolicyGuard; use ControlBot\Scheduler\SchedulerSelection; use ControlBot\Scheduler\ValidatedSchedulerSelection; use InvalidArgumentException;
const NOW=10_000;
function rejected(callable $f): bool {try{$f();return false;}catch(InvalidArgumentException){return true;}}
function work(string $id,int $issue,string $type='engineering',string $priority='high',string $state='queued',int $generation=3,?string $reservationId=null,?string $sessionId=null): array {
 return ['version'=>1,'work_item_id'=>$id,'project_id'=>'controlbot','source_ref'=>'pl0n3r/ControlBot#'.$issue,'type'=>$type,'priority'=>$priority,'state'=>$state,'dependency_ids'=>[],'required_capabilities'=>['php','review'],'generation'=>$generation,'attempt'=>1,'reservation_id'=>$reservationId,'assigned_session_id'=>$sessionId];
}
function context(array $w,bool $ready=true): array {
 $r=[]; if($w['reservation_id']!==null)$r[]=['reservation_id'=>$w['reservation_id'],'owner_session_id'=>$w['assigned_session_id'],'generation'=>$w['generation'],'active'=>true];
 return ['dependency_states'=>[],'approval_state'=>'approved','freeze_state'=>$ready?'clear':'active','reservations'=>$r,'capacity'=>['account_id'=>'account_main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],'expected_generation'=>$w['generation'],'expected_owner_session_id'=>$w['assigned_session_id']];
}
function validated(array $rows,string $selectedId,array $blocked=[]): ValidatedSchedulerSelection {
 $c=[]; foreach($rows as $r)$c[]=SchedulerCore::candidate($r,context($r,!in_array($r['work_item_id'],$blocked,true)));
 $req=SchedulerSelection::request($c); $ready=[];$excluded=[];
 foreach($req['candidates'] as $x){if($x['readiness']['ready']){if($x['key']!==$selectedId)$ready[]=$x['key'];}else $excluded[$x['key']]=$x['readiness']['reasons'];}
 sort($ready,SORT_STRING);ksort($excluded,SORT_STRING);
 return ValidatedSchedulerSelection::fromDecision($req,['version'=>1,'policy_ref'=>'factory-dispatcher-v2','request_fingerprint'=>$req['fingerprint'],'selected_key'=>$selectedId,'selection_reason'=>'authoritative Factory dispatcher decision','telemetry'=>['ready_not_selected'=>$ready,'excluded'=>$excluded]],$c);
}
function trace(ValidatedSchedulerSelection $s,array $replace=[]): array {
 $v=$s->value(); return array_replace(['version'=>1,'policy_ref'=>'factory-dispatcher-v2','request_fingerprint'=>$v['request_fingerprint'],'selected_key'=>$v['selected_key'],'focus_version'=>null,'focus_position'=>null,'focus_influenced'=>false],$replace);
}
function account(): array {return ['version'=>1,'account_id'=>'account_main','provider_id'=>'chatgpt-web','account_alias'=>'Owner','plan'=>'plus','capacity'=>2,'status'=>'active'];}
function session(string $ref): array {
 $p=strrpos($ref,'#'); return ['version'=>1,'session_id'=>'session_run','agent_id'=>'agent_run','account_id'=>'account_main','profile_alias'=>'Main','tab_id'=>'tab-run','status'=>'working','assignment_id'=>'assignment-run','last_heartbeat_at'=>NOW-10,'mode'=>'web','repository'=>substr($ref,0,$p),'issue_number'=>(int)substr($ref,$p+1)];
}
function agent(): array {return ['version'=>1,'agent_id'=>'agent_run','role'=>'engineer','capabilities'=>['php','review']];}
function assignment(string $ref): array {return ['version'=>1,'assignment_id'=>'assignment-run','session_id'=>'session_run','project_id'=>'controlbot','source_ref'=>$ref,'status'=>'running'];}
function presence(array $running,bool $safe,bool $non,int $generationOverride=0): array {
 $g=$generationOverride>0?$generationOverride:$running['generation']; $row=['session'=>session($running['source_ref']),'agent'=>agent(),'assignment'=>assignment($running['source_ref']),'claims'=>['src/SchedulerPolicyGuard.php'],'generation'=>$g,'attempt'=>1,'safe_point'=>$safe,'non_preemptible'=>$non];
 return PresenceAdapter::snapshot([account()],[$row],NOW,90,300,['account_main'=>['version'=>1,'state'=>'healthy','total_capacity'=>2,'occupied_capacity'=>1,'observed_at'=>NOW]]);
}
$case=$argv[1]??'';
if($case==='preemption'){
 $selected=work('work-incident',395,'incident','high'); $running=work('work-running',394,'engineering','high','running',4,'reservation-run','session_run'); $s=validated([$selected,$running],'work-incident'); $t=trace($s);
 $blocked=SchedulerPolicyGuard::preemptionIntent($s,[$selected,$running],$t,presence($running,false,true),'work-running','session_run',4);
 $safe=SchedulerPolicyGuard::preemptionIntent($s,[$selected,$running],$t,presence($running,true,true),'work-running','session_run',4);
 $stale=SchedulerPolicyGuard::preemptionIntent($s,[$selected,$running],$t,presence($running,true,true,5),'work-running','session_run',4);
 echo json_encode(compact('blocked','safe','stale'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='focus'){
 $high=work('work-high',395); $critical=work('work-critical',58,'engineering','critical'); $badSel=validated([$high,$critical],'work-high');
 $bad=rejected(fn()=>SchedulerPolicyGuard::focusGuard($badSel,[$high,$critical],trace($badSel,['focus_version'=>3,'focus_position'=>1,'focus_influenced'=>true])));
 $goodSel=validated([$high,$critical],'work-critical'); $good=SchedulerPolicyGuard::focusGuard($goodSel,[$high,$critical],trace($goodSel,['focus_version'=>3,'focus_position'=>2,'focus_influenced'=>true]));
 $incident=work('work-incident',56,'incident','medium'); $incSel=validated([$high,$incident],'work-high'); $inc=rejected(fn()=>SchedulerPolicyGuard::focusGuard($incSel,[$high,$incident],trace($incSel,['focus_version'=>3,'focus_position'=>1,'focus_influenced'=>true])));
 echo json_encode(['critical_rejected'=>$bad,'incident_rejected'=>$inc,'protected_selected'=>$good],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='blocked_focus'){
 $high=work('work-high',395); $critical=work('work-critical',58,'engineering','critical'); $s=validated([$high,$critical],'work-high',['work-critical']);
 echo json_encode(SchedulerPolicyGuard::focusGuard($s,[$high,$critical],trace($s,['focus_version'=>4,'focus_position'=>1,'focus_influenced'=>true])),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='drift'){
 $selected=work('work-incident',395,'incident','high'); $other=work('work-other',58); $s=validated([$selected,$other],'work-incident'); $base=trace($s);
 $a=$base;$a['request_fingerprint']=str_repeat('a',64); $b=$base;$b['selected_key']='work-other'; $c=$base;$c['focus_influenced']=true; $changed=$selected;$changed['generation']=4;
 echo json_encode(['fingerprint'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($s,[$selected,$other],$a)),'selected'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($s,[$selected,$other],$b)),'focus'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($s,[$selected,$other],$c)),'work'=>rejected(fn()=>SchedulerPolicyGuard::focusGuard($s,[$changed,$other],$base))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='deterministic'){
 $a=work('work-a',395);$b=work('work-b',58);$s=validated([$a,$b],'work-a');$t=trace($s,['focus_version'=>2,'focus_position'=>1,'focus_influenced'=>true]);$one=SchedulerPolicyGuard::focusGuard($s,[$a,$b],$t);$two=SchedulerPolicyGuard::focusGuard($s,[$b,$a],$t);
 $source=strtolower(file_get_contents(__DIR__.'/../src/SchedulerPolicyGuard.php'));$forbidden=['select_next','score','new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec('];$hits=[];foreach($forbidden as $n)if(str_contains($source,$n))$hits[]=$n;
 echo json_encode(['same'=>$one===$two,'fingerprint_same'=>$one['fingerprint']===$two['fingerprint'],'hits'=>$hits],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown scheduler policy guard scenario\n");exit(2);
