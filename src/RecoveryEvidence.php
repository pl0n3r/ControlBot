<?php
declare(strict_types=1);
namespace ControlBot\Infrastructure;
use ControlBot\Production\BackupReceipt;
use InvalidArgumentException;
final class RecoveryEvidence
{
    private const CONTROLS=['checksum','encryption','offsite','immutability'];
    private const SOURCES=['database','media','repository','configuration'];
    private const STATES=['verified','failed','unknown'];
    private const SOURCE_STATES=['verified','failed','unknown','not_applicable'];
    private const FRESHNESS=['fresh','stale','unknown'];
    public static function normalize(array $raw,array $profileRaw,BackupReceipt $receipt): array
    {
        InfrastructureProvider::assertFields($raw,['version','project_ref','profile_ref','controls','sources'],'RecoveryEvidence');
        if (($raw['version']??null)!==1) throw new InvalidArgumentException('RecoveryEvidence version invalid.');
        $profile=RecoveryProfile::normalize($profileRaw); $project=self::projectRef($raw['project_ref']??null);
        if ($project!==$profile['project_ref']||$project!==self::producerProjectRef($receipt)) throw new InvalidArgumentException('RecoveryEvidence project mismatch.');
        return ['version'=>1,'project_ref'=>$project,'profile_ref'=>self::profileRef($raw['profile_ref']??null,$project),'controls'=>self::controls($raw['controls']??null),'sources'=>self::sources($raw['sources']??null,$profile['sources'])];
    }
    private static function controls(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw,self::CONTROLS,'RecoveryEvidence controls'); $out=[];
        foreach(self::CONTROLS as $n) $out[$n]=self::evidenceState($raw[$n]??null,"recovery.controls.$n",self::STATES);
        return $out;
    }
    private static function sources(mixed $rows,array $profileSources): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)!==count(self::SOURCES)) throw new InvalidArgumentException('RecoveryEvidence sources invalid.');
        $out=[];
        foreach($rows as $row){
            InfrastructureProvider::assertFields($row,['source','state','evidence_ref','observed_at','freshness'],'RecoveryEvidence source');
            $source=InfrastructureProvider::normalizeEnum($row['source']??null,self::SOURCES,'recovery.source');
            if(isset($out[$source])) throw new InvalidArgumentException('RecoveryEvidence source duplicated.');
            $reported=InfrastructureProvider::normalizeEnum($row['state']??null,self::SOURCE_STATES,"recovery.sources.$source.state"); $app=$profileSources[$source]??null;
            if($app==='not_applicable'){
                if($reported!=='not_applicable'||($row['evidence_ref']??null)!==null||($row['observed_at']??null)!==null||($row['freshness']??null)!=='unknown') throw new InvalidArgumentException('RecoveryEvidence not_applicable source invalid.');
                $out[$source]=['source'=>$source,'state'=>'not_applicable','reported_state'=>'not_applicable','evidence_ref'=>null,'observed_at'=>null,'freshness'=>'unknown']; continue;
            }
            if($app!=='required'||$reported==='not_applicable') throw new InvalidArgumentException('RecoveryEvidence source applicability mismatch.');
            $out[$source]=['source'=>$source,...self::evidenceState(['state'=>$row['state'],'evidence_ref'=>$row['evidence_ref'],'observed_at'=>$row['observed_at'],'freshness'=>$row['freshness']],"recovery.sources.$source",self::STATES)];
        }
        foreach(self::SOURCES as $source) if(!isset($out[$source])) throw new InvalidArgumentException('RecoveryEvidence source missing.');
        return array_map(static fn(string $source):array=>$out[$source],self::SOURCES);
    }
    private static function evidenceState(mixed $raw,string $label,array $states): array
    {
        InfrastructureProvider::assertFields($raw,['state','evidence_ref','observed_at','freshness'],$label);
        $reported=InfrastructureProvider::normalizeEnum($raw['state']??null,$states,"$label.state"); $fresh=InfrastructureProvider::normalizeEnum($raw['freshness']??null,self::FRESHNESS,"$label.freshness");
        $ref=self::nullableEvidenceRef($raw['evidence_ref']??null,"$label.evidence_ref"); $at=self::nullableTimestamp($raw['observed_at']??null,"$label.observed_at");
        if($fresh==='unknown'&&($ref!==null||$at!==null||$reported!=='unknown')) throw new InvalidArgumentException("$label unknown evidence invalid.");
        if($fresh!=='unknown'&&($ref===null||$at===null)) throw new InvalidArgumentException("$label provenance required.");
        return ['state'=>$fresh==='fresh'?$reported:'unknown','reported_state'=>$reported,'evidence_ref'=>$ref,'observed_at'=>$at,'freshness'=>$fresh];
    }
    private static function producerProjectRef(BackupReceipt $receipt): string
    {
        $project=$receipt->safeEvidence()['project']??null;
        if(!is_string($project)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$project)!==1) throw new InvalidArgumentException('RecoveryEvidence producer project invalid.');
        return 'controlbot:project/project-'.$project;
    }
    private static function projectRef(mixed $value): string
    {
        $ref=InfrastructureProvider::normalizeControlRef($value,'recovery.project_ref');
        if(!str_starts_with($ref,'controlbot:project/')||strlen($ref)<=strlen('controlbot:project/')) throw new InvalidArgumentException('recovery.project_ref invalid.');
        return $ref;
    }
    private static function profileRef(mixed $value,string $project): string
    {
        $ref=InfrastructureProvider::normalizeControlRef($value,'recovery.profile_ref'); $prefix='controlbot:recovery-profile/'.substr($project,strlen('controlbot:project/'));
        if(!str_starts_with($ref,$prefix)||strlen($ref)<=strlen($prefix)) throw new InvalidArgumentException('recovery.profile_ref project mismatch.');
        return $ref;
    }
    private static function nullableEvidenceRef(mixed $v,string $l): ?string { return $v===null?null:InfrastructureProvider::normalizeReference($v,$l); }
    private static function nullableTimestamp(mixed $v,string $l): ?int { return $v===null?null:InfrastructureProvider::normalizeTimestamp($v,$l); }
}
