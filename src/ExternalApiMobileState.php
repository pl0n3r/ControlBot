<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiMobileState
{
    private const SENSITIVITY=['public','confidential','restricted'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|public[_ -]?key|api[_ -]?key|otp|dsn)/i';

    public static function snapshot(array $raw,bool $connected,int $now): array
    {
        self::fields($raw,[
            'version','resource_ref','observed_at','received_at','max_age_seconds','source_ref','sensitivity',
        ],'MobileSnapshot');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('MobileSnapshot version invalid.');
        if($now<1) throw new InvalidArgumentException('now invalid.');

        $resource=self::ref($raw['resource_ref'],'resource_ref',false);
        $sensitivity=self::oneOf($raw['sensitivity'],self::SENSITIVITY,'sensitivity');
        $maxAge=self::boundedPositive($raw['max_age_seconds'],'max_age_seconds',86400);

        $observed=$raw['observed_at'];
        $received=$raw['received_at'];
        $source=$raw['source_ref'];

        $allMissing=$observed===null&&$received===null&&$source===null;
        $allPresent=$observed!==null&&$received!==null&&$source!==null;
        if(!$allMissing&&!$allPresent) throw new InvalidArgumentException('snapshot provenance incomplete.');

        if($allMissing){
            return [
                'version'=>1,'resource_ref'=>$resource,'observed_at'=>null,'received_at'=>null,
                'max_age_seconds'=>$maxAge,'source_ref'=>null,'sensitivity'=>$sensitivity,
                'state'=>'unknown','age_seconds'=>null,'current'=>false,
            ];
        }

        $observed=self::timestamp($observed,'observed_at');
        $received=self::timestamp($received,'received_at');
        if($observed>$received||$received>$now) throw new InvalidArgumentException('snapshot timestamps invalid.');
        $source=self::ref($source,'source_ref',true);
        $age=$now-$observed;

        $state=!$connected?'offline':($age>$maxAge?'stale':'fresh');
        return [
            'version'=>1,'resource_ref'=>$resource,'observed_at'=>$observed,'received_at'=>$received,
            'max_age_seconds'=>$maxAge,'source_ref'=>$source,'sensitivity'=>$sensitivity,
            'state'=>$state,'age_seconds'=>$age,'current'=>$state==='fresh',
        ];
    }

    public static function mutationPolicy(
        array $raw,bool $connected,int $now,string $operationId,bool $idempotent,
        bool $stepUpRequired,bool $highAuthority,array $offlineAllowlist=[]
    ): array {
        $snapshot=self::snapshot($raw,$connected,$now);
        $operation=self::operationId($operationId);
        $allowlist=self::allowlist($offlineAllowlist);

        $fresh=$snapshot['state']==='fresh';
        $queueable=$snapshot['state']==='offline'
            &&$idempotent&&!$stepUpRequired&&!$highAuthority
            &&in_array($operation,$allowlist,true);

        $reasons=[];
        if(!$fresh) $reasons[]='snapshot_not_fresh';
        if($stepUpRequired) $reasons[]='step_up_required';
        if($highAuthority) $reasons[]='high_authority';
        if(!$idempotent) $reasons[]='not_idempotent';
        if($snapshot['state']==='offline'&&!in_array($operation,$allowlist,true))
            $reasons[]='operation_not_offline_allowlisted';

        return [
            'version'=>1,'operation_id'=>$operation,'snapshot_state'=>$snapshot['state'],
            'freshness_gate'=>$fresh?'pass':'fail','queueable'=>$queueable,
            'reasons'=>array_values(array_unique($reasons)),
        ];
    }

    public static function cachePolicy(
        array $raw,bool $connected,int $now,bool $persistentRequested,bool $storageEncrypted
    ): array {
        $snapshot=self::snapshot($raw,$connected,$now);
        $sensitivity=$snapshot['sensitivity'];

        $persistentAllowed=false;
        if($persistentRequested){
            if($sensitivity==='public') $persistentAllowed=true;
            elseif($sensitivity==='confidential') $persistentAllowed=$storageEncrypted;
        }

        return [
            'version'=>1,'resource_ref'=>$snapshot['resource_ref'],'snapshot_state'=>$snapshot['state'],
            'sensitivity'=>$sensitivity,'persistent_requested'=>$persistentRequested,
            'storage_encrypted'=>$storageEncrypted,'persistent_cache_allowed'=>$persistentAllowed,
        ];
    }

    private static function allowlist(array $items): array
    {
        if(!array_is_list($items)||count($items)>32) throw new InvalidArgumentException('offline allowlist invalid.');
        $out=[];
        foreach($items as $item){
            $operation=self::operationId($item);
            if(in_array($operation,$out,true)) throw new InvalidArgumentException('offline allowlist duplicated.');
            $out[]=$operation;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function operationId(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$value)!==1)
            throw new InvalidArgumentException('operation_id invalid.');
        return $value;
    }

    private static function ref(mixed $value,string $label,bool $controlbot): string
    {
        if(!is_string($value)||strlen($value)>160||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        $pattern=$controlbot
            ?'#^controlbot:[a-z][a-z0-9._/-]{1,119}$#D'
            :'#^[a-z][a-z0-9._/-]{1,119}$#D';
        if(preg_match($pattern,$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function boundedPositive(mixed $value,string $label,int $max): int
    {
        if(!is_int($value)||$value<1||$value>$max) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
