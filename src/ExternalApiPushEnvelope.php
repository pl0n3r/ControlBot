<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiPushEnvelope
{
    private const TYPES=[
        'critical_incident','security_risk','spend_approval',
        'venture_degradation','strategic_decision','decision_result',
    ];
    private const COPY_KEYS=['attention_required','decision_required','result_available'];
    private const FRESHNESS=['current','stale','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|public[_ -]?key|api[_ -]?key|otp|dsn)/i';

    public static function envelope(array $raw): array
    {
        self::fields($raw,[
            'version','notification_ref','type','target_ref','venture_ref',
            'occurred_at','source_ref','freshness','generic_copy_key','correlation_id',
        ],'PushEnvelope');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('PushEnvelope version invalid.');

        $type=self::oneOf($raw['type'],self::TYPES,'type');
        $target=self::targetRef($raw['target_ref']);
        $freshness=self::oneOf($raw['freshness'],self::FRESHNESS,'freshness');

        $occurred=$raw['occurred_at'];
        $source=$raw['source_ref'];
        if($freshness==='unknown'){
            if($occurred!==null||$source!==null)
                throw new InvalidArgumentException('Unknown push freshness cannot invent provenance.');
        }else{
            $occurred=self::timestamp($occurred,'occurred_at');
            $source=self::controlbotRef($source,'source_ref');
        }

        $venture=$raw['venture_ref'];
        if($venture!==null){
            if(!is_string($venture)||preg_match('#^controlbot:venture/[a-z][a-z0-9-]{1,63}$#D',$venture)!==1)
                throw new InvalidArgumentException('venture_ref invalid.');
        }

        return [
            'version'=>1,
            'notification_ref'=>self::opaque($raw['notification_ref'],'notification_ref','notification'),
            'type'=>$type,
            'target_ref'=>$target,
            'venture_ref'=>$venture,
            'occurred_at'=>$occurred,
            'source_ref'=>$source,
            'freshness'=>$freshness,
            'generic_copy_key'=>self::oneOf($raw['generic_copy_key'],self::COPY_KEYS,'generic_copy_key'),
            'correlation_id'=>self::opaque($raw['correlation_id'],'correlation_id',null),
            'collapse_key'=>self::collapseKey($type,$target),
        ];
    }

    public static function deepLink(array $raw): array
    {
        $envelope=self::envelope($raw);
        $route=match(true){
            str_starts_with($envelope['target_ref'],'controlbot:owner-inbox/')=>'owner_inbox_detail',
            str_starts_with($envelope['target_ref'],'controlbot:decision/')=>'owner_decision_detail',
            str_starts_with($envelope['target_ref'],'controlbot:incident/')=>'incident_detail',
            default=>throw new InvalidArgumentException('target_ref invalid.'),
        };
        return [
            'route'=>$route,
            'target_ref'=>$envelope['target_ref'],
            'requires_authenticated_api_fetch'=>true,
        ];
    }

    public static function deliveryPolicy(
        array $raw,
        bool $preferenceEnabled,
        bool $policyAllowsType,
        bool $severityAllowsDelivery,
    ): array {
        $envelope=self::envelope($raw);
        return [
            'version'=>1,
            'notification_ref'=>$envelope['notification_ref'],
            'type'=>$envelope['type'],
            'collapse_key'=>$envelope['collapse_key'],
            'preference_enabled'=>$preferenceEnabled,
            'policy_allows_type'=>$policyAllowsType,
            'severity_allows_delivery'=>$severityAllowsDelivery,
            'eligible'=>$preferenceEnabled&&$policyAllowsType&&$severityAllowsDelivery,
        ];
    }

    private static function collapseKey(string $type,string $target): string
    {
        $hex=hash('sha256',$type.'|'.$target);
        return 'collapse:'.implode('',array_map(
            static fn(string $pair): string=>'h'.$pair,
            str_split($hex,2)
        ));
    }

    private static function targetRef(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('#^controlbot:(owner-inbox|decision|incident)/[a-z][a-z0-9._/-]{1,139}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('target_ref invalid.');
        return $value;
    }

    private static function controlbotRef(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('#^controlbot:[a-z][a-z0-9._/-]{1,159}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function opaque(mixed $value,string $label,?string $namespace): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($label.' invalid.');
        $pattern=$namespace===null
            ?'/^[a-f0-9]{32}$/D'
            :'/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D';
        if(preg_match($pattern,$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}
