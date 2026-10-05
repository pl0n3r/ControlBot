<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;

final class HostingerPublicApiAdapter
{
    public static function websites(
        string $bearer,
        callable $transport,
        int $now,
    ): array {
        self::automatic('hostinger.read');
        $rows=self::rows(HostingerPublicApi::request(
            'GET',
            HostingerPublicApi::BASE_URL.'/api/hosting/v1/websites',
            $bearer,
            $transport,
        ));
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)){
                throw new RuntimeException('Hostinger website invalid.');
            }
            $out[]=[
                'domain'=>self::text($row['domain']??null,253),
                'website_type'=>self::text($row['website_type']??'unknown',32),
            ];
        }
        return self::evidence('hostinger.read',$now,['websites'=>$out]);
    }

    public static function cronSnapshot(
        string $username,
        string $bearer,
        callable $transport,
        int $now,
    ): array {
        self::automatic('cron.snapshot');
        $username=self::username($username);
        $rows=self::rows(HostingerPublicApi::request(
            'GET',
            HostingerPublicApi::BASE_URL.'/api/hosting/v1/accounts/'.$username.'/cron-jobs',
            $bearer,
            $transport,
        ));
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)){
                throw new RuntimeException('Hostinger cron invalid.');
            }
            $out[]=[
                'uid'=>self::text($row['uid']??null,80),
                'time'=>self::text($row['time']??null,100),
                'command'=>self::command($row['command']??null),
            ];
        }
        return self::evidence('cron.snapshot',$now,['cron_jobs'=>$out]);
    }

    public static function cronOutput(
        string $username,
        string $uid,
        string $bearer,
        callable $transport,
        int $now,
    ): array {
        self::automatic('cron.snapshot');
        $url=HostingerPublicApi::BASE_URL.'/api/hosting/v1/accounts/'
            .self::username($username).'/cron-jobs/'.self::text($uid,80).'/output';
        $payload=HostingerPublicApi::request('GET',$url,$bearer,$transport);
        return self::evidence('cron.snapshot',$now,[
            'uid'=>$uid,
            'output'=>self::command($payload['output']??''),
        ]);
    }

    public static function planCronWrite(
        CapabilityGrant $grant,
        array $scope,
        string $username,
        array $cron,
        int $now,
    ): array {
        if(($scope['capability']??null)!=='cron.write'){
            throw new RuntimeException('cron.write grant required.');
        }
        $decision=$grant->authorize($scope,$now);
        if(!($decision['authorized']??false)){
            throw new RuntimeException('cron.write denied.');
        }
        if(array_keys($cron)!==['time','command']){
            throw new InvalidArgumentException('Cron fields invalid.');
        }
        return [
            'version'=>1,
            'capability'=>'cron.write',
            'execution'=>false,
            'dry_run'=>true,
            'method'=>'POST',
            'path'=>'/api/hosting/v1/accounts/'.self::username($username).'/cron-jobs',
            'body'=>[
                'time'=>self::text($cron['time'],100),
                'command'=>self::command($cron['command']),
            ],
            'grant_id'=>$decision['grant_id'],
            'requires_backup'=>true,
        ];
    }

    private static function automatic(string $capability): void
    {
        $policy=CapabilityPolicy::classify($capability);
        if(!($policy['known']??false)||($policy['decision']??null)!=='automatic'){
            throw new RuntimeException('Hostinger capability denied.');
        }
    }

    private static function rows(array $payload): array
    {
        $rows=array_is_list($payload)?$payload:($payload['data']??null);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>500){
            throw new RuntimeException('Hostinger list invalid.');
        }
        return $rows;
    }

    private static function evidence(string $capability,int $now,array $data): array
    {
        if($now<1){throw new InvalidArgumentException('Observed time invalid.');}
        return [
            'source'=>'hostinger-public-api',
            'capability'=>$capability,
            'observed_at'=>$now,
            'freshness'=>'current',
            'data'=>$data,
        ];
    }

    private static function username(string $value): string
    {
        if(preg_match('/^[A-Za-z0-9._-]{2,64}$/D',$value)!==1){
            throw new InvalidArgumentException('Hostinger username invalid.');
        }
        return $value;
    }

    private static function text(mixed $value,int $max): string
    {
        if(
            !is_string($value) || $value==='' || strlen($value)>$max
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1
        ){
            throw new InvalidArgumentException('Hostinger field invalid.');
        }
        return $value;
    }

    private static function command(mixed $value): string
    {
        $value=self::text($value,500);
        $sensitive="/(?:\\bbearer\\s+\\S+|\\b(?:password|passwd|pwd|token|api[_-]?key|secret)[\"']?\\s*(?:[=:]\\s*|\\s+)[\"']?\\S+|\\b[A-Z0-9_]*(?:TOKEN|PASSWORD|PASSWD|PWD|API_KEY|SECRET)[A-Z0-9_]*\\s*=\\s*\\S+)/i";
        if(preg_match($sensitive,$value)===1){
            throw new RuntimeException('Hostinger evidence contains sensitive command.');
        }
        return $value;
    }
}
