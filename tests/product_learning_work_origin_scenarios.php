<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductIntelligence.php';
require __DIR__.'/../src/ProductExperimentOutcome.php';
require __DIR__.'/../src/ProductLearningSignal.php';
require __DIR__.'/../src/ProductLearningWorkOrigin.php';

use ControlBot\Business\ProductLearningWorkOrigin;

function woReject(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function woMetric(string $id,int|float|null $value,array $o=[]): array {
    return array_replace([
        'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'surface'=>'web_app','category'=>'activation','period'=>['start_at'=>1000,'end_at'=>2000],
        'status'=>$value===null?'unknown':'measured','value'=>$value,'unit'=>'ratio',
        'source_ref'=>'aggregate:analytics/product','evidence_ref'=>'evidence:product/metric',
        'sample_size'=>$value===null?0:100,'freshness'=>$value===null?'unknown':'fresh',
        'confidence'=>$value===null?0.0:0.9,'nature'=>'observed',
    ],$o);
}
function woSignal(string $type,array $targets=['discovery']): array {
    return ['version'=>1,'signal_id'=>'signal-work-origin','type'=>$type,'targets'=>$targets];
}
function woIntent(array $o=[]): array {
    return array_replace([
        'version'=>1,'work_id'=>'work-product-learning','group_id'=>'pl0n3r-group','work_type'=>'product',
        'requested_capabilities'=>['product_analysis'],'required_roles'=>['producto','qa'],
        'authority_level'=>'operational','priority_class'=>'high','depends_on'=>[],'claims'=>[],
        'policy_ref'=>'factory:constitution-v1','observed_at'=>'2026-09-29T09:00:00-05:00',
    ],$o);
}
function woOutcome(): array {
    return [
        'version'=>1,'outcome_id'=>'outcome-work-origin',
        'experiment_ref'=>'experiment:0123456789abcdef0123456789abcdef',
        'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'evaluation_window'=>['start_at'=>900,'end_at'=>2100],
        'source_ref'=>'aggregate:analytics/experiment','evidence_ref'=>'evidence:product/experiment',
    ];
}
function woFromMetric(array $intent,array $signal,array $metric): array {
    return ProductLearningWorkOrigin::fromMetric($intent,$signal,$metric,'venture-condor','product-condor');
}
function woFromExperiment(array $intent,array $signal,array $base,array $variant): array {
    return ProductLearningWorkOrigin::fromExperimentOutcome($intent,$signal,woOutcome(),$base,$variant,'venture-condor','product-condor');
}

$case=$argv[1]??'';
if($case==='scope'){
    $out=[
        'metric'=>woFromMetric(woIntent(),woSignal('observed_change'),woMetric('metric-work',0.4)),
        'experiment'=>woFromExperiment(woIntent(['work_id'=>'work-experiment']),woSignal('experiment_result'),woMetric('metric-base',0.4),woMetric('metric-variant',0.5)),
    ];
}elseif($case==='explicit'){
    $first=woFromMetric(woIntent(),woSignal('observed_change'),woMetric('metric-work',0.4));
    $second=woFromMetric(woIntent([
        'work_id'=>'work-other','work_type'=>'data_analytics','requested_capabilities'=>['analysis'],
        'required_roles'=>['datos-analitica'],'authority_level'=>'owner','priority_class'=>'medium',
        'policy_ref'=>'factory:policy-product',
    ]),woSignal('observed_change'),woMetric('metric-work',0.4));
    $out=['first'=>$first,'second'=>$second];
}elseif($case==='idempotency'){
    $a=woFromMetric(woIntent(),woSignal('observed_change',['momentum','discovery']),woMetric('metric-work',0.4));
    $b=woFromMetric(woIntent(['work_id'=>'work-second']),woSignal('observed_change',['discovery','momentum']),woMetric('metric-work',0.4));
    $out=['a'=>$a,'b'=>$b];
}elseif($case==='limited'){
    $unknown=woFromMetric(woIntent(),woSignal('evidence_gap'),woMetric('metric-gap',null));
    $stale=woFromMetric(woIntent(['work_id'=>'work-stale']),woSignal('observed_change'),woMetric('metric-stale',0.2,['freshness'=>'stale']));
    $missing=woIntent(); unset($missing['observed_at']);
    $out=['unknown'=>$unknown,'stale'=>$stale,'missing_time'=>woReject(fn()=>woFromMetric($missing,woSignal('observed_change'),woMetric('metric-work',0.4)))];
}elseif($case==='optional'){
    $intent=woIntent([
        'project_id'=>'controlbot','repository_ref'=>'pl0n3r/ControlBot',
        'budget_ref'=>'capital:product','approval_ref'=>'owner-decision:260',
    ]);
    $good=woFromMetric($intent,woSignal('observed_change'),woMetric('metric-work',0.4));
    $bad=$intent; $bad['executor']='runner-1';
    $out=['good'=>$good,'bad_execution_field'=>woReject(fn()=>woFromMetric($bad,woSignal('observed_change'),woMetric('metric-work',0.4)))];
}elseif($case==='pure'){
    $r=new ReflectionClass(ProductLearningWorkOrigin::class);
    $methods=array_values(array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===ProductLearningWorkOrigin::class
    )));
    sort($methods,SORT_STRING); $out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown work origin scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
