<?php
declare(strict_types=1);
require __DIR__.'/../src/InfrastructureProvider.php';
require __DIR__.'/../src/RecoveryWorkOrigin.php';
use ControlBot\Infrastructure\RecoveryWorkOrigin;

function health(array $o=[]): array {
    return array_replace([
        'version'=>1,'project'=>'controlbot','state'=>'DEGRADED',
        'observed_at'=>'2026-09-29T15:00:00-05:00',
        'reasons'=>['RTO_BREACHED'],'work_item_classes'=>['rto_breached'],
        'freshness'=>['backup_age_seconds'=>300,'backup_max_age_seconds'=>900,'drill_age_seconds'=>60,'drill_max_age_seconds'=>604800],
        'backup_ref'=>'backup:001','drill_status'=>'BREACHED',
        'drill_observed'=>['rpo_seconds'=>600,'rto_seconds'=>4000,'rpo_target_seconds'=>900,'rto_target_seconds'=>3600],
        'authority'=>'unchanged','execute'=>false,
    ],$o);
}
function intent(array $o=[]): array {
    return array_replace([
        'version'=>1,'work_id'=>'work-recovery-001','group_id'=>'pl0n3r-group',
        'work_type'=>'infrastructure','requested_capabilities'=>['recovery.repair','backup.read'],
        'required_roles'=>['sre','dba'],'authority_level'=>'operational','priority_class'=>'high',
        'depends_on'=>[],'claims'=>['project:controlbot:recovery'],
        'policy_ref'=>'factory:recovery-v1','health_ref'=>'controlbot:recovery-health/controlbot-001',
    ],$o);
}
function blocked(callable $fn): array {
    try{$fn();return ['blocked'=>false,'message'=>null];}
    catch(InvalidArgumentException $e){return ['blocked'=>true,'message'=>$e->getMessage()];}
}
$case=$argv[1]??'';
$out=match($case){
    'valid'=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health(),'rto_breached'),
    'explicit'=>RecoveryWorkOrigin::fromFactoryHealth(intent([
        'work_type'=>'operations','requested_capabilities'=>['incident.repair'],
        'required_roles'=>['sre'],'authority_level'=>'owner_reviewed','priority_class'=>'critical',
        'policy_ref'=>'factory:recovery-critical',
    ]),health(),'rto_breached'),
    'unknown'=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'state'=>'UNKNOWN','reasons'=>['BACKUP_MISSING'],'work_item_classes'=>['backup_missing'],
        'backup_ref'=>null,'drill_status'=>null,'drill_observed'=>null,'freshness'=>[],
    ]),'backup_missing'),
    'healthy'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'state'=>'HEALTHY','reasons'=>['RECOVERY_EVIDENCE_CURRENT'],'work_item_classes'=>[],
    ]),'rto_breached')),
    'class-absent'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health(),'backup_missing')),
    'unknown-class'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'work_item_classes'=>['other'],
    ]),'other')),
    'expanded-health'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'authority'=>'production-write',
    ]),'rto_breached')),
    'incoherent-drill'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'drill_status'=>null,
    ]),'rto_breached')),
    'sensitive-health'=>blocked(fn()=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health([
        'reasons'=>['Bearer abcdefghijklmnopqrstuvwxyz'],
    ]),'rto_breached')),
    'optionals'=>RecoveryWorkOrigin::fromFactoryHealth(intent([
        'venture_id'=>'venture-main','repository_ref'=>'pl0n3r/ControlBot',
        'budget_ref'=>'capital:recovery','approval_ref'=>'owner:recovery','severity'=>'high',
    ]),health(),'rto_breached'),
    'order-a'=>RecoveryWorkOrigin::fromFactoryHealth(intent(),health(),'rto_breached'),
    'order-b'=>RecoveryWorkOrigin::fromFactoryHealth(intent([
        'requested_capabilities'=>['backup.read','recovery.repair'],
        'required_roles'=>['dba','sre'],'claims'=>['project:controlbot:recovery'],
    ]),health(),'rto_breached'),
    default=>throw new InvalidArgumentException('scenario invalid'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
