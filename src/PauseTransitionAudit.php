<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class PauseTransitionAudit
{
    private const TRANSITIONS=[
        'create'=>['active','unknown'],
        'active'=>['releasing','released'],
        'releasing'=>['released'],
    ];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer\s+|credential|private[_ -]?key|api[_ -]?key)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]*[0-9])/i';

    public static function event(?array $beforeRaw,array $afterRaw,string $actor,int $occurredAt): array
    {
        if($occurredAt<1) throw new InvalidArgumentException('occurred_at invalid.');
        $after=PauseControl::state($afterRaw);
        $before=$beforeRaw===null?null:PauseControl::state($beforeRaw);
        $actor=self::actor($actor);
        $pauseId=self::safeRef($after['pause_id'],'pause_id');
        $scopeId=self::safeRef($after['scope_id'],'scope_id');
        $reason=self::freeText($after['reason'],'reason',240);
        $policyVersion=self::safeRef($after['policy_version'],'policy_version');
        $incidentId=self::safeRef($after['incident_id'],'incident_id');
        $evidenceRef=self::safeRef($after['evidence_ref'],'evidence_ref');

        if($before===null){
            if(!in_array($after['state'],self::TRANSITIONS['create'],true))
                throw new InvalidArgumentException('Pause creation transition invalid.');
            if($occurredAt<$after['created_at'])
                throw new InvalidArgumentException('Pause transition time invalid.');
        } else {
            self::sameIdentityAndProvenance($before,$after);
            $allowed=self::TRANSITIONS[$before['state']]??[];
            if(!in_array($after['state'],$allowed,true))
                throw new InvalidArgumentException('Pause state transition invalid.');
            if($occurredAt<$before['created_at']||$occurredAt<$after['created_at'])
                throw new InvalidArgumentException('Pause transition time invalid.');
        }
        foreach(['activated_at','released_at','safe_point_at'] as $field){
            if($after[$field]!==null&&$occurredAt<$after[$field])
                throw new InvalidArgumentException('Pause transition evidence is from the future.');
        }

        $basis=[
            'version'=>1,
            'event_id'=>self::eventId($pauseId,$after['state'],$occurredAt),
            'pause_id'=>$pauseId,
            'scope_type'=>$after['scope_type'],
            'scope_id'=>$scopeId,
            'actor'=>$actor,
            'source'=>$after['source'],
            'reason'=>$reason,
            'occurred_at'=>$occurredAt,
            'before_state'=>$before['state']??null,
            'after_state'=>$after['state'],
            'policy_version'=>$policyVersion,
            'incident_id'=>$incidentId,
            'evidence_ref'=>$evidenceRef,
        ];
        self::secretFree($basis);
        return $basis+['fingerprint'=>self::fingerprint($basis)];
    }

    public static function append(array $existing,array $eventRaw): array
    {
        if(!array_is_list($existing)||count($existing)>256)
            throw new InvalidArgumentException('Pause audit history invalid.');
        $event=self::auditEvent($eventRaw);
        $normalized=[];$lastAt=null;$seen=[];$stateByPause=[];
        foreach($existing as $raw){
            $row=self::auditEvent($raw);
            if(isset($seen[$row['event_id']]))
                throw new InvalidArgumentException('Pause audit event duplicated.');
            if($lastAt!==null&&$row['occurred_at']<$lastAt)
                throw new InvalidArgumentException('Pause audit history reordered.');
            $current=$stateByPause[$row['pause_id']]??null;
            if($row['before_state']!==$current)
                throw new InvalidArgumentException('Pause audit history chain invalid.');
            $seen[$row['event_id']]=$row;
            $stateByPause[$row['pause_id']]=$row['after_state'];
            $lastAt=$row['occurred_at'];
            $normalized[]=$row;
        }

        if(isset($seen[$event['event_id']])){
            if($seen[$event['event_id']]!==$event)
                throw new InvalidArgumentException('Pause audit event id conflict.');
            return $normalized;
        }
        if($lastAt!==null&&$event['occurred_at']<$lastAt)
            throw new InvalidArgumentException('Pause audit timestamp regressed.');
        $current=$stateByPause[$event['pause_id']]??null;
        if($event['before_state']!==$current)
            throw new InvalidArgumentException('Pause audit history chain invalid.');

        $normalized[]=$event;
        return $normalized;
    }

    private static function auditEvent(mixed $raw): array
    {
        self::fields($raw,[
            'version','event_id','pause_id','scope_type','scope_id','actor','source','reason',
            'occurred_at','before_state','after_state','policy_version','incident_id','evidence_ref','fingerprint',
        ],'PauseAuditEvent');
        if(($raw['version']??null)!==1||!is_int($raw['occurred_at'])||$raw['occurred_at']<1)
            throw new InvalidArgumentException('Pause audit event invalid.');

        $basis=$raw;
        unset($basis['fingerprint']);
        if(!is_string($raw['fingerprint'])||preg_match('/^[0-9a-f]{64}$/D',$raw['fingerprint'])!==1
            ||!hash_equals(self::fingerprint($basis),$raw['fingerprint']))
            throw new InvalidArgumentException('Pause audit fingerprint invalid.');

        if(!is_string($raw['event_id'])||preg_match('/^pause-event:[a-f0-9]{64}$/D',$raw['event_id'])!==1)
            throw new InvalidArgumentException('Pause audit event_id invalid.');
        $pauseId=self::safeRef($raw['pause_id'],'pause_id');
        $scopeId=self::safeRef($raw['scope_id'],'scope_id');
        if($pauseId===null||$scopeId===null)
            throw new InvalidArgumentException('Pause audit event identity invalid.');
        self::freeText($raw['reason'],'reason',240);
        if(!in_array($raw['scope_type'],['session','account','project','global'],true)
            ||!in_array($raw['source'],['owner','policy','system'],true)
            ||!in_array($raw['after_state'],['active','releasing','released','unknown'],true)
            ||($raw['before_state']!==null&&!in_array($raw['before_state'],['active','releasing'],true)))
            throw new InvalidArgumentException('Pause audit event state invalid.');
        $allowed=self::TRANSITIONS[$raw['before_state']??'create']??[];
        if(!in_array($raw['after_state'],$allowed,true))
            throw new InvalidArgumentException('Pause audit event transition invalid.');
        foreach(['policy_version','incident_id','evidence_ref'] as $field)
            self::safeRef($raw[$field],$field);
        if(!hash_equals(self::eventId($pauseId,$raw['after_state'],$raw['occurred_at']),$raw['event_id']))
            throw new InvalidArgumentException('Pause audit event_id mismatch.');
        self::actor($raw['actor']);
        self::secretFree($basis);
        return $raw;
    }

    private static function sameIdentityAndProvenance(array $before,array $after): void
    {
        foreach([
            'pause_id','scope_type','scope_id','reason','source','created_at','activated_at','safe_point_at',
            'preemptibility','policy_version','incident_id','evidence_ref',
        ] as $field){
            if($before[$field]!==$after[$field])
                throw new InvalidArgumentException('Pause transition provenance mismatch.');
        }
    }

    private static function freeText(mixed $value,string $label,int $max): string
    {
        if(!is_string($value)||trim($value)===''||strlen($value)>$max
            ||preg_match('/[\x00-\x1f\x7f]/',$value)===1
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return trim($value);
    }

    private static function safeRef(mixed $value,string $label): ?string
    {
        if($value===null) return null;
        if(!is_string($value)||strlen($value)<1||strlen($value)>180
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function actor(mixed $value): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>120||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|bearer|credential)/i',$value)===1
            ||preg_match(self::DIRECT_PII,$value)===1)
            throw new InvalidArgumentException('actor invalid.');
        return $value;
    }

    private static function eventId(string $pauseId,string $afterState,int $occurredAt): string
    {
        return 'pause-event:'.hash('sha256',$pauseId.'|'.$afterState.'|'.$occurredAt);
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('Pause audit contains sensitive material.');
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
