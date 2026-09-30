<?php
declare(strict_types=1);
namespace ControlBot\Security;

use InvalidArgumentException;

final class SecurityAlertChannel
{
    private const CHANNEL_STATES=['healthy','degraded','down','stale','unavailable','unknown'];

    public static function project(array $event,array $channels,array $policy): array
    {
        $event=self::event($event);
        $channels=self::channels($channels);
        $policy=self::policy($policy);

        $primary=$channels['primary_state'];
        $independent=$channels['independent_state'];
        $primaryUnavailable=in_array($primary,['degraded','down','stale','unavailable'],true);
        $independentEligible=$event['severity']==='critical'||$policy['escalate_noncritical'];

        if($primary==='healthy') {
            $decision='primary_channel';
            $channel='primary';
        } elseif($primary==='unknown') {
            $decision='owner_review';
            $channel='owner_review';
        } elseif(!$independentEligible) {
            $decision='no_alert';
            $channel='none';
        } elseif($primaryUnavailable&&$independent==='healthy') {
            $decision='independent_channel';
            $channel='independent';
        } else {
            $decision='owner_review';
            $channel='owner_review';
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
        return [
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
    }

    private static function event(array $event): array
    {
        $expected=[
            'version','provider','event_id','observed_at','occurred_at','event_type','severity','account_scope',
            'confidence','device','actor','event_identity','fingerprint',
        ];
        $actual=array_keys($event);
        sort($actual,SORT_STRING);
        sort($expected,SORT_STRING);
        if($actual!==$expected)
            throw new InvalidArgumentException('SecurityEvent fields invalid.');

        $raw=$event;
        unset($raw['event_identity'],$raw['fingerprint']);
        $canonical=SecurityEvent::normalize($raw);
        if($canonical!=$event)
            throw new InvalidArgumentException('SecurityEvent must be canonical normalized v1.');
        return $canonical;
    }

    private static function channels(array $channels): array
    {
        $keys=array_keys($channels);
        sort($keys,SORT_STRING);
        if($keys!==['independent_state','primary_state'])
            throw new InvalidArgumentException('ChannelState fields invalid.');
        foreach(['primary_state','independent_state'] as $key){
            if(!is_string($channels[$key])||!in_array($channels[$key],self::CHANNEL_STATES,true))
                throw new InvalidArgumentException($key.' invalid.');
        }
        return $channels;
    }

    private static function policy(array $policy): array
    {
        $keys=array_keys($policy);
        sort($keys,SORT_STRING);
        if($keys!==['escalate_noncritical','version'])
            throw new InvalidArgumentException('AlertPolicy fields invalid.');
        if(!is_int($policy['version'])||$policy['version']<1)
            throw new InvalidArgumentException('policy version invalid.');
        if(!is_bool($policy['escalate_noncritical']))
            throw new InvalidArgumentException('escalate_noncritical invalid.');
        return $policy;
    }
}
