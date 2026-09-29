<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumCampaign.php';

use ControlBot\Momentum\MomentumCampaign;

function brand(string $venture='venture-condor'): array {
    return [
        'version'=>1,'brand_context_id'=>'brand-condor','venture_id'=>$venture,
        'tone_ref'=>'brand-tone-v1','constraints'=>['brand-safe','no-dark-patterns'],
        'source_ref'=>'controlbot:venture-brand','observed_at'=>1000,
    ];
}
function campaign(string $venture='venture-condor'): array {
    return [
        'version'=>1,'campaign_id'=>'campaign-launch-1','venture_id'=>$venture,
        'brand_context_id'=>'brand-condor','objective'=>'acquisition',
        'audience_ref'=>'audience-wholesale-v1','offer_ref'=>'offer-condor-v1','cta_ref'=>'cta-demo-v1',
        'channels'=>['web','email','paid_social'],'creative_variant_refs'=>['creative-b','creative-a'],
        'budget_ref'=>'capital:budget-2026-q4','schedule'=>['start_at'=>1100,'end_at'=>2100],
        'experiment_refs'=>['experiment-copy-a-b'],'status'=>'planned',
        'evidence_refs'=>['evidence:brief-1'],'execution'=>false,
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
    $email=campaign(); $email['audience_ref']='owner@example.com';
    $secret=campaign(); $secret['budget_ref']='token:supersecret';
    echo json_encode([
        'email_rejected'=>blocked(fn()=>MomentumCampaign::campaign($email,brand())),
        'secret_rejected'=>blocked(fn()=>MomentumCampaign::campaign($secret,brand())),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='closed'){
    $duplicate=campaign(); $duplicate['channels']=['email','email'];
    $extra=campaign(); $extra['provider']='meta';
    $badSchedule=campaign(); $badSchedule['schedule']=['start_at'=>2000,'end_at'=>1000];
    $active=campaign(); $active['status']='active'; $active['schedule']=['start_at'=>null,'end_at'=>null];
    echo json_encode([
        'canonical'=>MomentumCampaign::campaign(campaign(),brand()),
        'duplicate_rejected'=>blocked(fn()=>MomentumCampaign::campaign($duplicate,brand())),
        'extra_rejected'=>blocked(fn()=>MomentumCampaign::campaign($extra,brand())),
        'schedule_rejected'=>blocked(fn()=>MomentumCampaign::campaign($badSchedule,brand())),
        'active_without_start_rejected'=>blocked(fn()=>MomentumCampaign::campaign($active,brand())),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='pure'){
    $reflection=new ReflectionClass(MomentumCampaign::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    echo json_encode(['methods'=>$methods,'campaign'=>MomentumCampaign::campaign(campaign(),brand())],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown MOMENTUM campaign scenario\n"); exit(2);
