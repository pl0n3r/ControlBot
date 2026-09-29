<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumRevenue.php';

use ControlBot\Momentum\MomentumRevenue;

function pipeline(string $venture='venture-condor',string $stage='opportunity'): array {
    $won=$stage==='won';
    $qualification=$stage==='disqualified'?'disqualified':($stage==='lead'?'unknown':'qualified');
    return [
        'version'=>1,
        'pipeline_id'=>'pipeline:11111111111111111111111111111111',
        'venture_id'=>$venture,
        'lead_ref'=>'lead:22222222222222222222222222222222',
        'opportunity_ref'=>in_array($stage,['opportunity','proposal','won','lost'],true)?'opportunity:33333333333333333333333333333333':null,
        'source_ref'=>'source:44444444444444444444444444444444',
        'campaign_ref'=>'campaign:55555555555555555555555555555555',
        'creative_ref'=>'creative:66666666666666666666666666666666',
        'owner_ref'=>'owner:77777777777777777777777777777777',
        'stage'=>$stage,
        'qualification'=>$qualification,
        'next_action_ref'=>$won?null:'action:88888888888888888888888888888888',
        'observed_at'=>1000,
        'freshness'=>'current',
        'customer_success_handoff_ref'=>$won?'customer-success:99999999999999999999999999999999':null,
    ];
}
function forecast(string $venture='venture-condor'): array {
    return [
        'version'=>1,'forecast_id'=>'forecast:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','venture_id'=>$venture,
        'opportunity_ref'=>'opportunity:33333333333333333333333333333333','amount_minor'=>12500000,
        'currency'=>'COP','confidence'=>70,'source_ref'=>'source:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'observed_at'=>1100,'freshness'=>'current',
    ];
}
function attribution(string $classification='observed',?int $amount=12500000): array {
    return [
        'version'=>1,'attribution_id'=>'attribution:cccccccccccccccccccccccccccccccc','venture_id'=>'venture-condor',
        'opportunity_ref'=>'opportunity:33333333333333333333333333333333',
        'campaign_ref'=>'campaign:55555555555555555555555555555555','creative_ref'=>'creative:66666666666666666666666666666666',
        'classification'=>$classification,'amount_minor'=>$amount,'currency'=>'COP',
        'source_ref'=>'source:dddddddddddddddddddddddddddddddd',
        'evidence_refs'=>$classification==='observed'?['evidence:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee']:[],
        'observed_at'=>1200,'freshness'=>'current',
    ];
}
function signal(string $kind='renewal',string $classification='observed'): array {
    return [
        'version'=>1,'signal_id'=>'signal:ffffffffffffffffffffffffffffffff','venture_id'=>'venture-condor',
        'opportunity_ref'=>'opportunity:33333333333333333333333333333333','kind'=>$kind,'classification'=>$classification,
        'source_ref'=>'source:12121212121212121212121212121212','observed_at'=>1300,'freshness'=>'current',
        'product_intelligence_ref'=>'product-intelligence:34343434343434343434343434343434',
        'customer_success_ref'=>'customer-success:56565656565656565656565656565656',
    ];
}
function blocked(callable $fn): bool {
    try { $fn(); return false; }
    catch (InvalidArgumentException) { return true; }
}

$case=$argv[1]??'';
if($case==='scope'){
    $canonical=MomentumRevenue::pipeline(pipeline());
    $crossForecast=forecast('venture-brvtal');
    $crossSignal=signal();$crossSignal['venture_id']='venture-brvtal';
    echo json_encode([
        'pipeline'=>$canonical,
        'pipeline_campaign_ref'=>$canonical['campaign_ref'],
        'pipeline_creative_ref'=>$canonical['creative_ref'],
        'cross_forecast_rejected'=>blocked(fn()=>MomentumRevenue::forecast($crossForecast,pipeline())),
        'cross_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal($crossSignal,pipeline())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='funnel'){
    $lead=MomentumRevenue::pipeline(pipeline('venture-condor','lead'));
    $won=MomentumRevenue::pipeline(pipeline('venture-condor','won'));
    $badStage=pipeline();$badStage['stage']='closed';
    $badQualification=pipeline();$badQualification['qualification']='unknown';
    $piiLead=pipeline();$piiLead['lead_ref']='lead:JohnDoe';
    $piiOwner=pipeline();$piiOwner['owner_ref']='owner:andres';
    $extra=pipeline();$extra['email']='person@example.com';
    echo json_encode([
        'lead'=>$lead,'won'=>$won,
        'bad_stage_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($badStage)),
        'bad_qualification_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($badQualification)),
        'pii_lead_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($piiLead)),
        'pii_owner_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($piiOwner)),
        'extra_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($extra)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='forecast'){
    $row=MomentumRevenue::forecast(forecast(),pipeline());
    $badConfidence=forecast();$badConfidence['confidence']=101;
    $badFreshness=forecast();$badFreshness['freshness']='fresh';
    echo json_encode([
        'forecast'=>$row,
        'bad_confidence_rejected'=>blocked(fn()=>MomentumRevenue::forecast($badConfidence,pipeline())),
        'bad_freshness_rejected'=>blocked(fn()=>MomentumRevenue::forecast($badFreshness,pipeline())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='attribution'){
    $wonPipeline=pipeline('venture-condor','won');
    $observed=MomentumRevenue::attribution(attribution('observed',12500000),$wonPipeline);
    $inferred=MomentumRevenue::attribution(attribution('inferred',9000000),$wonPipeline);
    $unknown=MomentumRevenue::attribution(attribution('unknown',null),$wonPipeline);
    $unknownClaim=attribution('unknown',1);
    $observedNoEvidence=attribution('observed',1);$observedNoEvidence['evidence_refs']=[];
    $wrongCampaign=attribution('observed',1);$wrongCampaign['campaign_ref']='campaign:abababababababababababababababab';
    echo json_encode([
        'observed'=>$observed,'inferred'=>$inferred,'unknown'=>$unknown,
        'unknown_claim_rejected'=>blocked(fn()=>MomentumRevenue::attribution($unknownClaim,$wonPipeline)),
        'observed_without_evidence_rejected'=>blocked(fn()=>MomentumRevenue::attribution($observedNoEvidence,$wonPipeline)),
        'pre_won_revenue_rejected'=>blocked(fn()=>MomentumRevenue::attribution(attribution('observed',1),pipeline())),
        'campaign_mismatch_rejected'=>blocked(fn()=>MomentumRevenue::attribution($wrongCampaign,$wonPipeline)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='handoff'){
    $won=MomentumRevenue::pipeline(pipeline('venture-condor','won'));
    $premature=pipeline();$premature['customer_success_handoff_ref']='customer-success:99999999999999999999999999999999';
    $wonPipeline=pipeline('venture-condor','won');
    $retention=MomentumRevenue::lifecycleSignal(signal('renewal','observed'),$wonPipeline);
    $churn=MomentumRevenue::lifecycleSignal(signal('churn','inferred'),$wonPipeline);
    $orphan=signal();$orphan['product_intelligence_ref']=null;$orphan['customer_success_ref']=null;
    echo json_encode([
        'won'=>$won,'retention'=>$retention,'churn'=>$churn,
        'premature_handoff_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($premature)),
        'orphan_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal($orphan,$wonPipeline)),
        'pre_won_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal(signal('renewal','observed'),pipeline())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $reflection=new ReflectionClass(MomentumRevenue::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods,SORT_STRING);
    echo json_encode(['methods'=>$methods],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown MOMENTUM revenue scenario\n");exit(2);
