<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HostingReadPanel
{
    private const SIGNALS=['ok','degraded','critical','unknown'];
    private const SITES=['online','degraded','offline','unknown'];
    private const FRESHNESS=['fresh','stale','unknown'];
    public function __construct(private readonly \Closure $reader) {}

    public function read(ConnectionProfile $profile,CapabilityGrant $grant,array $context,int $now): array
    {
        if($now<1) throw new InvalidArgumentException('now inválido.');
        $safe=$profile->safeSnapshot();
        if(!$profile->allowsReadOnlyDiagnosis()) throw new RuntimeException('Perfil Hostinger no disponible.');
        self::fields($context,['issue','run_id','subject'],'context');
        $scope=[
            'capability'=>'hostinger.read','project'=>$safe['project'],'environment'=>$safe['environment'],
            'resource'=>'hosting-panel','operation'=>'hosting.snapshot.read','issue'=>$context['issue'],
            'run_id'=>$context['run_id'],'subject'=>$context['subject'],
        ];
        $auth=$grant->authorize($scope,$now);
        if(($auth['authorized']??false)!==true) throw new RuntimeException('hostinger.read denegado.');

        $request=$profile->probeRequest()+[
            'operation'=>'hosting.snapshot.read','capability'=>'hostinger.read','resource'=>'hosting-panel',
        ];
        try{$raw=($this->reader)($request);}catch(Throwable){throw new RuntimeException('Lectura Hostinger falló.');}
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('Respuesta Hostinger inválida.');
        self::fields($raw,[
            'fingerprint','observed_at','source','freshness','site_status','disk','databases',
            'cron','ssl','deploy_status','recent_errors'
        ],'provider');
        if(!is_string($raw['fingerprint'])||!$profile->matchesFingerprint($raw['fingerprint']))
            throw new RuntimeException('Fingerprint Hostinger cambió.');
        if(!is_int($raw['observed_at'])||$raw['observed_at']<1||$raw['observed_at']>$now+60)
            throw new InvalidArgumentException('observed_at inválido.');
        $fresh=self::choice($raw['freshness'],self::FRESHNESS,'freshness');
        $site=self::choice($raw['site_status'],self::SITES,'site_status');
        $signals=[];
        foreach(['disk','databases','cron','ssl','deploy_status','recent_errors'] as $key)
            $signals[$key]=self::choice($raw[$key],self::SIGNALS,$key);
        $source=self::plain($raw['source'],'source');

        $health='healthy';
        if($site==='offline'||in_array('critical',$signals,true)) $health='critical';
        elseif($site!=='online'||$fresh!=='fresh'||array_diff($signals,['ok'])!==[]) $health='degraded';

        $snapshot=[
            'project'=>$safe['project'],'environment'=>$safe['environment'],'profile_id'=>$safe['profile_id'],
            'observed_at'=>$raw['observed_at'],'connection_status'=>$safe['status'],'site_status'=>$site,
            'disk'=>$signals['disk'],'databases'=>$signals['databases'],'cron'=>$signals['cron'],
            'ssl'=>$signals['ssl'],'deploy_status'=>$signals['deploy_status'],
            'recent_errors'=>$signals['recent_errors'],'health'=>$health,'source'=>$source,'freshness'=>$fresh,
        ];
        self::secretFree($snapshot);
        return $snapshot;
    }

    public static function aggregate(array $snapshots): array
    {
        if(!array_is_list($snapshots)||count($snapshots)>100) throw new InvalidArgumentException('snapshots inválidos.');
        $out=[];
        foreach($snapshots as $row){
            if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('snapshot inválido.');
            self::fields($row,[
                'project','environment','profile_id','observed_at','connection_status','site_status','disk',
                'databases','cron','ssl','deploy_status','recent_errors','health','source','freshness'
            ],'snapshot');
            $key=$row['project'].'|'.$row['environment'];
            if(isset($out[$key])) throw new InvalidArgumentException('Scope Hostinger duplicado.');
            self::secretFree($row);$out[$key]=$row;
        }
        ksort($out,SORT_STRING);return array_values($out);
    }

    private static function fields(array $row,array $expected,string $label): void
    {
        $keys=array_keys($row);sort($keys);sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields inválidos.');
    }
    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' inválido.');
        return $value;
    }
    private static function plain(mixed $value,string $label): string
    {
        if(!is_string($value)||$value===''||strlen($value)>160||preg_match('/[\x00-\x1F\x7F]/',$value)===1
            ||preg_match('/password|private[_ -]?key|token|dsn|cookie|authorization|bearer/i',$value)===1)
            throw new InvalidArgumentException($label.' inválido.');
        return $value;
    }
    private static function secretFree(array $value): void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if(preg_match('/password|private[_ -]?key|secret_ref|token|dsn|cookie|authorization|bearer/i',$json)===1)
            throw new InvalidArgumentException('Snapshot contiene material sensible.');
    }
}
