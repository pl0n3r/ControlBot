<?php
declare(strict_types=1);

require __DIR__.'/../src/ProductIntelligence.php';
require __DIR__.'/../src/ProductExperimentOutcome.php';
require __DIR__.'/../src/ProductLearningSignal.php';

use ControlBot\Business\ProductLearningSignal;

function learningRejected(callable $fn): bool
{
    try{$fn();return false;}catch(InvalidArgumentException){return true;}
}

function learningMetric(string $id,int|float|null $value,array $overrides=[]): array
{
    return array_replace([
        'version'=>1,
        'metric_id'=>$id,
        'venture_id'=>'venture-condor',
        'product_id'=>'product-condor',
        'surface'=>'web_app',
        'category'=>'activation',
        'period'=>['start_at'=>1000,'end_at'=>2000],
        'status'=>$value===null?'unknown':'measured',
        'value'=>$value,
        'unit'=>'ratio',
        'source_ref'=>'aggregate:analytics/product',
        'evidence_ref'=>'evidence:product/metric',
        'sample_size'=>$value===null?0:100,
        'freshness'=>$value===null?'unknown':'fresh',
        'confidence'=>$value===null?0.0:0.9,
        'nature'=>'observed',
    ],$overrides);
}

function learningSignal(string $type,array $targets=['discovery'],array $overrides=[]): array
{
    return array_replace([
        'version'=>1,
        'signal_id'=>'signal-product-learning',
        'type'=>$type,
        'targets'=>$targets,
    ],$overrides);
}

function learningOutcomeRaw(array $overrides=[]): array
{
    return array_replace([
        'version'=>1,
        'outcome_id'=>'outcome-learning-test',
        'experiment_ref'=>'experiment:0123456789abcdef0123456789abcdef',
        'venture_id'=>'venture-condor',
        'product_id'=>'product-condor',
        'evaluation_window'=>['start_at'=>900,'end_at'=>2100],
        'source_ref'=>'aggregate:analytics/experiment',
        'evidence_ref'=>'evidence:product/experiment',
    ],$overrides);
}

function fromMetricSignal(array $signal,array $metric): array
{
    return ProductLearningSignal::fromMetric($signal,$metric,'venture-condor','product-condor');
}

function fromExperimentSignal(array $signal,array $outcome,array $baseline,array $variant): array
{
    return ProductLearningSignal::fromExperimentOutcome(
        $signal,$outcome,$baseline,$variant,'venture-condor','product-condor'
    );
}

$case=$argv[1]??'';
if($case==='scope'){
    $metric=learningMetric('metric-activation',0.42);
    $good=fromMetricSignal(learningSignal('observed_change',['momentum','discovery']),$metric);
    $out=[
        'good'=>$good,
        'wrong_scope'=>learningRejected(fn()=>ProductLearningSignal::fromMetric(
            learningSignal('observed_change'),
            array_replace($metric,['venture_id'=>'venture-other']),
            'venture-condor','product-condor'
        )),
    ];
}elseif($case==='closed'){
    $good=fromMetricSignal(
        learningSignal('observed_change',['momentum','capital','discovery']),
        learningMetric('metric-activation',0.42)
    );
    $out=[
        'good'=>$good,
        'duplicate'=>learningRejected(fn()=>fromMetricSignal(
            learningSignal('observed_change',['discovery','discovery']),
            learningMetric('metric-activation',0.42)
        )),
        'bad_target'=>learningRejected(fn()=>fromMetricSignal(
            learningSignal('observed_change',['factory']),
            learningMetric('metric-activation',0.42)
        )),
        'bad_type'=>learningRejected(fn()=>fromMetricSignal(
            learningSignal('recommendation'),
            learningMetric('metric-activation',0.42)
        )),
    ];
}elseif($case==='fail_closed'){
    $unknown=fromMetricSignal(
        learningSignal('evidence_gap'),
        learningMetric('metric-unknown',null)
    );
    $stale=fromMetricSignal(
        learningSignal('observed_change'),
        learningMetric('metric-stale',0.30,['freshness'=>'stale','confidence'=>0.7])
    );
    $inferred=fromMetricSignal(
        learningSignal('inferred_change'),
        learningMetric('metric-inferred',0.35,['nature'=>'inferred','confidence'=>0.55])
    );
    $experiment=fromExperimentSignal(
        learningSignal('experiment_result',['discovery','capital']),
        learningOutcomeRaw(),
        learningMetric('metric-base',null),
        learningMetric('metric-variant',0.50)
    );
    $out=['unknown'=>$unknown,'stale'=>$stale,'inferred'=>$inferred,'experiment'=>$experiment];
}elseif($case==='reject_payload'){
    $base=learningSignal('observed_change');
    $cases=[];
    foreach(['message','action','priority','members','event_payload','user_id'] as $field){
        $candidate=$base;
        $candidate[$field]=$field==='members'?['person-1']:'forbidden';
        $cases[$field]=learningRejected(fn()=>fromMetricSignal(
            $candidate,
            learningMetric('metric-activation',0.42)
        ));
    }
    $out=$cases;
}elseif($case==='provenance'){
    $revenue=fromMetricSignal(
        learningSignal('revenue_outcome',['capital']),
        learningMetric('metric-revenue',1200,[
            'category'=>'revenue_outcome',
            'unit'=>'cop',
            'source_ref'=>'aggregate:finance/product',
            'evidence_ref'=>'evidence:product/revenue',
        ])
    );
    $experiment=fromExperimentSignal(
        learningSignal('experiment_result',['discovery','momentum']),
        learningOutcomeRaw(),
        learningMetric('metric-base',0.40),
        learningMetric('metric-variant',0.51)
    );
    $gap=fromMetricSignal(
        learningSignal('evidence_gap',['customer_success']),
        learningMetric('metric-gap',null)
    );
    $out=['revenue'=>$revenue,'experiment'=>$experiment,'gap'=>$gap];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(ProductLearningSignal::class);
    $methods=array_values(array_map(
        static fn(ReflectionMethod $m): string=>$m->getName(),
        array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ProductLearningSignal::class
        )
    ));
    sort($methods,SORT_STRING);
    $out=['methods'=>$methods];
}else{
    fwrite(STDERR,"Unknown product learning signal scenario\n");
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
