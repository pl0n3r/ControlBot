<?php
declare(strict_types=1);
namespace ControlBot\Security;

use InvalidArgumentException;

final class SecurityAlertChannel
{
    private const SEVERITIES=['info','warning','critical'];
    private const CONFIDENCE=['confirmed','provider_reported','correlated','unknown'];
    private const CHANNEL_STATES=['healthy','degraded','down','stale','unavailable','unknown'];
    private const SENSITIVE='/([^\s]*@[^\s]*|-----BEGIN [^-]*PRIVATE KEY-----|\bbearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\s*[:=]\s*\S+|\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public static function project(array $event,array $channels,array $policy): array
    {
        $event=self::event($event);
        $channels=self::channels($channels);
        $policy=self::policy($policy);

        $primary=$channels['primary_state'];
        $independent=$channels['independent_state'];
        $primaryUnavailable=in_array($primary,['degraded','down','stale','unavailable'],true);

        if($primary==='healthy') {
            $decision='primary_channel';
            $channel='primary';
        } elseif($primary==='unknown') {
            $decision='owner_review';
            $channel='owner_review';
        } elseif($event['severity']==='critical') {
            if($primaryUnavailable&&$independent==='healthy') {
                $decision='independent_channel';
                $channel='independent';
            } else {
                $decision='owner_review';
                $channel='owner_review';
            }
        } elseif($policy['escalate_noncritical']) {
            if($primaryUnavailable&&$independent==='healthy') {
                $decision='independent_channel';
                $channel='independent';
            } else {
                $decision='owner_review';
                $channel='owner_review';
            }
        } else {
            $decision='no_alert';
            $channel='none';
        }

        $payload=$decision==='no_alert'?null:[
            'event_identity'=>$event['event_identity'],
            'event_type'=>$event['event_type'],
            'severity'=>$event['severity'],
            'confidence'=>$event['confidence'],
            'timestamp'=>$event['occurred_at']??$event['observed_at'],
            'context'=>['account_scope'=>$event['account_scope']],
        ];
        $intentBasis=[
            'event_identity'=>$event['event_identity'],
            'channel'=>$channel,
            'policy_version'=>$policy['version'],
        ];
        $result=[
            'version'=>1,
            'decision'=>$decision,
            'channel'=>$channel,
            'policy_version'=>$policy['version'],
            'event_identity'=>$event['event_identity'],
            'severity'=>$event['severity'],
            'confidence'=>$event['confidence'],
            'payload'=>$payload,
            'intent_id'=>'security-alert:'.substr(hash('sha256',json_encode($intentBasis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),0,40),
        ];
        self::secretFree($result);
        return $result;
    }

    private static function event(array $event): array
    {
        self::fields($event,[
            'version','provider','event_id','observed_at','occurred_at','event_type','severity','account_scope',
            'confidence','device','actor','event_identity','fingerprint',
        ],'SecurityEvent');
        if(($event['version']??null)!==1) throw new InvalidArgumentException('SecurityEvent version invalid.');
        if(!is_string($event['event_identity'])
            ||preg_match('/^security-event:(?:provider|fingerprint):[0-9a-f]{40}$/D',$event['event_identity'])!==1)
            throw new InvalidArgumentException('event_identity invalid.');
        if(!is_string($event['fingerprint'])||preg_match('/^[0-9a-f]{64}$/D',$event['fingerprint'])!==1)
            throw new InvalidArgumentException('event fingerprint invalid.');
        if(!is_string($event['event_type'])||preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$event['event_type'])!==1)
            throw new InvalidArgumentException('event_type invalid.');
        self::enum($event['severity'],self::SEVERITIES,'severity');
        self::enum($event['confidence'],self::CONFIDENCE,'confidence');
        self::opaqueRef($event['account_scope'],'account_scope');
        if(!is_int($event['observed_at'])||$event['observed_at']<1)
            throw new InvalidArgumentException('observed_at invalid.');
        if($event['occurred_at']!==null&&(!is_int($event['occurred_at'])||$event['occurred_at']<1||$event['occurred_at']>$event['observed_at']))
            throw new InvalidArgumentException('occurred_at invalid.');
        return $event;
    }

    private static function channels(array $channels): array
    {
        self::fields($channels,['primary_state','independent_state'],'ChannelState');
        return [
            'primary_state'=>self::enum($channels['primary_state'],self::CHANNEL_STATES,'primary_state'),
            'independent_state'=>self::enum($channels['independent_state'],self::CHANNEL_STATES,'independent_state'),
        ];
    }

    private static function policy(array $policy): array
    {
        self::fields($policy,['version','escalate_noncritical'],'AlertPolicy');
        if(!is_int($policy['version'])||$policy['version']<1)
            throw new InvalidArgumentException('policy version invalid.');
        if(!is_bool($policy['escalate_noncritical']))
            throw new InvalidArgumentException('escalate_noncritical invalid.');
        return $policy;
    }

    private static function opaqueRef(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::secretFree($value);
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item)self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('SecurityAlert intent contains sensitive material.');
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}
