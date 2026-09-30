<?php
declare(strict_types=1);

foreach([
    'ProductDiscovery','ProductDiscoveryExperiment','ProductIntelligence','ProductExperimentOutcome',
    'ProductDiscoveryAssessment','ProductDiscoveryDecision','ProductDiscoveryWorkOrigin',
] as $file) require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Business\ProductDiscoveryWorkOrigin;

const A='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const B='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const C='cccccccccccccccccccccccccccccccc';
const D='dddddddddddddddddddddddddddddddd';
const E='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const F='ffffffffffffffffffffffffffffffff';

function ini(): array { return [
    'version'=>1,'initiative_id'=>'initiative:'.A,'scope'=>'venture','venture_id'=>'venture-alpha',
    'market_ref'=>'market:'.B,'problem_ref'=>'problem:'.C,'segment_ref'=>'segment:'.D,
    'evidence_refs'=>['evidence:'.E],'source_ref'=>'source:'.F,'freshness'=>'fresh',
    'confidence'=>'high','state'=>'HYPOTHESIS','responsible_ref'=>'responsible:'.B,
]; }
function hyp(): array { return [
    'version'=>1,'hypothesis_ref'=>'hypothesis:'.B,'initiative_id'=>'initiative:'.A,
    'expected_outcome_ref'=>'outcome:'.C,'primary_metric_ref'=>'metric:'.D,
    'constraint_refs'=>['constraint:'.E],'evidence_refs'=>['evidence:'.F],
    'source_ref'=>'source:'.A,'freshness'=>'fresh','confidence'=>'high',
]; }
function experimentFixture(): array { return [
    'version'=>1,'experiment_ref'=>'experiment:'.C,'initiative_id'=>'initiative:'.A,
    'hypothesis_ref'=>'hypothesis:'.B,'kind'=>'prototype','primary_metric_ref'=>'metric:'.D,
    'evaluation_window'=>['start_at'=>100,'end_at'=>200],'validation_cost_ref'=>'cost:'.E,
    'state'=>'EXPERIMENT_READY',
]; }
function metric(string $id,int $value,string $freshness='fresh'): array { return [
    'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-alpha','product_id'=>'product-alpha',
    'surface'=>'web','category'=>'activation','period'=>['start_at'=>110,'end_at'=>190],
    'status'=>'measured','value'=>$value,'unit'=>'percent','source_ref'=>'aggregate:usage/alpha',
    'evidence_ref'=>'evidence:product/alpha','sample_size'=>100,'freshness'=>$freshness,
    'confidence'=>0.9,'nature'=>'observed',
]; }
function outcome(): array { return [
    'version'=>1,'outcome_id'=>'outcome-alpha','experiment_ref'=>'experiment:'.C,
    'venture_id'=>'venture-alpha','product_id'=>'product-alpha','evaluation_window'=>['start_at'=>100,'end_at'=>200],
    'source_ref'=>'aggregate:discovery/alpha','evidence_ref'=>'evidence:product/discovery-alpha',
]; }
function assess(string $class='VALIDATED'): array { return [
    'version'=>1,'assessment_ref'=>'assessment:'.F,'experiment_ref'=>'experiment:'.C,
    'initiative_id'=>'initiative:'.A,'hypothesis_ref'=>'hypothesis:'.B,'primary_metric_ref'=>'metric:'.D,
    'outcome_id'=>'outcome-alpha','classification'=>$class,'assessment_rule_ref'=>'rule:'.E,
    'assessment_evidence_refs'=>['evidence:'.F,'evidence:'.E],
]; }
function decision(string $value='BUILD',array $extra=[]): array { return array_replace([
    'version'=>1,'decision_ref'=>'decision:'.A,'assessment_ref'=>'assessment:'.F,
    'experiment_ref'=>'experiment:'.C,'initiative_id'=>'initiative:'.A,'hypothesis_ref'=>'hypothesis:'.B,
    'decision'=>$value,'decision_reason_ref'=>'reason:'.D,
    'decision_evidence_refs'=>['evidence:'.F,'evidence:'.E],
],$extra); }
function origin(array $extra=[]): array { return array_replace([
    'version'=>1,
    'work_id'=>'work:discovery-alpha',
    'group_id'=>'group-alpha',
    'project_id'=>'project-alpha',
    'repository_ref'=>'pl0n3r/ControlBot',
    'work_type'=>'product',
    'requested_capabilities'=>['product.discovery','workitem.write'],
    'required_roles'=>['producto','qa'],
    'authority_level'=>'l2',
    'priority_class'=>'high',
    'policy_ref'=>'controlbot:policy/product-discovery-v1',
    'depends_on'=>['work:discovery-prerequisite'],
    'claims'=>['claim:product-alpha'],
    'execution'=>false,
],$extra); }
function runOrigin(
    string $decisionValue='BUILD',
    string $class='VALIDATED',
    string $freshness='fresh',
    array $originExtra=[],
    array $decisionExtra=[],
    string $expectedVenture='venture-alpha',
    string $expectedProduct='product-alpha'
): array {
    return ProductDiscoveryWorkOrigin::materialize(
        origin($originExtra),decision($decisionValue,$decisionExtra),assess($class),experimentFixture(),ini(),hyp(),outcome(),
        metric('metric-baseline',10,$freshness),metric('metric-variant',15,$freshness),
        $expectedVenture,$expectedProduct
    );
}

$case=$argv[1]??'';
if($case==='valid'){
    $out=runOrigin();
}elseif($case==='blocked'){
    $out=[];
    foreach(['ITERATE','PARK','STOP','RESEARCH_MORE'] as $value)
        $out[strtolower($value)]=runOrigin($value,'INVALIDATED');
    $out['stale']=runOrigin('ITERATE','INCONCLUSIVE','stale');
    $out['unknown']=runOrigin('PARK','INCONCLUSIVE','unknown');
}elseif($case==='explicit'){
    $out=[
        'base'=>runOrigin(),
        'changed'=>runOrigin('BUILD','VALIDATED','fresh',[
            'work_id'=>'work:discovery-review',
            'work_type'=>'engineering',
            'requested_capabilities'=>['code.review','product.discovery'],
            'required_roles'=>['arquitectura','producto'],
            'authority_level'=>'l3',
            'priority_class'=>'medium',
            'policy_ref'=>'controlbot:policy/product-discovery-review-v1',
            'depends_on'=>['work:alpha','work:beta'],
            'claims'=>['claim:review-alpha','claim:review-beta'],
        ]),
    ];
}elseif($case==='provenance'){
    $out=[
        'first'=>runOrigin(),
        'second'=>runOrigin(),
        'changed'=>runOrigin('BUILD','VALIDATED','fresh',[],['decision_ref'=>'decision:'.B]),
    ];
}elseif($case==='invalid'){
    $out=[
        'cross_scope'=>runOrigin('BUILD','VALIDATED','fresh',[],[],'venture-beta','product-alpha'),
        'extra'=>runOrigin('BUILD','VALIDATED','fresh',['extra'=>'x']),
        'sensitive'=>runOrigin('BUILD','VALIDATED','fresh',['work_id'=>'Bearer abcdefghijklmnopqrstuvwxyz']),
        'pii'=>runOrigin('BUILD','VALIDATED','fresh',['work_id'=>'owner@example.com']),
        'duplicate_capability'=>runOrigin('BUILD','VALIDATED','fresh',[
            'requested_capabilities'=>['product.discovery','product.discovery'],
        ]),
        'duplicate_claim'=>runOrigin('BUILD','VALIDATED','fresh',[
            'claims'=>['claim:product-alpha','claim:product-alpha'],
        ]),
        'duplicate_role'=>runOrigin('BUILD','VALIDATED','fresh',[
            'required_roles'=>['producto','producto'],
        ]),
    ];
}elseif($case==='surface'){
    $out=[
        'valid'=>runOrigin(),
        'source'=>file_get_contents(__DIR__.'/../src/ProductDiscoveryWorkOrigin.php'),
    ];
}else{
    fwrite(STDERR,"scenario invalid\n");exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
