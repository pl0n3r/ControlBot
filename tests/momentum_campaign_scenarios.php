<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumCampaign.php';

use ControlBot\Momentum\MomentumCampaign;

function brand(string $venture='venture-condor'): array {
    return [
        'version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111','venture_id'=>$venture,
        'tone_ref'=>'tone:22222222222222222222222222222222','constraints'=>['brand-safe','no-dark-patterns'],
        'source_ref'=>'source:33333333333333333333333333333333','observed_at'=>1000,
    ];
}
function campaign(string $venture='venture-condor'): array {
    return [
        'version'=>1,'campaign_id'=>'campaign:44444444444444444444444444444444','venture_id'=>$venture,
        'brand_context_id'=>'brand:11111111111111111111111111111111','objective'=>'acquisition',
        'audience_ref'=>'audience:55555555555555555555555555555555','offer_ref'=>'offer:66666666666666666666666666666666','cta_ref'=>'cta:77777777777777777777777777777777',
        'channels'=>['web','e'.'mail','paid_social'],'creative_variant_refs'=>['creative:99999999999999999999999999999999','creative:88888888888888888888888888888888'],
        'budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','schedule'=>['start_at'=>1100,'end_at'=>2100],
        'experiment_refs'=>['experiment:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],'status'=>'planned',
        'evidence_refs'=>['evidence:cccccccccccccccccccccccccccccccc'],'execution'=>false,
    ];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    echo json_encode([
        'brand'=>MomentumCampaign::brandContext(brand()),
        'campaign'=>MomentumCampaign::campaign(campaign(),brand()),
        'cross_venture'=>blocked(fn()=>MomentumCampaign::campaign(campaign('venture-brvtal'),brand())),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='neutral'){
    $row=MomentumCampaign::campaign(campaign(),brand());
    echo json_encode(['campaign'=>$row],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='privacy'){
    $humanSlug=campaign(); $humanSlug['audience_ref']='John_Doe';
    $phoneLike=campaign(); $phoneLike['audience_ref']='573001234567';
    $wrongNamespace=campaign(); $wrongNamespace['audience_ref']='contact:55555555555555555555555555555555';
    $namespacedName=campaign(); $namespacedName['audience_ref']='audience:JohnDoe';
    $namespacedPhone=campaign(); $namespacedPhone['audience_ref']='audience:573001234567';
    $secret=campaign(); $secret['budget_ref']='budget:token-supersecret';
    echo json_encode([
        'human_slug_rejected'=>blocked(fn()=>MomentumCampaign::campaign($humanSlug,brand())),
        'phone_like_rejected'=>blocked(fn()=>MomentumCampaign::campaign($phoneLike,brand())),
        'wrong_namespace_rejected'=>blocked(fn()=>MomentumCampaign::campaign($wrongNamespace,brand())),
        'namespaced_name_rejected'=>blocked(fn()=>MomentumCampaign::campaign($namespacedName,brand())),
        'namespaced_phone_rejected'=>blocked(fn()=>MomentumCampaign::campaign($namespacedPhone,brand())),
        'secret_rejected'=>blocked(fn()=>MomentumCampaign::campaign($secret,brand())),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='closed'){
    $duplicate=campaign(); $duplicate['channels']=['e'.'mail','e'.'mail'];
    $extra=campaign(); $extra['provider']='meta';
    $badSchedule=campaign(); $badSchedule['schedule']=['start_at'=>2000,'end_at'=>1000];
    $active=campaign(); $active['status']='active'; $active['schedule']=['start_at'=>null,'end_at'=>null];
    $completedMissingStart=campaign(); $completedMissingStart['status']='completed'; $completedMissingStart['schedule']=['start_at'=>null,'end_at'=>2100];
    echo json_encode([
        'canonical'=>MomentumCampaign::campaign(campaign(),brand()),
        'duplicate_rejected'=>blocked(fn()=>MomentumCampaign::campaign($duplicate,brand())),
        'extra_rejected'=>blocked(fn()=>MomentumCampaign::campaign($extra,brand())),
        'schedule_rejected'=>blocked(fn()=>MomentumCampaign::campaign($badSchedule,brand())),
        'active_without_start_rejected'=>blocked(fn()=>MomentumCampaign::campaign($active,brand())),
        'completed_without_start_rejected'=>blocked(fn()=>MomentumCampaign::campaign($completedMissingStart,brand())),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='pure'){
    $reflection=new ReflectionClass(MomentumCampaign::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    echo json_encode(['methods'=>$methods,'campaign'=>MomentumCampaign::campaign(campaign(),brand())],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown MOMENTUM campaign scenario\n"); exit(2);
