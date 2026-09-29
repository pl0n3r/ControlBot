<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalApiPushEnvelope.php';

use ControlBot\ExternalApi\ExternalApiPushEnvelope;

/** Return true only when the callable fails closed with InvalidArgumentException. */
function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
/** Build one valid baseline envelope with explicit overrides. */
function pushRaw(array $o=[]): array { return array_replace([
    'version'=>1,
    'notification_ref'=>'notification:11111111111111111111111111111111',
    'type'=>'strategic_decision',
    'target_ref'=>'controlbot:owner-inbox/entry-alpha',
    'venture_ref'=>'controlbot:venture/venture-alpha',
    'occurred_at'=>1500,
    'source_ref'=>'controlbot:owner-inbox/event-alpha',
    'freshness'=>'current',
    'generic_copy_key'=>'decision_required',
    'correlation_id'=>'22222222222222222222222222222222',
],$o); }

$case=$argv[1]??'';
if($case==='closed'){
    $types=['critical_incident','security_risk','spend_approval','venture_degradation','strategic_decision','decision_result'];
    $targets=['controlbot:owner-inbox/entry-alpha','controlbot:decision/decision-alpha','controlbot:incident/incident-alpha'];
    $ok=[];
    foreach($types as $type) $ok[] = ExternalApiPushEnvelope::envelope(pushRaw(['type'=>$type]));
    $targetRows=[];
    foreach($targets as $target) $targetRows[] = ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>$target]));
    $out=[
        'types'=>$ok,'targets'=>$targetRows,
        'bad_type'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['type'=>'marketing_noise']))),
        'external_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'https://example.invalid/item']))),
    ];
}elseif($case==='minimal'){
    $extra=[];
    foreach(['title','body','external_url'] as $field){
        $row=pushRaw();$row[$field]='not allowed';
        $extra[$field]=bad(fn()=>ExternalApiPushEnvelope::envelope($row));
    }
    foreach(['device_'.'token','provider_'.'credential','api_'.'secret'] as $field){
        $row=pushRaw();$row[$field]='forbidden';
        $extra[$field]=bad(fn()=>ExternalApiPushEnvelope::envelope($row));
    }
    $out=[
        'payload'=>ExternalApiPushEnvelope::envelope(pushRaw()),
        'extra'=>$extra,
        'sensitive_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:decision/secret']))),
        'legit_dsn_target'=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:incident/dsn-outage']))['target_ref'],
        'token_hyphen_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:decision/token-value']))),
        'token_dot_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:decision/token.value']))),
        'password_dot_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:decision/password.value']))),
        'bad_version'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['version'=>2]))),
        'bad_notification_ref'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['notification_ref'=>'notification:not-opaque']))),
        'bad_correlation_id'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['correlation_id'=>'not-opaque']))),
        'bad_venture_ref'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['venture_ref'=>'external:venture/alpha']))),
        'bad_occurred_type'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['occurred_at'=>'1500']))),
        'bad_occurred_zero'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['occurred_at'=>0]))),
        'bad_copy_key'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['generic_copy_key'=>'custom_free_text']))),
    ];
}elseif($case==='deep_link'){
    $out=[
        'inbox'=>ExternalApiPushEnvelope::deepLink(pushRaw()),
        'decision'=>ExternalApiPushEnvelope::deepLink(pushRaw(['target_ref'=>'controlbot:decision/decision-alpha'])),
        'incident'=>ExternalApiPushEnvelope::deepLink(pushRaw(['target_ref'=>'controlbot:incident/incident-alpha'])),
    ];
}elseif($case==='freshness'){
    $unknown=pushRaw(['freshness'=>'unknown','occurred_at'=>null,'source_ref'=>null]);
    $out=[
        'current'=>ExternalApiPushEnvelope::envelope(pushRaw()),
        'stale'=>ExternalApiPushEnvelope::envelope(pushRaw(['freshness'=>'stale'])),
        'unknown'=>ExternalApiPushEnvelope::envelope($unknown),
        'unknown_source'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['freshness'=>'unknown','occurred_at'=>null]))),
        'unknown_time'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['freshness'=>'unknown','source_ref'=>null]))),
        'current_missing_source'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['source_ref'=>null]))),
        'stale_missing_time'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['freshness'=>'stale','occurred_at'=>null]))),
    ];
}elseif($case==='delivery'){
    $raw=pushRaw();
    $out=[
        'a'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,true),
        'b'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,true),
        'preference_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,false,true,true),
        'policy_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,false,true),
        'severity_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,false),
        'different_type'=>ExternalApiPushEnvelope::deliveryPolicy(pushRaw(['type'=>'decision_result']),true,true,true),
        'different_target'=>ExternalApiPushEnvelope::deliveryPolicy(pushRaw(['target_ref'=>'controlbot:decision/decision-alpha']),true,true,true),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiPushEnvelope::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiPushEnvelope::class
    ));
    sort($methods,SORT_STRING);$out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiPushEnvelope.php')];
}else{fwrite(STDERR,"Unknown external api push envelope scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
