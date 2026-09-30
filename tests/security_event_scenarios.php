<?php
declare(strict_types=1);
require __DIR__.'/../src/SecurityEvent.php';

use ControlBot\Security\SecurityEvent;
use InvalidArgumentException;

function providerEvent(): array{return [
    'version'=>1,'provider'=>'github','event_id'=>'evt-new-device-001','observed_at'=>2000,'occurred_at'=>1900,
    'event_type'=>'new_device','severity'=>'warning','account_scope'=>'controlbot:account/owner-primary',
    'confidence'=>'provider_reported','device'=>[
        'type'=>'desktop','platform'=>'macOS','browser'=>'Chrome',
        'location'=>['country'=>'CO','region'=>'Risaralda','city'=>'Pereira'],
    ],'actor'=>null,
];}
function rejected(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}

$input=providerEvent();
$newDevice=SecurityEvent::normalize($input);
$repeat=$input;$repeat['observed_at']=2100;$repeat=SecurityEvent::normalize($repeat);
$reordered=SecurityEvent::normalize(array_reverse($input,true));

$fallback=$input;$fallback['event_id']=null;$fallback['occurred_at']=1850;$fallback['confidence']='correlated';
$fallbackA=SecurityEvent::normalize($fallback);
$fallbackB=SecurityEvent::normalize(array_reverse($fallback,true));

$factors=[];
foreach(['passkey_added','totp_changed','recovery_method_changed'] as $type){
    $event=$input;$event['event_id']=null;$event['event_type']=$type;$event['occurred_at']=1800;
    $event['device']=null;$factors[$type]=SecurityEvent::normalize($event);
}

$controlBot=SecurityEvent::normalize([
    'version'=>1,'provider'=>'controlbot','event_id'=>'audit-production-001','observed_at'=>2300,'occurred_at'=>2290,
    'event_type'=>'production_authority_changed','severity'=>'critical','account_scope'=>'controlbot:account/owner-primary',
    'confidence'=>'confirmed','device'=>null,
    'actor'=>['actor_ref'=>'controlbot:actor/owner','context_ref'=>'controlbot:context/production-settings'],
]);

$unknown=$input;$unknown['event_id']=null;$unknown['occurred_at']=1880;$unknown['confidence']='unknown';$unknown['device']=null;
$unknown=SecurityEvent::normalize($unknown);
$confirmedIncomplete=$input;$confirmedIncomplete['event_id']=null;$confirmedIncomplete['confidence']='confirmed';
$extra=$input;$extra['raw_payload']='opaque';
$precise=$input;$precise['device']['location']['latitude']=4.8143;
$secret=$input;$secret['event_id']='token:supersecretvalue12345';
$missingActor=[
    'version'=>1,'provider'=>'controlbot','event_id'=>'audit-production-002','observed_at'=>2400,'occurred_at'=>2390,
    'event_type'=>'production_authority_changed','severity'=>'critical','account_scope'=>'controlbot:account/owner-primary',
    'confidence'=>'confirmed','device'=>null,'actor'=>null,
];

echo json_encode([
    'new_device'=>$newDevice,'repeat'=>$repeat,'reordered'=>$reordered,
    'fallback_a'=>$fallbackA,'fallback_b'=>$fallbackB,'factors'=>$factors,'controlbot'=>$controlBot,
    'incomplete_unknown'=>$unknown,
    'reject_confirmed_incomplete'=>rejected(fn()=>SecurityEvent::normalize($confirmedIncomplete)),
    'reject_extra_field'=>rejected(fn()=>SecurityEvent::normalize($extra)),
    'reject_precise_location'=>rejected(fn()=>SecurityEvent::normalize($precise)),
    'reject_secret'=>rejected(fn()=>SecurityEvent::normalize($secret)),
    'reject_missing_actor'=>rejected(fn()=>SecurityEvent::normalize($missingActor)),
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
