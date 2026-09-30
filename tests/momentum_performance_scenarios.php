<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumRevenue.php';
require __DIR__.'/../src/MomentumPerformance.php';

use ControlBot\Momentum\MomentumPerformance;

function ref(string $namespace,string $char): string { return $namespace.':'.str_repeat($char,32); }

function pipeline(string $venture='venture-condor',string $campaignChar='5'): array {
    return [
        'version'=>1,'pipeline_id'=>ref('pipeline','1'),'venture_id'=>$venture,'lead_ref'=>ref('lead','2'),
        'opportunity_ref'=>ref('opportunity','3'),'source_ref'=>ref('source','4'),'campaign_ref'=>ref('campaign',$campaignChar),
        'creative_ref'=>ref('creative','6'),'owner_ref'=>ref('owner','7'),'stage'=>'won','qualification'=>'qualified',
        'next_action_ref'=>null,'observed_at'=>1200,'freshness'=>'current','customer_success_handoff_ref'=>ref('customer-success','9'),
    ];
}

function attribution(string $classification='observed',?int $amount=12500000,string $currency='COP',string $campaignChar='5'): array {
    return [
        'version'=>1,'attribution_id'=>ref('attribution','a'),'venture_id'=>'venture-condor',
        'opportunity_ref'=>ref('opportunity','3'),'campaign_ref'=>ref('campaign',$campaignChar),'creative_ref'=>ref('creative','6'),
        'classification'=>$classification,'amount_minor'=>$amount,'currency'=>$currency,'source_ref'=>ref('source','b'),
        'evidence_refs'=>$classification==='unknown'?[]:[ref('evidence','c')],'observed_at'=>1500,'freshness'=>'current',
    ];
}

function raw(string $spendClass='observed',string $funnelClass='observed',string $costClass='observed'): array {
    return [
        'version'=>1,'performance_id'=>ref('performance','d'),'venture_id'=>'venture-condor','campaign_ref'=>ref('campaign','5'),
        'paid_media_ref'=>ref('paid-media','e'),'period'=>['start_at'=>1000,'end_at'=>2000],'currency'=>'COP',
        'spend'=>[
            'classification'=>$spendClass,'amount_minor'=>$spendClass==='unknown'?null:1000000,'currency'=>'COP',
            'source_ref'=>ref('source','1'),'evidence_refs'=>$spendClass==='unknown'?[]:[ref('evidence','1')],
            'observed_at'=>1300,'freshness'=>'current',
        ],
        'funnel'=>[
            'classification'=>$funnelClass,'leads'=>$funnelClass==='unknown'?null:100,'conversions'=>$funnelClass==='unknown'?null:10,
            'source_ref'=>ref('source','2'),'evidence_refs'=>$funnelClass==='unknown'?[]:[ref('evidence','2')],
            'observed_at'=>1400,'freshness'=>'current',
        ],
        'cost'=>[
            'classification'=>$costClass,'amount_minor'=>$costClass==='unknown'?null:2000000,'currency'=>'COP',
            'source_ref'=>ref('source','3'),'evidence_refs'=>$costClass==='unknown'?[]:[ref('evidence','3')],
            'observed_at'=>1450,'freshness'=>'current',
        ],
        'source_ref'=>ref('source','f'),'evidence_refs'=>[ref('evidence','4'),ref('evidence','5')],
        'observed_at'=>1600,'freshness'=>'current','execution'=>false,
    ];
}

function blocked(callable $fn): bool {
    try { $fn(); return false; }
    catch(InvalidArgumentException) { return true; }
}

$case=$argv[1]??'';

if($case==='scope'){
    $base=raw();
    $cross=$base;$cross['venture_id']='venture-brvtal';
    $campaign=$base;$campaign['campaign_ref']=ref('campaign','8');
    $period=$base;$period['period']=['start_at'=>1500,'end_at'=>1700];
    echo json_encode([
        'performance'=>MomentumPerformance::project($base,pipeline(),attribution()),
        'cross_venture_rejected'=>blocked(fn()=>MomentumPerformance::project($cross,pipeline(),attribution())),
        'campaign_mismatch_rejected'=>blocked(fn()=>MomentumPerformance::project($campaign,pipeline(),attribution())),
        'period_mismatch_rejected'=>blocked(fn()=>MomentumPerformance::project($period,pipeline(),attribution())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='chain'){
    $full=MomentumPerformance::project(raw(),pipeline(),attribution());
    $unknownSpend=raw('unknown');
    echo json_encode([
        'full'=>$full,
        'unknown_spend'=>MomentumPerformance::project($unknownSpend,pipeline(),attribution()),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='classification'){
    echo json_encode([
        'observed'=>MomentumPerformance::project(raw(),pipeline(),attribution('observed',12500000)),
        'inferred'=>MomentumPerformance::project(raw(),pipeline(),attribution('inferred',9000000)),
        'unknown'=>MomentumPerformance::project(raw(),pipeline(),attribution('unknown',null)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='unit'){
    $zero=raw();$zero['funnel']['conversions']=0;
    $noCost=raw('observed','observed','unknown');
    $noSpend=raw('unknown');
    $full=MomentumPerformance::project(raw(),pipeline(),attribution());
    echo json_encode([
        'full'=>$full,
        'zero_conversions'=>MomentumPerformance::project($zero,pipeline(),attribution()),
        'no_cost'=>MomentumPerformance::project($noCost,pipeline(),attribution()),
        'no_spend'=>MomentumPerformance::project($noSpend,pipeline(),attribution()),
        'inferred_revenue'=>MomentumPerformance::project(raw(),pipeline(),attribution('inferred',9000000)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='guardrails'){
    $currency=raw();$currency['spend']['currency']='USD';
    $attributionCurrency=attribution();$attributionCurrency['currency']='USD';
    $outside=raw();$outside['spend']['observed_at']=999;
    $duplicate=raw();$duplicate['evidence_refs']=[ref('evidence','4'),ref('evidence','4')];
    $missingEvidence=raw();$missingEvidence['spend']['evidence_refs']=[];
    $funnel=raw();$funnel['funnel']['conversions']=101;
    $extra=raw();$extra['email']='person@example.com';
    $stale=raw();$stale['spend']['freshness']='stale';
    echo json_encode([
        'currency_mismatch_rejected'=>blocked(fn()=>MomentumPerformance::project($currency,pipeline(),attribution())),
        'attribution_currency_rejected'=>blocked(fn()=>MomentumPerformance::project(raw(),pipeline(),$attributionCurrency)),
        'outside_period_rejected'=>blocked(fn()=>MomentumPerformance::project($outside,pipeline(),attribution())),
        'duplicate_evidence_rejected'=>blocked(fn()=>MomentumPerformance::project($duplicate,pipeline(),attribution())),
        'missing_observed_evidence_rejected'=>blocked(fn()=>MomentumPerformance::project($missingEvidence,pipeline(),attribution())),
        'invalid_funnel_rejected'=>blocked(fn()=>MomentumPerformance::project($funnel,pipeline(),attribution())),
        'extra_field_rejected'=>blocked(fn()=>MomentumPerformance::project($extra,pipeline(),attribution())),
        'stale'=>MomentumPerformance::project($stale,pipeline(),attribution()),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

if($case==='pure'){
    $reflection=new ReflectionClass(MomentumPerformance::class);
    $methods=array_map(static fn(ReflectionMethod $method): string=>$method->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods,SORT_STRING);
    echo json_encode(['methods'=>$methods],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}

fwrite(STDERR,"Unknown MOMENTUM performance scenario\n");exit(2);
