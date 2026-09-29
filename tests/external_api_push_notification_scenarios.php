<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ExternalApiContract.php';
require_once __DIR__.'/../src/ExternalApiPushNotification.php';

use ControlBot\ExternalApi\ExternalApiContract;
use ControlBot\ExternalApi\ExternalApiPushNotification;

function bad(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }
function raw(array $overrides=[]): array {
    return array_replace([
        'version'=>1,
        'notification_ref'=>'notification:'.str_repeat('a',32),
        'category'=>'critical_incident',
        'severity'=>'critical',
        'entity_ref'=>'controlbot:incident/incident-alpha',
        'detail_operation_id'=>'owner_inbox.read',
        'localization_key'=>'push.critical_incident',
        'issued_at'=>1000,
        'expires_at'=>2000,
        'dedupe_key'=>'dedupe:'.str_repeat('b',32),
    ],$overrides);
}
function context(array $overrides=[]): array {
    return array_replace([
        'category_preference'=>true,
        'severity_preference'=>true,
        'default_preference_enabled'=>false,
        'previous_dedupe_key'=>null,
        'previous_delivered_at'=>null,
        'dedupe_window_seconds'=>300,
        'rate_delivered_count'=>0,
        'rate_window_started_at'=>1400,
        'rate_window_seconds'=>600,
        'rate_limit'=>3,
        'now'=>1500,
    ],$overrides);
}

$case=$argv[1]??'';
if($case==='minimal'){
    $payload=ExternalApiPushNotification::payload(raw());
    $extra=[];
    foreach(['title','body','amount','email','decision','options','device_token','provider_credential','url'] as $field)
        $extra[$field]=bad(fn()=>ExternalApiPushNotification::payload(raw([$field=>'secret'])));
    $out=[
        'payload'=>$payload,
        'extra'=>$extra,
        'sensitive_entity'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['entity_ref'=>'controlbot:incident/password.value']))),
        'external_entity'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['entity_ref'=>'https://example.test/detail']))),
        'bad_notification'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['notification_ref'=>'notification:not-opaque']))),
        'bad_dedupe'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['dedupe_key'=>'dedupe:campaign-alpha']))),
    ];
}elseif($case==='detail'){
    $payload=ExternalApiPushNotification::payload(raw());
    $catalog=ExternalApiContract::catalog();
    $out=[
        'operation'=>$payload['detail_operation_id'],
        'requires_authenticated_detail'=>$payload['requires_authenticated_detail'],
        'is_public_get'=>isset($catalog['GET /api/v1/owner-inbox'])
            &&$catalog['GET /api/v1/owner-inbox']['operation_id']===$payload['detail_operation_id'],
        'post_operation'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['detail_operation_id'=>'owner_decision.decide']))),
        'unknown_operation'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['detail_operation_id'=>'incident.read']))),
    ];
}elseif($case==='closed'){
    $out=[
        'bad_category'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['category'=>'marketing']))),
        'bad_severity'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['severity'=>'emergency']))),
        'bad_localization'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['localization_key'=>'push.custom']))),
        'bad_issued'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['issued_at'=>0]))),
        'bad_expiry'=>bad(fn()=>ExternalApiPushNotification::payload(raw(['expires_at'=>1000]))),
    ];
}elseif($case==='policy'){
    $same='dedupe:'.str_repeat('b',32);
    $out=[
        'deliver'=>ExternalApiPushNotification::deliveryPolicy(raw(),context()),
        'category_off'=>ExternalApiPushNotification::deliveryPolicy(raw(),context(['category_preference'=>false])),
        'default_quiet'=>ExternalApiPushNotification::deliveryPolicy(raw(),context([
            'category_preference'=>null,'severity_preference'=>null,
        ])),
        'expired'=>ExternalApiPushNotification::deliveryPolicy(raw(),context(['now'=>2000])),
        'duplicate'=>ExternalApiPushNotification::deliveryPolicy(raw(),context([
            'previous_dedupe_key'=>$same,'previous_delivered_at'=>1450,
        ])),
        'rate_limited'=>ExternalApiPushNotification::deliveryPolicy(raw(),context(['rate_delivered_count'=>3])),
        'dedupe_window_elapsed'=>ExternalApiPushNotification::deliveryPolicy(raw(),context([
            'previous_dedupe_key'=>$same,'previous_delivered_at'=>1100,
        ])),
        'rate_window_elapsed'=>ExternalApiPushNotification::deliveryPolicy(raw(),context([
            'rate_delivered_count'=>99,'rate_window_started_at'=>800,
        ])),
    ];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(ExternalApiPushNotification::class);
    $methods=[];
    foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
        if($method->getDeclaringClass()->getName()===ExternalApiPushNotification::class) $methods[]=$method->getName();
    sort($methods);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiPushNotification.php')];
}else{
    fwrite(STDERR,"unknown scenario\n");
    exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR);
