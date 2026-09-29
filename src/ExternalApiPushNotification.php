<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiPushNotification
{
    private const CATEGORIES=[
        'critical_incident','owner_security_risk','approval_required',
        'venture_degraded','strategic_decision','decision_result',
    ];
    private const SEVERITIES=['info','attention','critical'];
    private const LOCALIZATION=[
        'critical_incident'=>'push.critical_incident',
        'owner_security_risk'=>'push.owner_security_risk',
        'approval_required'=>'push.approval_required',
        'venture_degraded'=>'push.venture_degraded',
        'strategic_decision'=>'push.strategic_decision',
        'decision_result'=>'push.decision_result',
    ];
    private const SENSITIVE_COMPONENTS=
        '#(?:^|[:/])(?:password|passwd|secret|cookie|authorization|bearer|credential|'
        .'private[_ -]?key|public[_ -]?key|api[_ -]?key|otp|dsn)(?:$|[:/.])#i';
    private const SENSITIVE_TOKEN='#(?:^|[:/])token(?:$|[:/._-])#i';

    public static function payload(array $raw): array
    {
        self::fields($raw,[
            'version','notification_ref','category','severity','entity_ref',
            'detail_operation_id','localization_key','issued_at','expires_at','dedupe_key',
        ],'PushNotification');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('PushNotification version invalid.');

        $category=self::enumValue($raw['category'],self::CATEGORIES,'category');
        $issued=self::positiveInt($raw['issued_at'],'issued_at');
        $expires=self::positiveInt($raw['expires_at'],'expires_at');
        if($expires<=$issued) throw new InvalidArgumentException('expires_at invalid.');

        $localization=$raw['localization_key']??null;
        if(!is_string($localization)||$localization!==self::LOCALIZATION[$category])
            throw new InvalidArgumentException('localization_key invalid.');

        return [
            'version'=>1,
            'notification_ref'=>self::opaqueRef($raw['notification_ref'],'notification','notification_ref'),
            'category'=>$category,
            'severity'=>self::enumValue($raw['severity'],self::SEVERITIES,'severity'),
            'entity_ref'=>self::entityRef($raw['entity_ref']),
            'detail_operation_id'=>self::detailOperation($raw['detail_operation_id']),
            'localization_key'=>$localization,
            'issued_at'=>$issued,
            'expires_at'=>$expires,
            'dedupe_key'=>self::opaqueRef($raw['dedupe_key'],'dedupe','dedupe_key'),
            'requires_authenticated_detail'=>true,
        ];
    }

    public static function deliveryPolicy(array $raw,array $context): array
    {
        $payload=self::payload($raw);
        self::fields($context,[
            'category_preference','severity_preference','default_preference_enabled',
            'previous_dedupe_key','previous_delivered_at','dedupe_window_seconds',
            'rate_delivered_count','rate_window_started_at','rate_window_seconds',
            'rate_limit','now',
        ],'PushDeliveryContext');

        $default=$context['default_preference_enabled']??null;
        if(!is_bool($default)) throw new InvalidArgumentException('default_preference_enabled invalid.');
        $categoryPreference=self::nullableBool($context['category_preference'],'category_preference');
        $severityPreference=self::nullableBool($context['severity_preference'],'severity_preference');
        $preferenceEnabled=($categoryPreference??$default)&&($severityPreference??$default);

        $now=self::positiveInt($context['now'],'now');
        $dedupeWindow=self::positiveInt($context['dedupe_window_seconds'],'dedupe_window_seconds');
        $rateWindow=self::positiveInt($context['rate_window_seconds'],'rate_window_seconds');
        $rateLimit=self::positiveInt($context['rate_limit'],'rate_limit');
        $rateCount=$context['rate_delivered_count']??null;
        if(!is_int($rateCount)||$rateCount<0) throw new InvalidArgumentException('rate_delivered_count invalid.');
        $rateStarted=self::positiveInt($context['rate_window_started_at'],'rate_window_started_at');
        if($rateStarted>$now) throw new InvalidArgumentException('rate_window_started_at invalid.');

        $previousKey=$context['previous_dedupe_key']??null;
        $previousAt=$context['previous_delivered_at']??null;
        if(($previousKey===null)!==($previousAt===null))
            throw new InvalidArgumentException('previous delivery evidence incomplete.');
        if($previousKey!==null){
            $previousKey=self::opaqueRef($previousKey,'dedupe','previous_dedupe_key');
            $previousAt=self::positiveInt($previousAt,'previous_delivered_at');
            if($previousAt>$now) throw new InvalidArgumentException('previous_delivered_at invalid.');
        }

        $expired=$now>=$payload['expires_at'];
        $duplicate=$previousKey===$payload['dedupe_key']
            &&$previousAt!==null&&($now-$previousAt)<$dedupeWindow;
        $rateLimited=($now-$rateStarted)<$rateWindow&&$rateCount>=$rateLimit;

        $reasons=[];
        if(!$preferenceEnabled) $reasons[]='preference_disabled';
        if($expired) $reasons[]='expired';
        if($duplicate) $reasons[]='duplicate';
        if($rateLimited) $reasons[]='rate_limited';

        return [
            'version'=>1,
            'notification_ref'=>$payload['notification_ref'],
            'decision'=>$reasons===[]?'deliver':'suppress',
            'reasons'=>$reasons,
            'preference_enabled'=>$preferenceEnabled,
            'expired'=>$expired,
            'duplicate'=>$duplicate,
            'rate_limited'=>$rateLimited,
            'requires_authenticated_detail'=>true,
        ];
    }

    private static function detailOperation(mixed $value): string
    {
        if(!is_string($value)||$value==='') throw new InvalidArgumentException('detail_operation_id invalid.');
        foreach(ExternalApiContract::catalog() as $route=>$operation){
            if(str_starts_with($route,'GET ')
                &&($operation['operation_id']??null)===$value
                &&($operation['mutation']??true)===false) return $value;
        }
        throw new InvalidArgumentException('detail_operation_id invalid.');
    }

    private static function entityRef(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('#^controlbot:[a-z][a-z0-9._/-]{1,159}$#D',$value)!==1
            ||preg_match(self::SENSITIVE_COMPONENTS,$value)===1
            ||preg_match(self::SENSITIVE_TOKEN,$value)===1)
            throw new InvalidArgumentException('entity_ref invalid.');
        return $value;
    }

    private static function opaqueRef(mixed $value,string $namespace,string $label): string
    {
        if(!is_string($value)
            ||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableBool(mixed $value,string $label): ?bool
    {
        if($value!==null&&!is_bool($value)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)
            ||array_fill_keys(array_keys($row),true)!=array_fill_keys($expected,true))
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}
