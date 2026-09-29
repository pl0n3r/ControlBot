<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductIntelligence.php';
require __DIR__.'/../src/ProductExperimentOutcome.php';

use ControlBot\Business\ProductExperimentOutcome;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

function metric(string $id,float|int|null $value,array $overrides=[]): array {
    return array_replace([
        'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'surface'=>'web_app','category'=>'activation','period'=>['start_at'=>1000,'end_at'=>2000],
        'status'=>$value===null?'unknown':'measured','value'=>$value,'unit'=>'ratio',
        'source_ref'=>'aggregate:analytics/experiment','evidence_ref'=>'evidence:product/experiment-metric',
        'sample_size'=>$value===null?0:100,'freshness'=>$value===null?'unknown':'fresh',
        'confidence'=>$value===null?0.0:0.95,'nature'=>'observed',
    ],$overrides);
}
function raw(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'outcome_id'=>'outcome-activation-test',
        'experiment_ref'=>'experiment:0123456789abcdef0123456789abcdef',
        'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'evaluation_window'=>['start_at'=>900,'end_at'=>2100],
        'source_ref'=>'aggregate:analytics/experiment-outcome',
        'evidence_ref'=>'evidence:product/experiment-outcome',
    ],$overrides);
}
function outcome(array $raw,array $base,array $variant): array {
    return ProductExperimentOutcome::outcome($raw,$base,$variant,'venture-condor','product-condor');
}

$case=$argv[1]??'';
if($case==='scope'){
    $base=metric('metric-baseline',0.40);
    $variant=metric('metric-variant',0.52);
    $out=[
        'good'=>outcome(raw(),$base,$variant),
        'venture'=>rejected(fn()=>outcome(raw(),$base,metric('metric-variant',0.52,['venture_id'=>'venture-other']))),
        'product'=>rejected(fn()=>outcome(raw(),$base,metric('metric-variant',0.52,['product_id'=>'product-other']))),
        'surface'=>rejected(fn()=>outcome(raw(),$base,metric('metric-variant',0.52,['surface'=>'mobile_app']))),
        'category'=>rejected(fn()=>outcome(raw(),$base,metric('metric-variant',0.52,['category'=>'retention']))),
        'unit'=>rejected(fn()=>outcome(raw(),$base,metric('metric-variant',52,['unit'=>'percent']))),
    ];
}elseif($case==='measured'){
    $outcome=outcome(raw(),metric('metric-baseline',0.40),metric('metric-variant',0.52));
    $out=[
        'outcome'=>$outcome,
        'window_reject'=>rejected(fn()=>outcome(raw(['evaluation_window'=>['start_at'=>1100,'end_at'=>2100]]),metric('metric-baseline',0.40),metric('metric-variant',0.52))),
    ];
}elseif($case==='inconclusive'){
    $unknown=outcome(raw(),metric('metric-baseline',null),metric('metric-variant',0.52));
    $insufficient=outcome(
        raw(),
        metric('metric-baseline',null,['status'=>'insufficient_data','sample_size'=>3,'freshness'=>'fresh','confidence'=>0.25]),
        metric('metric-variant',0.52)
    );
    $out=['unknown'=>$unknown,'insufficient'=>$insufficient];
}elseif($case==='fail_closed'){
    $inferred=outcome(
        raw(),
        metric('metric-baseline',0.40,['nature'=>'inferred','confidence'=>0.70]),
        metric('metric-variant',0.52,['confidence'=>0.88])
    );
    $stale=outcome(
        raw(),
        metric('metric-baseline',0.40,['freshness'=>'stale','confidence'=>0.80]),
        metric('metric-variant',0.52,['confidence'=>0.90])
    );
    $unknownFreshness=outcome(
        raw(),
        metric('metric-baseline',0.40,['freshness'=>'unknown']),
        metric('metric-variant',0.52)
    );
    $out=['inferred'=>$inferred,'stale'=>$stale,'unknown_freshness'=>$unknownFreshness];
}elseif($case==='boundary'){
    $extra=raw();$extra['decision']='build';
    $out=[
        'good'=>outcome(raw(),metric('metric-baseline',0.40),metric('metric-variant',0.52)),
        'bad_ref'=>rejected(fn()=>outcome(raw(['experiment_ref'=>'experiment:plain-name']),metric('metric-baseline',0.40),metric('metric-variant',0.52))),
        'extra_decision'=>rejected(fn()=>outcome($extra,metric('metric-baseline',0.40),metric('metric-variant',0.52))),
    ];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(ProductExperimentOutcome::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    $out=['methods'=>$methods,'outcome'=>outcome(raw(),metric('metric-baseline',0.40),metric('metric-variant',0.52))];
}else{fwrite(STDERR,"Unknown product experiment outcome scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
