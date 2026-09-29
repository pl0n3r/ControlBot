<?php
declare(strict_types=1);
require __DIR__.'/../src/InfrastructureProvider.php'; require __DIR__.'/../src/RecoveryProfile.php'; require __DIR__.'/../src/BackupReceipt.php'; require __DIR__.'/../src/RecoveryEvidence.php';
use ControlBot\Infrastructure\RecoveryEvidence; use ControlBot\Production\BackupReceipt;
function recoveryProfile(array $o=[]):array{return array_replace_recursive([
'version'=>1,'project_ref'=>'controlbot:project/project-controlbot','manifest_ref'=>'controlbot:recovery-manifest/project-controlbot-v1',
'targets'=>['rpo_minutes'=>15,'rto_minutes'=>60],'retention'=>['hourly'=>24,'daily'=>7,'weekly'=>8,'monthly'=>12],
'sources'=>['database'=>'required','media'=>'not_applicable','repository'=>'required','configuration'=>'required'],
'strategy'=>['copies_required'=>3,'media_types_required'=>2,'offsite_required'=>true,'immutable_required'=>true,'undetected_restore_failures_target'=>0],
'encryption_required'=>true,'restore_drill_cadence_hours'=>168,'source_ref'=>'https://github.com/pl0n3r/factory/issues/305','observed_at'=>2000,'freshness'=>'fresh'],$o);}
function producerReceipt(string $p='controlbot'):BackupReceipt{return BackupReceipt::fromRecord([
'version'=>1,'receipt_id'=>'11111111-1111-4111-8111-111111111111','project'=>$p,'environment'=>'production','resource'=>'database-main','backup_type'=>'database_dump',
'run_id'=>'22222222-2222-4222-8222-222222222222','issue'=>'pl0n3r/ControlBot#257','created_at'=>'2026-09-29T13:00:00Z','verified_at'=>'2026-09-29T13:01:00Z',
'status'=>'ready','artifact_ref'=>'artifact:recovery-evidence-257','integrity'=>['algorithm'=>'sha256','digest'=>'abcdef0123456789'],'restore_capability'=>'validated','expires_at'=>'2026-09-30T13:01:00Z']);}
function st(string $s='verified',?string $r='controlbot:evidence/recovery-check',?int $at=2100,string $f='fresh'):array{return ['state'=>$s,'evidence_ref'=>$r,'observed_at'=>$at,'freshness'=>$f];}
function ev():array{return ['version'=>1,'project_ref'=>'controlbot:project/project-controlbot','profile_ref'=>'controlbot:recovery-profile/project-controlbot-v1',
'controls'=>['checksum'=>st(r:'controlbot:evidence/checksum'),'encryption'=>st(r:'controlbot:evidence/encryption'),'offsite'=>st(r:'controlbot:evidence/offsite'),'immutability'=>st(r:'controlbot:evidence/immutability')],
'sources'=>[['source'=>'database',...st(r:'controlbot:evidence/database')],['source'=>'media','state'=>'not_applicable','evidence_ref'=>null,'observed_at'=>null,'freshness'=>'unknown'],['source'=>'repository',...st(r:'controlbot:evidence/repository')],['source'=>'configuration',...st(r:'controlbot:evidence/configuration')]]];}
function blocked(callable $f):array{try{$f();return ['blocked'=>false,'message'=>null];}catch(InvalidArgumentException $e){return ['blocked'=>true,'message'=>$e->getMessage()];}}
function norm(array $x):array{return RecoveryEvidence::normalize($x,recoveryProfile(),producerReceipt());}
$c=$argv[1]??''; $out=match($c){
'valid'=>norm(ev()),
'cross-project'=>blocked(function(){ $x=ev();$x['project_ref']='controlbot:project/project-other';norm($x);}),
'producer-project-mismatch'=>blocked(fn()=>RecoveryEvidence::normalize(ev(),recoveryProfile(),producerReceipt('other'))),
'cross-project-profile-ref'=>blocked(function(){ $x=ev();$x['profile_ref']='controlbot:recovery-profile/project-other-v1';norm($x);}),
'stale-checksum'=>(function(){ $x=ev();$x['controls']['checksum']['freshness']='stale';return norm($x);})(),
'unknown-checksum'=>(function(){ $x=ev();$x['controls']['checksum']=st('unknown',null,null,'unknown');return norm($x);})(),
'stale-source'=>(function(){ $x=ev();$x['sources'][2]['freshness']='stale';return norm($x);})(),
'duplicate-source'=>blocked(function(){ $x=ev();$x['sources'][3]['source']='database';norm($x);}),
'required-not-applicable'=>blocked(function(){ $x=ev();$x['sources'][0]=['source'=>'database','state'=>'not_applicable','evidence_ref'=>null,'observed_at'=>null,'freshness'=>'unknown'];norm($x);}),
'top-level-digest'=>blocked(function(){ $x=ev();$x['digest']='sha256:0123456789abcdef';norm($x);}),
'payload-field'=>blocked(function(){ $x=ev();$x['controls']['checksum']['payload']='opaque-backup-payload';norm($x);}),
'provider-url'=>blocked(function(){ $x=ev();$x['controls']['offsite']['evidence_ref']='https://bucket.example.invalid/object';norm($x);}),
'secret-ref'=>blocked(function(){ $x=ev();$x['controls']['encryption']['evidence_ref']='controlbot:evidence/token-secret-value';norm($x);}),
'unknown-with-provenance'=>blocked(function(){ $x=ev();$x['controls']['checksum']['state']='unknown';$x['controls']['checksum']['freshness']='unknown';norm($x);}),
default=>throw new InvalidArgumentException('scenario invalid')};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
