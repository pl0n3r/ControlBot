<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductDiscovery.php';
require __DIR__.'/../src/ProductDiscoveryExperiment.php';

use ControlBot\Business\ProductDiscoveryExperiment;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function r(string $ns,string $hex='11111111111111111111111111111111'): string { return "$ns:$hex"; }
function initiative(array $o=[]): array { return array_replace([
    'version'=>1,'initiative_id'=>r('initiative'),'scope'=>'venture','venture_id'=>'venture-condor',
    'market_ref'=>r('market'),'problem_ref'=>r('problem'),'segment_ref'=>r('segment'),
    'evidence_refs'=>[r('evidence')],'source_ref'=>r('source'),'freshness'=>'fresh','confidence'=>'medium',
    'state'=>'HYPOTHESIS','responsible_ref'=>r('responsible'),
],$o); }
function hypothesis(array $o=[]): array { return array_replace([
    'version'=>1,'hypothesis_ref'=>r('hypothesis'),'initiative_id'=>r('initiative'),
    'expected_outcome_ref'=>r('outcome'),'primary_metric_ref'=>r('metric'),
    'constraint_refs'=>[r('constraint')],'evidence_refs'=>[r('evidence','22222222222222222222222222222222')],
    'source_ref'=>r('source','22222222222222222222222222222222'),'freshness'=>'fresh','confidence'=>'medium',
],$o); }
function plan(array $o=[]): array { return array_replace([
    'version'=>1,'experiment_ref'=>r('experiment'),'initiative_id'=>r('initiative'),
    'hypothesis_ref'=>r('hypothesis'),'kind'=>'landing_test','primary_metric_ref'=>r('metric'),
    'evaluation_window'=>['start_at'=>1000,'end_at'=>2000],'validation_cost_ref'=>r('cost'),'state'=>'EXPERIMENT_READY',
],$o); }

$case=$argv[1]??'';
if($case==='bind'){
    $out=[
        'good'=>ProductDiscoveryExperiment::plan(plan(),initiative(),hypothesis()),
        'initiative'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['initiative_id'=>r('initiative','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')]),initiative(),hypothesis())),
        'hypothesis'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['hypothesis_ref'=>r('hypothesis','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')]),initiative(),hypothesis())),
        'metric'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['primary_metric_ref'=>r('metric','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')]),initiative(),hypothesis())),
    ];
}elseif($case==='declared'){
    $out=[
        'good'=>ProductDiscoveryExperiment::plan(plan(),initiative(),hypothesis()),
        'reversed_window'=>ProductDiscoveryExperiment::plan(plan(['evaluation_window'=>['end_at'=>2000,'start_at'=>1000]]),initiative(),hypothesis()),
        'bad_kind'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['kind'=>'unknown_kind']),initiative(),hypothesis())),
        'bad_window'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['evaluation_window'=>['start_at'=>2000,'end_at'=>1000]]),initiative(),hypothesis())),
        'bad_cost'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['validation_cost_ref'=>'cost:human-readable']),initiative(),hypothesis())),
    ];
}elseif($case==='scope'){
    $venture=ProductDiscoveryExperiment::plan(plan(),initiative(),hypothesis());
    $groupI=initiative(['scope'=>'group','venture_id'=>null,'market_ref'=>null]);
    $group=ProductDiscoveryExperiment::plan(plan(),$groupI,hypothesis());
    $stale=ProductDiscoveryExperiment::plan(plan(),initiative(['freshness'=>'stale','confidence'=>'medium']),hypothesis(['freshness'=>'stale','confidence'=>'medium']));
    $unknown=ProductDiscoveryExperiment::plan(plan(),initiative(['freshness'=>'unknown','confidence'=>'unknown']),hypothesis(['freshness'=>'unknown','confidence'=>'unknown']));
    $out=['venture'=>$venture,'group'=>$group,'stale'=>$stale,'unknown'=>$unknown];
}elseif($case==='invalid'){
    $extra=plan();$extra['repository']='pl0n3r/ControlBot';
    $out=[
        'extra'=>bad(fn()=>ProductDiscoveryExperiment::plan($extra,initiative(),hypothesis())),
        'bad_state'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['state'=>'VALIDATED']),initiative(),hypothesis())),
        'parked'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(),initiative(['state'=>'PARKED']),hypothesis())),
        'bad_ref'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['experiment_ref'=>'experiment:not-opaque']),initiative(),hypothesis())),
        'zero_window'=>bad(fn()=>ProductDiscoveryExperiment::plan(plan(['evaluation_window'=>['start_at'=>1000,'end_at'=>1000]]),initiative(),hypothesis())),
    ];
}elseif($case==='deterministic'){
    $a=ProductDiscoveryExperiment::plan(plan(),initiative(),hypothesis());
    $out=['a'=>$a,'b'=>ProductDiscoveryExperiment::plan(plan(),initiative(),hypothesis())];
}elseif($case==='pure'){
    $ref=new ReflectionClass(ProductDiscoveryExperiment::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ProductDiscoveryExperiment::class
    ));sort($methods,SORT_STRING);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ProductDiscoveryExperiment.php')];
}else{fwrite(STDERR,"Unknown discovery experiment scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
