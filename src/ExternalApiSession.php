<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiSession
{
    private const SESSION_MAX_SECONDS = 3600;
    private const STEP_UP_MAX_SECONDS = 300;

    public static function device(array $raw,string $expectedIdentityId): array
    {
        return self::deviceRecord($raw,self::identity($expectedIdentityId));
    }

    public static function session(
        array $raw,
        array $deviceRaw,
        string $expectedIdentityId,
        string $expectedScope,
        int $now,
    ): array {
        self::positive($now,'now');
        $identity=self::identity($expectedIdentityId);
        $scope=self::scope($expectedScope);
        $device=self::deviceRecord($deviceRaw,$identity);
        if($device['state']!=='active') throw new InvalidArgumentException('device revoked.');

        $session=self::sessionRecord($raw,$identity);
        if(!hash_equals($device['device_ref'],$session['device_ref']))
            throw new InvalidArgumentException('session device mismatch.');
        if(!hash_equals($scope,$session['scope']))
            throw new InvalidArgumentException('session scope mismatch.');
        if($session['state']!=='active') throw new InvalidArgumentException('session revoked.');
        if($session['issued_at']>$now || $now>=$session['expires_at'])
            throw new InvalidArgumentException('session expired.');

        return $session;
    }

    public static function stepUp(
        array $raw,
        array $sessionRaw,
        array $deviceRaw,
        string $expectedIdentityId,
        string $expectedScope,
        int $now,
    ): array {
        $session=self::session($sessionRaw,$deviceRaw,$expectedIdentityId,$expectedScope,$now);
        $device=self::deviceRecord($deviceRaw,$session['identity_id']);

        self::fields($raw,[
            'version','step_up_ref','session_ref','device_ref','identity_id',
            'method','verified_at','expires_at','state','revoked_at','revocation_reason',
        ],'StepUpEvidence');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('StepUpEvidence version invalid.');

        $verified=self::positive($raw['verified_at'],'verified_at');
        $expires=self::positive($raw['expires_at'],'expires_at');
        if($verified<$session['issued_at'] || $verified>$now || $expires<=$verified
            ||($expires-$verified)>self::STEP_UP_MAX_SECONDS || $now>=$expires)
            throw new InvalidArgumentException('step-up lifetime invalid.');

        $identity=self::identity($raw['identity_id']);
        if(!hash_equals($session['identity_id'],$identity)
            ||!hash_equals($session['session_ref'],self::opaque($raw['session_ref'],'session_ref','session'))
            ||!hash_equals($device['device_ref'],self::opaque($raw['device_ref'],'device_ref','device')))
            throw new InvalidArgumentException('step-up binding mismatch.');

        $state=self::oneOf($raw['state'],['active','revoked'],'step_up.state');
        [$revokedAt,$reason]=self::revocation($state,$raw['revoked_at'],$raw['revocation_reason'],$verified);
        if($revokedAt!==null && $revokedAt>$now)
            throw new InvalidArgumentException('step-up revocation time invalid.');
        if($state!=='active') throw new InvalidArgumentException('step-up revoked.');

        return [
            'version'=>1,
            'step_up_ref'=>self::opaque($raw['step_up_ref'],'step_up_ref','stepup'),
            'session_ref'=>$session['session_ref'],
            'device_ref'=>$device['device_ref'],
            'identity_id'=>$identity,
            'method'=>self::oneOf($raw['method'],['passkey','mfa'],'method'),
            'verified_at'=>$verified,
            'expires_at'=>$expires,
            'state'=>$state,
            'revoked_at'=>$revokedAt,
            'revocation_reason'=>$reason,
        ];
    }

    public static function safeInventory(array $devicesRaw,array $sessionsRaw,string $expectedIdentityId): array
    {
        $identity=self::identity($expectedIdentityId);
        if(!array_is_list($devicesRaw)||!array_is_list($sessionsRaw)
            ||count($devicesRaw)>50||count($sessionsRaw)>100)
            throw new InvalidArgumentException('inventory invalid.');

        $devices=[];$deviceRefs=[];
        foreach($devicesRaw as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('device record invalid.');
            $row=self::deviceRecord($raw,$identity);
            if(isset($deviceRefs[$row['device_ref']])) throw new InvalidArgumentException('device duplicated.');
            $deviceRefs[$row['device_ref']]=true;
            $devices[]=$row;
        }

        $sessions=[];$sessionRefs=[];
        foreach($sessionsRaw as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('session record invalid.');
            $row=self::sessionRecord($raw,$identity);
            if(!isset($deviceRefs[$row['device_ref']])) throw new InvalidArgumentException('session device unknown.');
            if(isset($sessionRefs[$row['session_ref']])) throw new InvalidArgumentException('session duplicated.');
            $sessionRefs[$row['session_ref']]=true;
            $sessions[]=$row;
        }

        usort($devices,static fn(array $a,array $b): int=>$a['device_ref']<=>$b['device_ref']);
        usort($sessions,static fn(array $a,array $b): int=>$a['session_ref']<=>$b['session_ref']);
        return ['version'=>1,'identity_id'=>$identity,'devices'=>$devices,'sessions'=>$sessions];
    }

    private static function deviceRecord(array $raw,string $expectedIdentityId): array
    {
        self::fields($raw,[
            'version','device_ref','identity_id','registered_at','state','revoked_at','revocation_reason',
        ],'DeviceRecord');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('DeviceRecord version invalid.');
        $identity=self::identity($raw['identity_id']);
        if(!hash_equals($expectedIdentityId,$identity)) throw new InvalidArgumentException('device identity mismatch.');

        $registered=self::positive($raw['registered_at'],'registered_at');
        $state=self::oneOf($raw['state'],['active','revoked'],'device.state');
        [$revokedAt,$reason]=self::revocation($state,$raw['revoked_at'],$raw['revocation_reason'],$registered);

        return [
            'version'=>1,'device_ref'=>self::opaque($raw['device_ref'],'device_ref','device'),
            'identity_id'=>$identity,'registered_at'=>$registered,'state'=>$state,
            'revoked_at'=>$revokedAt,'revocation_reason'=>$reason,
        ];
    }

    private static function sessionRecord(array $raw,string $expectedIdentityId): array
    {
        self::fields($raw,[
            'version','session_ref','device_ref','identity_id','scope','issued_at','expires_at',
            'state','revoked_at','revocation_reason',
        ],'SessionRecord');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('SessionRecord version invalid.');
        $identity=self::identity($raw['identity_id']);
        if(!hash_equals($expectedIdentityId,$identity)) throw new InvalidArgumentException('session identity mismatch.');

        $issued=self::positive($raw['issued_at'],'issued_at');
        $expires=self::positive($raw['expires_at'],'expires_at');
        if($expires<=$issued || ($expires-$issued)>self::SESSION_MAX_SECONDS)
            throw new InvalidArgumentException('session lifetime invalid.');
        $state=self::oneOf($raw['state'],['active','revoked'],'session.state');
        [$revokedAt,$reason]=self::revocation($state,$raw['revoked_at'],$raw['revocation_reason'],$issued);

        return [
            'version'=>1,'session_ref'=>self::opaque($raw['session_ref'],'session_ref','session'),
            'device_ref'=>self::opaque($raw['device_ref'],'device_ref','device'),
            'identity_id'=>$identity,'scope'=>self::scope($raw['scope']),
            'issued_at'=>$issued,'expires_at'=>$expires,'state'=>$state,
            'revoked_at'=>$revokedAt,'revocation_reason'=>$reason,
        ];
    }

    private static function revocation(string $state,mixed $at,mixed $reason,int $notBefore): array
    {
        if($state==='active'){
            if($at!==null||$reason!==null) throw new InvalidArgumentException('active record cannot carry revocation.');
            return [null,null];
        }
        $at=self::positive($at,'revoked_at');
        if($at<$notBefore) throw new InvalidArgumentException('revoked_at invalid.');
        if(!is_string($reason)||preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$reason)!==1)
            throw new InvalidArgumentException('revocation_reason invalid.');
        return [$at,$reason];
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function identity(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('identity_id invalid.');
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('scope invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positive(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
