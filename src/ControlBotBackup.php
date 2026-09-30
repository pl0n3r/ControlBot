<?php
declare(strict_types=1);

namespace ControlBot\Backup;

use InvalidArgumentException;

final class ControlBotBackup
{
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|api[_ -]?key|dsn|signature|x-amz-)/i';

    public static function backupReceipt(array $raw): array
    {
        self::fields($raw,['version','backup_id','project','environment','resource','started_at','completed_at','checksum','size_bytes','storage_ref','encryption','status']);
        if($raw['version']!==1||$raw['status']!=='completed') throw new InvalidArgumentException('Backup receipt status invalid.');
        $out=[
            'version'=>1,
            'backup_id'=>self::id($raw['backup_id'],'backup_id'),
            'project'=>self::slug($raw['project'],'project'),
            'environment'=>self::slug($raw['environment'],'environment'),
            'resource'=>self::resource($raw['resource']),
            'started_at'=>self::time($raw['started_at'],'started_at'),
            'completed_at'=>self::time($raw['completed_at'],'completed_at'),
            'checksum'=>self::checksum($raw['checksum']),
            'size_bytes'=>self::size($raw['size_bytes']),
            'storage_ref'=>self::ref($raw['storage_ref'],'storage_ref'),
            'encryption'=>self::choice($raw['encryption'],['aes-256-gcm','kms-managed','provider-managed'],'encryption'),
            'status'=>'completed',
        ];
        if($out['completed_at']<$out['started_at']) throw new InvalidArgumentException('Backup receipt timestamps invalid.');
        $out['fingerprint']=self::hash($out);
        return $out;
    }

    public static function restoreReceipt(array $backupRaw,array $raw): array
    {
        $backup=self::backupReceipt($backupRaw);
        self::fields($raw,['version','restore_id','backup_id','source_checksum','destination_checksum','target_environment','started_at','completed_at','status','evidence_ref']);
        if($raw['version']!==1||$raw['status']!=='passed') throw new InvalidArgumentException('Restore receipt status invalid.');
        $target=self::slug($raw['target_environment'],'target_environment');
        if(in_array($target,['production','prod'],true)) throw new InvalidArgumentException('Restore drill target must be isolated.');
        $out=[
            'version'=>1,
            'restore_id'=>self::id($raw['restore_id'],'restore_id'),
            'backup_id'=>self::id($raw['backup_id'],'backup_id'),
            'source_checksum'=>self::checksum($raw['source_checksum']),
            'destination_checksum'=>self::checksum($raw['destination_checksum']),
            'target_environment'=>$target,
            'started_at'=>self::time($raw['started_at'],'started_at'),
            'completed_at'=>self::time($raw['completed_at'],'completed_at'),
            'status'=>'passed',
            'evidence_ref'=>self::ref($raw['evidence_ref'],'evidence_ref'),
        ];
        if($out['backup_id']!==$backup['backup_id']||$out['source_checksum']!==$backup['checksum']
            ||$out['destination_checksum']!==$backup['checksum']
            ||$out['started_at']<$backup['completed_at']||$out['completed_at']<$out['started_at'])
            throw new InvalidArgumentException('Restore drill evidence mismatch.');
        $out['fingerprint']=self::hash($out);
        return $out;
    }

    public static function restorable(array $backupRaw,?array $restoreRaw): array
    {
        $backup=self::backupReceipt($backupRaw);
        if($restoreRaw===null) return ['restorable'=>false,'reason'=>'restore_evidence_missing','backup_id'=>$backup['backup_id']];
        try{$restore=self::restoreReceipt($backupRaw,$restoreRaw);}
        catch(InvalidArgumentException){return ['restorable'=>false,'reason'=>'restore_evidence_invalid','backup_id'=>$backup['backup_id']];}
        return ['restorable'=>true,'reason'=>'verified_restore_drill','backup_id'=>$backup['backup_id'],'restore_id'=>$restore['restore_id']];
    }

    public static function retentionPlan(array $backupRows,array $restoreRows,int $keepLatest=2): array
    {
        if(!array_is_list($backupRows)||$backupRows===[]||count($backupRows)>128||$keepLatest<1||$keepLatest>32)
            throw new InvalidArgumentException('Retention input invalid.');
        if(!array_is_list($restoreRows)||count($restoreRows)>128) throw new InvalidArgumentException('Restore evidence list invalid.');

        $backups=[];$byId=[];$scopes=[];
        foreach($backupRows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Backup list invalid.');
            $b=self::backupReceipt($raw);
            if(isset($byId[$b['backup_id']])) throw new InvalidArgumentException('Backup duplicated.');
            $byId[$b['backup_id']]=$raw;$backups[$b['backup_id']]=$b;
            $scopes[self::scopeKey($b)][]=$b['backup_id'];
        }
        $restores=[];
        foreach($restoreRows as $raw){
            if(!is_array($raw)||!is_string($raw['backup_id']??null)||!isset($byId[$raw['backup_id']])) throw new InvalidArgumentException('Restore evidence orphaned.');
            if(isset($restores[$raw['backup_id']])) throw new InvalidArgumentException('Restore evidence ambiguous.');
            $restores[$raw['backup_id']]=$raw;
        }

        $keep=[];$delete=[];$protected=[];
        foreach($scopes as $ids){
            usort($ids,static fn(string $a,string $b): int=>[$backups[$b]['completed_at'],$b]<=>[$backups[$a]['completed_at'],$a]);
            foreach(array_slice($ids,0,$keepLatest) as $id)$keep[$id]=true;
            $restorable=[];
            foreach($ids as $id) if(self::restorable($byId[$id],$restores[$id]??null)['restorable']) $restorable[]=$id;
            if($restorable===[]){
                foreach($ids as $id)$keep[$id]=true;
                continue;
            }
            usort($restorable,static fn(string $a,string $b): int=>[$backups[$b]['completed_at'],$b]<=>[$backups[$a]['completed_at'],$a]);
            $protected[$restorable[0]]=true;$keep[$restorable[0]]=true;
            foreach($ids as $id) if(!isset($keep[$id]))$delete[$id]=true;
        }
        $keepIds=array_keys($keep);$deleteIds=array_keys($delete);$protectedIds=array_keys($protected);
        sort($keepIds);sort($deleteIds);sort($protectedIds);
        return ['keep'=>$keepIds,'delete'=>$deleteIds,'protected_restorable'=>$protectedIds,'authority'=>'plan_only'];
    }

    private static function fields(mixed $raw,array $expected): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('Receipt fields invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('Receipt fields invalid.');
    }
    private static function id(mixed $v,string $key): string {if(!is_string($v)||preg_match('/^[a-z0-9][a-z0-9._:-]{2,95}$/D',$v)!==1)throw new InvalidArgumentException($key.' invalid.');self::safe($v);return $v;}
    private static function slug(mixed $v,string $key): string {if(!is_string($v)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$v)!==1)throw new InvalidArgumentException($key.' invalid.');self::safe($v);return $v;}
    private static function resource(mixed $v): string {if(!is_string($v)||preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D',$v)!==1)throw new InvalidArgumentException('resource invalid.');self::safe($v);return $v;}
    private static function time(mixed $v,string $key): int {if(!is_int($v)||$v<1)throw new InvalidArgumentException($key.' invalid.');return $v;}
    private static function size(mixed $v): int {if(!is_int($v)||$v<1||$v>10_000_000_000_000)throw new InvalidArgumentException('size_bytes invalid.');return $v;}
    private static function checksum(mixed $v): string {if(!is_string($v)||preg_match('/^[a-f0-9]{64}$/D',$v)!==1)throw new InvalidArgumentException('checksum invalid.');return $v;}
    private static function ref(mixed $v,string $key): string {if(!is_string($v)||preg_match('~^[a-z][a-z0-9._:/-]{3,159}$~D',$v)!==1||preg_match('~^(?:https?|s3|ssh)://~i',$v)===1)throw new InvalidArgumentException($key.' invalid.');self::safe($v);return $v;}
    private static function choice(mixed $v,array $allowed,string $key): string {if(!is_string($v)||!in_array($v,$allowed,true))throw new InvalidArgumentException($key.' invalid.');return $v;}
    private static function safe(string $v): void {if(preg_match(self::SENSITIVE,$v)===1)throw new InvalidArgumentException('Sensitive receipt metadata invalid.');}
    private static function scopeKey(array $b): string {return $b['project'].'|'.$b['environment'].'|'.$b['resource'];}
    private static function hash(array $v): string {return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
}
