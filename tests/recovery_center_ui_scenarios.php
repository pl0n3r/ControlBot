<?php
declare(strict_types=1);
foreach(['InfrastructureProvider','RecoveryProfile','BackupReceipt','RecoveryEvidence','RecoveryDrillProjection','RecoveryCenterUi'] as $n) require __DIR__.'/../src/'.$n.'.php';

use ControlBot\Production\BackupReceipt;
use ControlBot\Security\RecoveryCenterUi;

function profile():array{return[
'version'=>1,'project_ref'=>'controlbot:project/project-controlbot','manifest_ref'=>'controlbot:recovery-manifest/project-controlbot-v1',
'targets'=>['rpo_minutes'=>15,'rto_minutes'=>60],'retention'=>['hourly'=>24,'daily'=>7,'weekly'=>8,'monthly'=>12],
'sources'=>['database'=>'required','media'=>'not_applicable','repository'=>'required','configuration'=>'required'],
'strategy'=>['copies_required'=>3,'media_types_required'=>2,'offsite_required'=>true,'immutable_required'=>true,'undetected_restore_failures_target'=>0],
'encryption_required'=>true,'restore_drill_cadence_hours'=>168,'source_ref'=>'https://github.com/pl0n3r/factory/issues/305','observed_at'=>2000,'freshness'=>'fresh'];}
function receipt():BackupReceipt{return BackupReceipt::fromRecord([
'version'=>1,'receipt_id'=>'11111111-1111-4111-8111-111111111111','project'=>'controlbot','environment'=>'production','resource'=>'database-main',
'backup_type'=>'database_dump','run_id'=>'22222222-2222-4222-8222-222222222222','issue'=>'pl0n3r/ControlBot#282',
'created_at'=>'2026-09-29T15:00:00Z','verified_at'=>'2026-09-29T15:01:00Z','status'=>'ready','artifact_ref'=>'artifact:recovery-ui-282',
'integrity'=>['algorithm'=>'sha256','digest'=>'abcdef0123456789'],'restore_capability'=>'validated','expires_at'=>'2026-09-30T15:01:00Z']);}
function st(string $s='verified',?string $r='controlbot:evidence/recovery',?int $at=2100,string $f='fresh'):array{return['state'=>$s,'evidence_ref'=>$r,'observed_at'=>$at,'freshness'=>$f];}
function evidence():array{return[
'version'=>1,'project_ref'=>'controlbot:project/project-controlbot','profile_ref'=>'controlbot:recovery-profile/project-controlbot-v1',
'controls'=>['checksum'=>st(r:'controlbot:evidence/checksum'),'encryption'=>st(r:'controlbot:evidence/encryption'),'offsite'=>st(r:'controlbot:evidence/offsite'),'immutability'=>st(r:'controlbot:evidence/immutability')],
'sources'=>[['source'=>'database',...st(r:'controlbot:evidence/database')],['source'=>'media','state'=>'not_applicable','evidence_ref'=>null,'observed_at'=>null,'freshness'=>'unknown'],['source'=>'repository',...st(r:'controlbot:evidence/repository')],['source'=>'configuration',...st(r:'controlbot:evidence/configuration')]]];}
function drill():array{return[
'version'=>1,'project_ref'=>'controlbot:project/project-controlbot','recovery_evidence_ref'=>'controlbot:recovery-evidence/project-controlbot-v1',
'backup_receipt_id'=>'11111111-1111-4111-8111-111111111111','target'=>['kind'=>'disposable','target_ref'=>'controlbot:recovery-target/sandbox-001'],
'reported_status'=>'BREACHED','observed'=>['rpo_seconds'=>600,'rto_seconds'=>4000,'rpo_target_seconds'=>900,'rto_target_seconds'=>3600],
'checks'=>['health'=>true,'smoke'=>true,'integrity'=>true],'reasons'=>['RTO_EXCEEDED'],'evidence_refs'=>['controlbot:evidence/drill-001'],
'observed_at'=>2200,'freshness'=>'fresh','authority'=>'unchanged','execute'=>false];}
function health(array $o=[]):array{return array_replace([
'version'=>1,'project'=>'controlbot','state'=>'DEGRADED','observed_at'=>'2026-09-29T15:10:00Z','reasons'=>['RTO_BREACHED'],'work_item_classes'=>['rto_breached'],
'freshness'=>['backup_age_seconds'=>300,'backup_max_age_seconds'=>900,'drill_age_seconds'=>60,'drill_max_age_seconds'=>604800],
'backup_ref'=>'backup:001','drill_status'=>'BREACHED','drill_observed'=>['rpo_seconds'=>600,'rto_seconds'=>4000,'rpo_target_seconds'=>900,'rto_target_seconds'=>3600],
'authority'=>'unchanged','execute'=>false],$o);}
function project(?array $e=null,?array $d=null,?array $h=null):array{return RecoveryCenterUi::project(profile(),$e,$d,$h,($e!==null||$d!==null)?receipt():null);}
function blocked(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException){return true;}}

$c=$argv[1]??'';
$out=match($c){
'valid'=>project(evidence(),drill(),health()),
'missing-health'=>project(evidence(),drill(),null),
'blocked-health'=>project(evidence(),drill(),health(['state'=>'BLOCKED','reasons'=>['DRILL_EVIDENCE_INVALID'],'work_item_classes'=>['restore_drill_failed']])),
'stale'=>(function(){ $e=evidence();$e['controls']['offsite']['freshness']='stale';$d=drill();$d['freshness']='stale';return project($e,$d,health());})(),
'cross-health'=>blocked(fn()=>project(evidence(),drill(),health(['project'=>'other']))),
'cross-evidence'=>blocked(function(){ $e=evidence();$e['project_ref']='controlbot:project/project-other';project($e,drill(),health());}),
'unsafe-authority'=>blocked(fn()=>project(evidence(),drill(),health(['authority'=>'production-write']))),
'execute-true'=>blocked(fn()=>project(evidence(),drill(),health(['execute'=>true]))),
'sensitive-health'=>blocked(fn()=>project(evidence(),drill(),health(['reasons'=>['Bearer abcdefghijklmnopqrstuvwxyz']]))),
'caller-action'=>blocked(fn()=>RecoveryCenterUi::project(profile(),evidence(),drill(),health(),receipt(),[['action'=>'restore']])),
default=>throw new InvalidArgumentException('scenario invalid')};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
