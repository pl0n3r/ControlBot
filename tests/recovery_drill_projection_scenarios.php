<?php
declare(strict_types=1);

require __DIR__.'/../src/InfrastructureProvider.php';
require __DIR__.'/../src/RecoveryProfile.php';
require __DIR__.'/../src/BackupReceipt.php';
require __DIR__.'/../src/RecoveryEvidence.php';
require __DIR__.'/../src/RecoveryDrillProjection.php';

use ControlBot\Infrastructure\RecoveryDrillProjection;
use ControlBot\Production\BackupReceipt;

function profile(): array {
    return [
        'version'=>1,'project_ref'=>'controlbot:project/project-controlbot',
        'manifest_ref'=>'controlbot:recovery-manifest/project-controlbot-v1',
        'targets'=>['rpo_minutes'=>15,'rto_minutes'=>60],
        'retention'=>['hourly'=>24,'daily'=>7,'weekly'=>8,'monthly'=>12],
        'sources'=>['database'=>'required','media'=>'not_applicable','repository'=>'required','configuration'=>'required'],
        'strategy'=>['copies_required'=>3,'media_types_required'=>2,'offsite_required'=>true,'immutable_required'=>true,'undetected_restore_failures_target'=>0],
        'encryption_required'=>true,'restore_drill_cadence_hours'=>168,
        'source_ref'=>'https://github.com/pl0n3r/factory/issues/305','observed_at'=>2000,'freshness'=>'fresh',
    ];
}
function receipt(string $project='controlbot'): BackupReceipt {
    return BackupReceipt::fromRecord([
        'version'=>1,'receipt_id'=>'11111111-1111-4111-8111-111111111111',
        'project'=>$project,'environment'=>'production','resource'=>'database-main',
        'backup_type'=>'database_dump','run_id'=>'22222222-2222-4222-8222-222222222222',
        'issue'=>'pl0n3r/ControlBot#271','created_at'=>'2026-09-29T13:00:00Z',
        'verified_at'=>'2026-09-29T13:01:00Z','status'=>'ready',
        'artifact_ref'=>'artifact:restore-drill-271',
        'integrity'=>['algorithm'=>'sha256','digest'=>'abcdef0123456789'],
        'restore_capability'=>'validated','expires_at'=>'2026-09-30T13:01:00Z',
    ]);
}
function evState(string $state='verified', ?string $ref='controlbot:evidence/recovery', ?int $at=2100, string $fresh='fresh'): array {
    return ['state'=>$state,'evidence_ref'=>$ref,'observed_at'=>$at,'freshness'=>$fresh];
}
function recoveryEvidence(): array {
    return [
        'version'=>1,'project_ref'=>'controlbot:project/project-controlbot',
        'profile_ref'=>'controlbot:recovery-profile/project-controlbot-v1',
        'controls'=>[
            'checksum'=>evState(ref:'controlbot:evidence/checksum'),
            'encryption'=>evState(ref:'controlbot:evidence/encryption'),
            'offsite'=>evState(ref:'controlbot:evidence/offsite'),
            'immutability'=>evState(ref:'controlbot:evidence/immutability'),
        ],
        'sources'=>[
            ['source'=>'database',...evState(ref:'controlbot:evidence/database')],
            ['source'=>'media','state'=>'not_applicable','evidence_ref'=>null,'observed_at'=>null,'freshness'=>'unknown'],
            ['source'=>'repository',...evState(ref:'controlbot:evidence/repository')],
            ['source'=>'configuration',...evState(ref:'controlbot:evidence/configuration')],
        ],
    ];
}
function drill(): array {
    return [
        'version'=>1,'project_ref'=>'controlbot:project/project-controlbot',
        'recovery_evidence_ref'=>'controlbot:recovery-evidence/project-controlbot-v1',
        'backup_receipt_id'=>'11111111-1111-4111-8111-111111111111',
        'target'=>['kind'=>'disposable','target_ref'=>'controlbot:recovery-target/sandbox-001'],
        'reported_status'=>'PASSED',
        'observed'=>['rpo_seconds'=>600,'rto_seconds'=>1680,'rpo_target_seconds'=>900,'rto_target_seconds'=>3600],
        'checks'=>['health'=>true,'smoke'=>true,'integrity'=>true],
        'reasons'=>[],'evidence_refs'=>['controlbot:evidence/drill-001'],
        'observed_at'=>2200,'freshness'=>'fresh','authority'=>'unchanged','execute'=>false,
    ];
}
function normalizeDrill(array $raw, ?BackupReceipt $r=null): array {
    return RecoveryDrillProjection::normalize($raw,profile(),recoveryEvidence(),$r??receipt());
}
function rejected(callable $fn): array {
    try{$fn();return ['blocked'=>false,'message'=>null];}
    catch(InvalidArgumentException $e){return ['blocked'=>true,'message'=>$e->getMessage()];}
}

$case=$argv[1]??'';
$out=match($case){
    'valid'=>normalizeDrill(drill()),
    'cross-project'=>rejected(function(){ $x=drill();$x['project_ref']='controlbot:project/project-other';normalizeDrill($x); }),
    'producer-mismatch'=>rejected(fn()=>normalizeDrill(drill(),receipt('other'))),
    'stale'=>(function(){ $x=drill();$x['freshness']='stale';return normalizeDrill($x); })(),
    'unknown'=>(function(){ $x=drill();$x['freshness']='unknown';return normalizeDrill($x); })(),
    'breached'=>(function(){ $x=drill();$x['reported_status']='BREACHED';$x['reasons']=['RTO_EXCEEDED'];$x['observed']['rto_seconds']=4000;return normalizeDrill($x); })(),
    'production-target'=>rejected(function(){ $x=drill();$x['target']['kind']='production';normalizeDrill($x); }),
    'failed-check'=>rejected(function(){ $x=drill();$x['checks']['smoke']=false;normalizeDrill($x); }),
    'authority-expanded'=>rejected(function(){ $x=drill();$x['authority']='production-write';normalizeDrill($x); }),
    'execute-true'=>rejected(function(){ $x=drill();$x['execute']=true;normalizeDrill($x); }),
    'sensitive-ref'=>rejected(function(){ $x=drill();$x['evidence_refs']=['controlbot:evidence/token-secret-value'];normalizeDrill($x); }),
    'extra-field'=>rejected(function(){ $x=drill();$x['payload']='backup-material';normalizeDrill($x); }),
    'target-mismatch'=>rejected(function(){ $x=drill();$x['observed']['rto_target_seconds']=999;normalizeDrill($x); }),
    default=>throw new InvalidArgumentException('scenario invalid'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
