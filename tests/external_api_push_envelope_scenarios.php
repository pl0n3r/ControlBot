<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalApiPushEnvelope.php';

use ControlBot\ExternalApi\ExternalApiPushEnvelope;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
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
        'sensitive_target'=>bad(fn()=>ExternalApiPushEnvelope::envelope(pushRaw(['target_ref'=>'controlbot:decision/secret-item']))),
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
    ];
}elseif($case==='delivery'){
    $raw=pushRaw();
    $out=[
        'a'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,true),
        'b'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,true),
        'preference_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,false,true,true),
        'policy_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,false,true),
        'severity_off'=>ExternalApiPushEnvelope::deliveryPolicy($raw,true,true,false),
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
