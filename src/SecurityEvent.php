<?php
declare(strict_types=1);
namespace ControlBot\Security;

use InvalidArgumentException;

final class SecurityEvent
{
    private const TYPES=[
        'new_login','new_device','privileged_session','passkey_added','passkey_removed','totp_changed',
        'recovery_method_changed','credential_rotated','production_authority_changed','billing_security_changed',
        'security_alert_acknowledged','repository_visibility_changed',
    ];
    private const SEVERITIES=['info','warning','critical'];
    private const CONFIDENCE=['confirmed','provider_reported','correlated','unknown'];
    private const DEVICE_TYPES=['desktop','mobile','tablet','server','unknown'];
    private const CONTROLBOT_SENSITIVE=[
        'credential_rotated','production_authority_changed','billing_security_changed','repository_visibility_changed',
    ];
    private const SENSITIVE='/([^\s]*@[^\s]*|-----BEGIN [^-]*PRIVATE KEY-----|\bbearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\s*[:=]\s*\S+|\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public static function normalize(array $raw): array
    {
        self::fields($raw,['version','provider','event_id','observed_at','occurred_at','event_type','severity','account_scope','confidence','device','actor'],'SecurityEvent');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('SecurityEvent version invalid.');

        $provider=self::slug($raw['provider'],'provider');
        $eventId=self::nullableEventId($raw['event_id']);
        $observedAt=self::positiveInt($raw['observed_at'],'observed_at');
        $occurredAt=self::nullablePositiveInt($raw['occurred_at'],'occurred_at');
        if($occurredAt!==null&&$occurredAt>$observedAt) throw new InvalidArgumentException('occurred_at cannot be after observed_at.');
        if($eventId===null&&$occurredAt===null) throw new InvalidArgumentException('SecurityEvent requires stable deduplication evidence.');

        $eventType=self::enum($raw['event_type'],self::TYPES,'event_type');
        $severity=self::enum($raw['severity'],self::SEVERITIES,'severity');
        $confidence=self::enum($raw['confidence'],self::CONFIDENCE,'confidence');
        $scope=self::opaqueRef($raw['account_scope'],'account_scope');
        $device=self::device($raw['device']);
        $actor=self::actor($raw['actor']);
        if($confidence==='confirmed'&&($eventId===null||$occurredAt===null))
            throw new InvalidArgumentException('Confirmed security event requires complete evidence.');
        if($provider==='controlbot'&&in_array($eventType,self::CONTROLBOT_SENSITIVE,true)&&$actor===null)
            throw new InvalidArgumentException('Sensitive ControlBot event requires actor context.');

        $base=[
            'version'=>1,'provider'=>$provider,'event_id'=>$eventId,'observed_at'=>$observedAt,'occurred_at'=>$occurredAt,
            'event_type'=>$eventType,'severity'=>$severity,'account_scope'=>$scope,'confidence'=>$confidence,
            'device'=>$device,'actor'=>$actor,
        ];
        $basis=[
            'provider'=>$provider,'event_id'=>$eventId,'occurred_at'=>$occurredAt,'event_type'=>$eventType,
            'account_scope'=>$scope,'device'=>$device,'actor'=>$actor,
        ];
        $fingerprint=self::digest($basis);
        $identity=$eventId===null
            ?'security-event:fingerprint:'.substr($fingerprint,0,40)
            :'security-event:provider:'.substr(self::digest(['provider'=>$provider,'event_id'=>$eventId]),0,40);
        $out=$base+['event_identity'=>$identity,'fingerprint'=>$fingerprint];
        self::secretFree($out);
        return $out;
    }

    private static function device(mixed $raw): ?array
    {
        if($raw===null)return null;
        self::fields($raw,['type','platform','browser','location'],'device');
        $type=$raw['type']===null?null:self::enum($raw['type'],self::DEVICE_TYPES,'device.type');
        $platform=self::nullableLabel($raw['platform'],'device.platform');
        $browser=self::nullableLabel($raw['browser'],'device.browser');
        $location=self::location($raw['location']);
        if($type===null&&$platform===null&&$browser===null&&$location===null)
            throw new InvalidArgumentException('Empty device context invalid.');
        return ['type'=>$type,'platform'=>$platform,'browser'=>$browser,'location'=>$location];
    }

    private static function location(mixed $raw): ?array
    {
        if($raw===null)return null;
        self::fields($raw,['country','region','city'],'location');
        if(!is_string($raw['country'])||preg_match('/^[A-Z]{2}$/D',$raw['country'])!==1)
            throw new InvalidArgumentException('location.country invalid.');
        return [
            'country'=>$raw['country'],
            'region'=>self::nullableLabel($raw['region'],'location.region'),
            'city'=>self::nullableLabel($raw['city'],'location.city'),
        ];
    }

    private static function actor(mixed $raw): ?array
    {
        if($raw===null)return null;
        self::fields($raw,['actor_ref','context_ref'],'actor');
        $actor=self::opaqueRef($raw['actor_ref'],'actor.actor_ref');
        $context=self::opaqueRef($raw['context_ref'],'actor.context_ref');
        if(!str_starts_with($actor,'controlbot:actor/')||!str_starts_with($context,'controlbot:context/'))
            throw new InvalidArgumentException('actor context must use ControlBot opaque refs.');
        return ['actor_ref'=>$actor,'context_ref'=>$context];
    }

    private static function digest(array $value): string
    {
        return hash('sha256',json_encode(self::canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(self::canonical(...),$value);
        ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=self::canonical($item);
        return $value;
    }

    private static function nullableEventId(mixed $value): ?string
    {
        if($value===null)return null;
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,127}$/D',$value)!==1)
            throw new InvalidArgumentException('event_id invalid.');
        self::secretFree($value);
        return $value;
    }

    private static function opaqueRef(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::secretFree($value);
        return $value;
    }

    private static function nullableLabel(mixed $value,string $label): ?string
    {
        if($value===null)return null;
        if(!is_string($value)||preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._+()\/-]{0,79}$/uD',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::secretFree($value);
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,39}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::secretFree($value);
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function positiveInt(mixed $value,string $label): int
    {if(!is_int($value)||$value<1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function nullablePositiveInt(mixed $value,string $label): ?int
    {return $value===null?null:self::positiveInt($value,$label);}

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item)self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('SecurityEvent contains sensitive material.');
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}
