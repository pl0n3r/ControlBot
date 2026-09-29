<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductIntelligence.php';
use ControlBot\Business\ProductIntelligence;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function metric(array $overrides=[]): array { return array_replace([
    'version'=>1,'metric_id'=>'metric-activation-web','venture_id'=>'venture-condor','product_id'=>'product-condor',
    'surface'=>'web_app','category'=>'activation','period'=>['start_at'=>1000,'end_at'=>2000],
    'status'=>'measured','value'=>0.42,'unit'=>'ratio','source_ref'=>'aggregate:analytics/activation',
    'evidence_ref'=>'evidence:product/activation-2026w40','sample_size'=>120,'freshness'=>'fresh','confidence'=>0.96,'nature'=>'observed',
],$overrides); }
function funnel(array $overrides=[]): array { return array_replace([
    'version'=>1,'funnel_id'=>'funnel-onboarding','venture_id'=>'venture-condor','product_id'=>'product-condor','surface'=>'web_app',
    'period'=>['start_at'=>1000,'end_at'=>2000],'stages'=>[['name'=>'visited','count'=>200],['name'=>'activated','count'=>84]],
    'source_ref'=>'aggregate:analytics/onboarding','evidence_ref'=>'evidence:product/funnel-2026w40','freshness'=>'fresh','confidence'=>0.94,'nature'=>'observed',
],$overrides); }
function cohort(array $overrides=[]): array { return array_replace([
    'version'=>1,'cohort_id'=>'cohort-week40','venture_id'=>'venture-condor','product_id'=>'product-condor','surface'=>'web_app',
    'period'=>['start_at'=>1000,'end_at'=>2000],'cohort_key'=>'signup_week_40','sample_size'=>80,'retained_count'=>46,
    'source_ref'=>'aggregate:analytics/retention','evidence_ref'=>'evidence:product/cohort-2026w40','freshness'=>'fresh','confidence'=>0.91,'nature'=>'observed',
],$overrides); }

$case=$argv[1]??'';
if($case==='scope'){
    $out=[
        'metric'=>ProductIntelligence::metric(metric(),'venture-condor','product-condor'),
        'wrong_venture'=>rejected(fn()=>ProductIntelligence::metric(metric(),'venture-brvtal','product-condor')),
        'wrong_product'=>rejected(fn()=>ProductIntelligence::metric(metric(),'venture-condor','product-other')),
        'bad_period'=>rejected(fn()=>ProductIntelligence::metric(metric(['period'=>['start_at'=>2000,'end_at'=>1000]]),'venture-condor','product-condor')),
    ];
}elseif($case==='provenance'){
    $out=[
        'inferred'=>ProductIntelligence::metric(metric(['metric_id'=>'metric-churn-risk','category'=>'churn_signal','nature'=>'inferred','confidence'=>0.61]),'venture-condor','product-condor'),
        'zero_sample'=>rejected(fn()=>ProductIntelligence::metric(metric(['sample_size'=>0]),'venture-condor','product-condor')),
        'bad_confidence'=>rejected(fn()=>ProductIntelligence::metric(metric(['confidence'=>1.2]),'venture-condor','product-condor')),
        'bad_freshness'=>rejected(fn()=>ProductIntelligence::metric(metric(['freshness'=>'healthy']),'venture-condor','product-condor')),
        'sensitive_ref'=>rejected(fn()=>ProductIntelligence::metric(metric(['source_ref'=>'aggregate:analytics/user_id']), 'venture-condor','product-condor')),
    ];
}elseif($case==='unknown'){
    $unknown=ProductIntelligence::metric(metric(['metric_id'=>'metric-unknown','status'=>'unknown','value'=>null,'sample_size'=>0,'freshness'=>'unknown','confidence'=>0.0]),'venture-condor','product-condor');
    $insufficient=ProductIntelligence::metric(metric(['metric_id'=>'metric-small','status'=>'insufficient_data','value'=>null,'sample_size'=>3,'confidence'=>0.2]),'venture-condor','product-condor');
    $out=[
        'unknown'=>$unknown,'insufficient'=>$insufficient,
        'unknown_with_value'=>rejected(fn()=>ProductIntelligence::metric(metric(['status'=>'unknown']), 'venture-condor','product-condor')),
        'insufficient_zero'=>rejected(fn()=>ProductIntelligence::metric(metric(['status'=>'insufficient_data','value'=>null,'sample_size'=>0]),'venture-condor','product-condor')),
    ];
}elseif($case==='aggregate'){
    $badFunnel=funnel(['stages'=>[['name'=>'visited','count'=>100],['name'=>'activated','count'=>120]]]);
    $extra=funnel();$extra['members']=['user-1'];
    $cohortExtra=cohort();$cohortExtra['member_ids']=['abc'];
    $out=[
        'funnel'=>ProductIntelligence::funnel(funnel(),'venture-condor','product-condor'),
        'cohort'=>ProductIntelligence::cohort(cohort(),'venture-condor','product-condor'),
        'increasing'=>rejected(fn()=>ProductIntelligence::funnel($badFunnel,'venture-condor','product-condor')),
        'member_list'=>rejected(fn()=>ProductIntelligence::funnel($extra,'venture-condor','product-condor')),
        'cohort_members'=>rejected(fn()=>ProductIntelligence::cohort($cohortExtra,'venture-condor','product-condor')),
        'retained_over_sample'=>rejected(fn()=>ProductIntelligence::cohort(cohort(['retained_count'=>81]),'venture-condor','product-condor')),
    ];
}elseif($case==='revenue'){
    $good=metric(['metric_id'=>'metric-revenue-outcome','category'=>'revenue_outcome','value'=>1250000,'unit'=>'cop','source_ref'=>'aggregate:finance/revenue-outcome']);
    $bad=metric(['metric_id'=>'metric-revenue-bad','category'=>'revenue_outcome','source_ref'=>'aggregate:analytics/revenue']);
    $customer=metric(['metric_id'=>'metric-revenue-customer','category'=>'revenue_outcome','source_ref'=>'aggregate:finance/customer_id']);
    $out=['good'=>ProductIntelligence::metric($good,'venture-condor','product-condor'),'bad_source'=>rejected(fn()=>ProductIntelligence::metric($bad,'venture-condor','product-condor')),'customer_ref'=>rejected(fn()=>ProductIntelligence::metric($customer,'venture-condor','product-condor'))];
}elseif($case==='pure'){
    $r=new ReflectionClass(ProductIntelligence::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC));
    $out=['methods'=>$methods,'metric'=>ProductIntelligence::metric(metric(),'venture-condor','product-condor')];
}else{fwrite(STDERR,"Unknown product intelligence scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
