<?php
declare(strict_types=1);

namespace ControlBot\Security;

use ControlBot\Infrastructure\InfrastructureProvider;
use ControlBot\Infrastructure\RecoveryDrillProjection;
use ControlBot\Infrastructure\RecoveryEvidence;
use ControlBot\Infrastructure\RecoveryProfile;
use ControlBot\Production\BackupReceipt;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RecoveryCenterUi
{
    private const HEALTH=['HEALTHY','DEGRADED','UNKNOWN','BLOCKED'];
    private const WORK_CLASSES=['backup_missing','backup_stale','offsite_missing','checksum_failed','restore_drill_failed','rpo_breached','rto_breached','retention_drift'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|credential)/i';

    public static function project(
        array $profileRaw,
        ?array $evidenceRaw,
        ?array $drillRaw,
        ?array $factoryHealthRaw,
        ?BackupReceipt $receipt,
    ): array {
        if(func_num_args()!==5) throw new InvalidArgumentException('Recovery UI action input is not supported.');
        $profile=RecoveryProfile::normalize($profileRaw);
        $evidence=self::evidence($evidenceRaw,$profileRaw,$receipt);
        $drill=self::drill($drillRaw,$profileRaw,$evidenceRaw,$receipt);
        $health=self::health($factoryHealthRaw,$profile['project_ref']);

        return [
            'version'=>1,
            'section'=>'security/resilience',
            'execution'=>false,
            'project_ref'=>$profile['project_ref'],
            'dr_status'=>$health['state'],
            'reasons'=>$health['reasons'],
            'work_item_classes'=>$health['work_item_classes'],
            'profile'=>[
                'status'=>RecoveryProfile::effectiveStatus($profileRaw),
                'rpo_target_minutes'=>$profile['targets']['rpo_minutes'],
                'rto_target_minutes'=>$profile['targets']['rto_minutes'],
                'restore_drill_cadence_hours'=>$profile['restore_drill_cadence_hours'],
                'manifest_ref'=>$profile['manifest_ref'],
            ],
            'signals'=>[
                'database_backup'=>self::sourceSignal($evidence,'database'),
                'offsite_copy'=>self::controlSignal($evidence,'offsite'),
                'immutable_copy'=>self::controlSignal($evidence,'immutability'),
                'media_versioning'=>self::sourceSignal($evidence,'media'),
            ],
            'restore_drill'=>self::drillView($drill,$profile),
            'health'=>[
                'source'=>'factory:recovery-health-v1',
                'observed_at'=>$health['observed_at'],
                'freshness'=>$health['freshness'],
                'backup_ref'=>$health['backup_ref'],
                'drill_status'=>$health['drill_status'],
            ],
            'actions'=>[],
        ];
    }

    private static function evidence(?array $raw,array $profile,?BackupReceipt $receipt): ?array
    {
        if($raw===null) return null;
        if($receipt===null) throw new InvalidArgumentException('Recovery UI evidence receipt required.');
        return RecoveryEvidence::normalize($raw,$profile,$receipt);
    }

    private static function drill(?array $raw,array $profile,?array $evidence,?BackupReceipt $receipt): ?array
    {
        if($raw===null) return null;
        if($evidence===null||$receipt===null) throw new InvalidArgumentException('Recovery UI drill provenance required.');
        return RecoveryDrillProjection::normalize($raw,$profile,$evidence,$receipt);
    }

    private static function health(?array $raw,string $projectRef): array
    {
        if($raw===null) return [
            'state'=>'UNKNOWN','reasons'=>['RECOVERY_HEALTH_MISSING'],'work_item_classes'=>[],
            'observed_at'=>null,'freshness'=>[],'backup_ref'=>null,'drill_status'=>null,
        ];
        self::safe($raw);
        InfrastructureProvider::assertFields($raw,[
            'version','project','state','observed_at','reasons','work_item_classes',
            'freshness','backup_ref','drill_status','drill_observed','authority','execute',
        ],'Recovery UI Factory Health');
        if(($raw['version']??null)!==1||($raw['authority']??null)!=='unchanged'||($raw['execute']??null)!==false)
            throw new InvalidArgumentException('Recovery UI Factory Health invalid.');
        $project=self::slug($raw['project']??null,'recovery.health.project');
        if(!self::projectMatches($projectRef,$project))
            throw new InvalidArgumentException('Recovery UI Factory Health project mismatch.');
        $state=self::enum($raw['state']??null,self::HEALTH,'recovery.health.state');
        $reasons=self::strings($raw['reasons']??null,'recovery.health.reasons',false);
        $classes=self::strings($raw['work_item_classes']??null,'recovery.health.work_item_classes',true);
        foreach($classes as $class) if(!in_array($class,self::WORK_CLASSES,true))
            throw new InvalidArgumentException('Recovery UI Factory Health class invalid.');
        if($state==='HEALTHY'&&$classes!==[])
            throw new InvalidArgumentException('Recovery UI healthy state cannot carry work classes.');
        $freshness=self::freshness($raw['freshness']??null);
        $observed=self::timestamp($raw['observed_at']??null);
        $backup=self::nullableText($raw['backup_ref']??null,'recovery.health.backup_ref');
        $drillStatus=$raw['drill_status']===null?null:self::enum($raw['drill_status'],['PASSED','BREACHED'],'recovery.health.drill_status');
        self::drillObserved($raw['drill_observed']??null);
        return ['state'=>$state,'reasons'=>$reasons,'work_item_classes'=>$classes,'observed_at'=>$observed,'freshness'=>$freshness,'backup_ref'=>$backup,'drill_status'=>$drillStatus];
    }

    private static function sourceSignal(?array $evidence,string $source): array
    {
        if($evidence===null) return self::unknownSignal();
        foreach($evidence['sources'] as $row) if($row['source']===$source) return [
            'state'=>$row['state'],'reported_state'=>$row['reported_state'],'freshness'=>$row['freshness'],
            'evidence_ref'=>$row['evidence_ref'],'observed_at'=>$row['observed_at'],
        ];
        return self::unknownSignal();
    }

    private static function controlSignal(?array $evidence,string $control): array
    {
        if($evidence===null||!isset($evidence['controls'][$control])) return self::unknownSignal();
        $row=$evidence['controls'][$control];
        return ['state'=>$row['state'],'reported_state'=>$row['reported_state'],'freshness'=>$row['freshness'],'evidence_ref'=>$row['evidence_ref'],'observed_at'=>$row['observed_at']];
    }

    private static function drillView(?array $drill,array $profile): array
    {
        if($drill===null) return [
            'status'=>'UNKNOWN','reported_status'=>'unknown','freshness'=>'unknown','observed_at'=>null,
            'rpo_seconds'=>null,'rto_seconds'=>null,
            'rpo_target_seconds'=>$profile['targets']['rpo_minutes']*60,
            'rto_target_seconds'=>$profile['targets']['rto_minutes']*60,
            'evidence_refs'=>[],
        ];
        return [
            'status'=>$drill['status'],'reported_status'=>$drill['reported_status'],'freshness'=>$drill['freshness'],'observed_at'=>$drill['observed_at'],
            'rpo_seconds'=>$drill['observed']['rpo_seconds'],'rto_seconds'=>$drill['observed']['rto_seconds'],
            'rpo_target_seconds'=>$drill['observed']['rpo_target_seconds'],'rto_target_seconds'=>$drill['observed']['rto_target_seconds'],
            'evidence_refs'=>$drill['evidence_refs'],
        ];
    }

    private static function unknownSignal(): array
    { return ['state'=>'unknown','reported_state'=>'unknown','freshness'=>'unknown','evidence_ref'=>null,'observed_at'=>null]; }

    private static function projectMatches(string $ref,string $project): bool
    {
        $prefix='controlbot:project/';
        if(!str_starts_with($ref,$prefix)) return false;
        $suffix=substr($ref,strlen($prefix));
        return $suffix===$project||$suffix==='project-'.$project;
    }

    private static function freshness(mixed $raw): array
    {
        if(!is_array($raw)||array_is_list($raw)||count($raw)>4) throw new InvalidArgumentException('Recovery UI freshness invalid.');
        foreach($raw as $key=>$value) if(!is_string($key)||!is_int($value)||$value<0)
            throw new InvalidArgumentException('Recovery UI freshness invalid.');
        ksort($raw,SORT_STRING); return $raw;
    }

    private static function drillObserved(mixed $raw): void
    {
        if($raw===null) return;
        InfrastructureProvider::assertFields($raw,['rpo_seconds','rto_seconds','rpo_target_seconds','rto_target_seconds'],'Recovery UI drill observed');
        foreach($raw as $value) if(!is_int($value)||$value<0)
            throw new InvalidArgumentException('Recovery UI drill observed invalid.');
    }

    private static function strings(mixed $raw,string $label,bool $allowEmpty): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>50||(!$allowEmpty&&$raw===[]))
            throw new InvalidArgumentException($label.' invalid.');
        $out=[]; foreach($raw as $item) $out[]=self::text($item,$label.'[]');
        $out=array_values(array_unique($out)); sort($out,SORT_STRING); return $out;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableText(mixed $value,string $label): ?string
    { return $value===null?null:self::text($value,$label); }

    private static function text(mixed $value,string $label): string
    {
        if(!is_string($value)||$value===''||strlen($value)>240||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        if(!is_string($value)||preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$value)!==1)
            throw new InvalidArgumentException('Recovery UI observed_at invalid.');
        try{$date=new DateTimeImmutable($value);}catch(\Exception $e){
            throw new InvalidArgumentException('Recovery UI observed_at invalid.',0,$e);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function safe(array $raw): void
    {
        $encoded=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($encoded)>100000||preg_match(self::SENSITIVE,$encoded)===1)
            throw new InvalidArgumentException('Recovery UI health contains sensitive material.');
    }
}
