<?php
declare(strict_types=1);
require __DIR__.'/../src/MomentumRevenue.php';
use ControlBot\Momentum\MomentumRevenue;

function pipeline(string $venture='venture-condor',string $stage='opportunity'): array {
    return ['version'=>1,'pipeline_id'=>'pipeline:'.str_repeat('1',32),'venture_id'=>$venture,
        'lead_ref'=>'lead:'.str_repeat('2',32),
        'opportunity_ref'=>in_array($stage,['opportunity','proposal','won','lost'],true)?'opportunity:'.str_repeat('3',32):null,
        'source_ref'=>'source:'.str_repeat('4',32),'campaign_ref'=>'campaign:'.str_repeat('5',32),
        'creative_ref'=>'creative:'.str_repeat('6',32),'owner_ref'=>'owner:'.str_repeat('7',32),'stage'=>$stage,
        'qualification'=>$stage==='disqualified'?'disqualified':($stage==='lead'?'unknown':'qualified'),
        'next_action_ref'=>$stage==='won'?null:'action:'.str_repeat('8',32),'observed_at'=>1000,'freshness'=>'current',
        'customer_success_handoff_ref'=>$stage==='won'?'customer-success:'.str_repeat('9',32):null];
}
function forecast(string $venture='venture-condor'): array {
    return ['version'=>1,'forecast_id'=>'forecast:'.str_repeat('a',32),'venture_id'=>$venture,
        'opportunity_ref'=>'opportunity:'.str_repeat('3',32),'amount_minor'=>12500000,'currency'=>'COP','confidence'=>70,
        'source_ref'=>'source:'.str_repeat('b',32),'observed_at'=>1100,'freshness'=>'current'];
}
function attribution(string $classification='observed',?int $amount=12500000): array {
    return ['version'=>1,'attribution_id'=>'attribution:'.str_repeat('c',32),'venture_id'=>'venture-condor',
        'opportunity_ref'=>'opportunity:'.str_repeat('3',32),'campaign_ref'=>'campaign:'.str_repeat('5',32),
        'creative_ref'=>'creative:'.str_repeat('6',32),'classification'=>$classification,'amount_minor'=>$amount,
        'currency'=>'COP','source_ref'=>'source:'.str_repeat('d',32),
        'evidence_refs'=>$classification==='observed'?['evidence:'.str_repeat('e',32)]:[],'observed_at'=>1200,'freshness'=>'current'];
}
function signal(string $kind='renewal',string $classification='observed'): array {
    return ['version'=>1,'signal_id'=>'signal:'.str_repeat('f',32),'venture_id'=>'venture-condor',
        'opportunity_ref'=>'opportunity:'.str_repeat('3',32),'kind'=>$kind,'classification'=>$classification,
        'source_ref'=>'source:'.str_repeat('1',32),'observed_at'=>1300,'freshness'=>'current',
        'product_intelligence_ref'=>'product-intelligence:'.str_repeat('3',32),
        'customer_success_ref'=>'customer-success:'.str_repeat('5',32)];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    $p=MomentumRevenue::pipeline(pipeline()); $cross=signal(); $cross['venture_id']='venture-brvtal';
    echo json_encode(['pipeline'=>$p,'pipeline_campaign_ref'=>$p['campaign_ref'],'pipeline_creative_ref'=>$p['creative_ref'],
        'cross_forecast_rejected'=>blocked(fn()=>MomentumRevenue::forecast(forecast('venture-brvtal'),pipeline())),
        'cross_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal($cross,pipeline()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='funnel'){
    $bad=pipeline();$bad['stage']='closed'; $q=pipeline();$q['qualification']='unknown';
    $lead=pipeline('venture-condor','lead');$lead['lead_ref']='lead:JohnDoe'; $owner=pipeline();$owner['owner_ref']='owner:andres';
    $extra=pipeline();$extra['email']='person@example.com';
    echo json_encode(['lead'=>MomentumRevenue::pipeline(pipeline('venture-condor','lead')),
        'won'=>MomentumRevenue::pipeline(pipeline('venture-condor','won')),'bad_stage_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($bad)),
        'bad_qualification_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($q)),'pii_lead_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($lead)),
        'pii_owner_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($owner)),'extra_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($extra))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='forecast'){
    $confidence=forecast();$confidence['confidence']=101; $fresh=forecast();$fresh['freshness']='fresh';
    echo json_encode(['forecast'=>MomentumRevenue::forecast(forecast(),pipeline()),
        'bad_confidence_rejected'=>blocked(fn()=>MomentumRevenue::forecast($confidence,pipeline())),
        'bad_freshness_rejected'=>blocked(fn()=>MomentumRevenue::forecast($fresh,pipeline()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='attribution'){
    $won=pipeline('venture-condor','won'); $unknown=attribution('unknown',1); $noEvidence=attribution('observed',1);$noEvidence['evidence_refs']=[];
    $wrong=attribution('observed',1);$wrong['campaign_ref']='campaign:'.str_repeat('a',32);
    echo json_encode(['observed'=>MomentumRevenue::attribution(attribution(),$won),
        'inferred'=>MomentumRevenue::attribution(attribution('inferred',9000000),$won),
        'unknown'=>MomentumRevenue::attribution(attribution('unknown',null),$won),
        'unknown_claim_rejected'=>blocked(fn()=>MomentumRevenue::attribution($unknown,$won)),
        'observed_without_evidence_rejected'=>blocked(fn()=>MomentumRevenue::attribution($noEvidence,$won)),
        'pre_won_revenue_rejected'=>blocked(fn()=>MomentumRevenue::attribution(attribution(),pipeline())),
        'campaign_mismatch_rejected'=>blocked(fn()=>MomentumRevenue::attribution($wrong,$won))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='handoff'){
    $won=pipeline('venture-condor','won'); $premature=pipeline();$premature['customer_success_handoff_ref']='customer-success:'.str_repeat('9',32);
    $orphan=signal();$orphan['product_intelligence_ref']=null;$orphan['customer_success_ref']=null;
    echo json_encode(['won'=>MomentumRevenue::pipeline($won),'retention'=>MomentumRevenue::lifecycleSignal(signal(),$won),
        'churn'=>MomentumRevenue::lifecycleSignal(signal('churn','inferred'),$won),
        'premature_handoff_rejected'=>blocked(fn()=>MomentumRevenue::pipeline($premature)),
        'orphan_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal($orphan,$won)),
        'pre_won_signal_rejected'=>blocked(fn()=>MomentumRevenue::lifecycleSignal(signal(),pipeline()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $r=new ReflectionClass(MomentumRevenue::class);$m=array_map(static fn(ReflectionMethod $x):string=>$x->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($m,SORT_STRING);echo json_encode(['methods'=>$m],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown MOMENTUM revenue scenario\n");exit(2);
