<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumCampaign.php';
require __DIR__.'/../src/MomentumEmail.php';

use ControlBot\Momentum\MomentumEmail;

function brand424(string $venture='venture-condor'): array {
    return ['version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111','venture_id'=>$venture,
        'tone_ref'=>'tone:22222222222222222222222222222222','constraints'=>['brand-safe'],
        'source_ref'=>'source:33333333333333333333333333333333','observed_at'=>1000];
}
function campaign424(string $venture='venture-condor',array $channels=['email','web']): array {
    return ['version'=>1,'campaign_id'=>'campaign:44444444444444444444444444444444','venture_id'=>$venture,
        'brand_context_id'=>'brand:11111111111111111111111111111111','objective'=>'acquisition',
        'audience_ref'=>'audience:55555555555555555555555555555555','offer_ref'=>'offer:66666666666666666666666666666666',
        'cta_ref'=>'cta:77777777777777777777777777777777','channels'=>$channels,'creative_variant_refs'=>[],
        'budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','schedule'=>['start_at'=>1100,'end_at'=>2100],
        'experiment_refs'=>[],'status'=>'planned','evidence_refs'=>[],'execution'=>false];
}
function program424(string $class='marketing',string $venture='venture-condor'): array {
    return ['version'=>1,'email_program_id'=>'email-program:88888888888888888888888888888888','venture_id'=>$venture,
        'campaign_ref'=>'campaign:44444444444444444444444444444444','audience_ref'=>'audience:55555555555555555555555555555555',
        'message_class'=>$class,'execution'=>false];
}
function signal424(string $kind,string $status,string $freshness='current'): array {
    $hex=['consent'=>'99999999999999999999999999999999','suppression'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','unsubscribe'=>'cccccccccccccccccccccccccccccccc'][$kind];
    return ['state_ref'=>$kind.':'.$hex,'status'=>$status,'source_ref'=>'source:dddddddddddddddddddddddddddddddd',
        'observed_at'=>1200,'freshness'=>$freshness];
}
function state424(string $consent='granted',string $suppression='clear',string $unsubscribe='clear',string $freshness='current'): array {
    return ['version'=>1,'email_program_id'=>'email-program:88888888888888888888888888888888','venture_id'=>'venture-condor',
        'audience_ref'=>'audience:55555555555555555555555555555555','consent'=>signal424('consent',$consent,$freshness),
        'suppression'=>signal424('suppression',$suppression,$freshness),'unsubscribe'=>signal424('unsubscribe',$unsubscribe,$freshness)];
}
function blocked424(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    $noEmail=campaign424('venture-condor',['web']);
    echo json_encode(['program'=>MomentumEmail::program(program424(),campaign424(),brand424()),
        'cross_venture'=>blocked424(fn()=>MomentumEmail::program(program424('marketing','venture-brvtal'),campaign424(),brand424())),
        'channel_mismatch'=>blocked424(fn()=>MomentumEmail::program(program424(),$noEmail,brand424()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='eligibility'){
    $program=program424();
    $cases=['eligible'=>state424(),'denied'=>state424('denied'),'suppressed'=>state424('granted','active'),
        'unsubscribed'=>state424('granted','clear','active'),'stale'=>state424('granted','clear','clear','stale')];
    $out=[];foreach($cases as $key=>$state){$out[$key]=MomentumEmail::audienceState($state,$program,campaign424(),brand424());}
    $transactional=MomentumEmail::audienceState(state424(),program424('transactional'),campaign424(),brand424());
    echo json_encode(['cases'=>$out,'transactional'=>$transactional],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='audit'){
    $unknown=state424('unknown');$unknown['suppression']=signal424('suppression','unknown','unknown');
    echo json_encode(MomentumEmail::audienceState($unknown,program424(),campaign424(),brand424()),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='invalid'){
    $pii=program424();$pii['audience_ref']='person@example.com';
    $secret=state424();$secret['consent']['state_ref']='consent:token-supersecret';
    $url=state424();$url['consent']['source_ref']='https://example.com/evidence';
    $extra=state424();$extra['email']='person@example.com';
    echo json_encode(['pii'=>blocked424(fn()=>MomentumEmail::program($pii,campaign424(),brand424())),
        'secret'=>blocked424(fn()=>MomentumEmail::audienceState($secret,program424(),campaign424(),brand424())),
        'url'=>blocked424(fn()=>MomentumEmail::audienceState($url,program424(),campaign424(),brand424())),
        'extra'=>blocked424(fn()=>MomentumEmail::audienceState($extra,program424(),campaign424(),brand424()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $reflection=new ReflectionClass(MomentumEmail::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    echo json_encode(['methods'=>$methods,'state'=>MomentumEmail::audienceState(state424(),program424(),campaign424(),brand424())],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown MOMENTUM email scenario\n");exit(2);
