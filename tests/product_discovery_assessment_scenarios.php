<?php
declare(strict_types=1);

foreach([
    'ProductDiscovery','ProductDiscoveryExperiment','ProductIntelligence',
    'ProductExperimentOutcome','ProductDiscoveryAssessment'
] as $file) require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Business\ProductDiscoveryAssessment;

const H='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const H2='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const H3='cccccccccccccccccccccccccccccccc';
const H4='dddddddddddddddddddddddddddddddd';
const H5='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const H6='ffffffffffffffffffffffffffffffff';

function initiative(): array {
    return [
        'version'=>1,'initiative_id'=>'initiative:'.H,'scope'=>'venture',
        'venture_id'=>'venture-alpha','market_ref'=>'market:'.H2,
        'problem_ref'=>'problem:'.H3,'segment_ref'=>'segment:'.H4,
        'evidence_refs'=>['evidence:'.H5],'source_ref'=>'source:'.H6,
        'freshness'=>'fresh','confidence'=>'high','state'=>'HYPOTHESIS',
        'responsible_ref'=>'responsible:'.H2,
    ];
}
function hypothesis(string $fresh='fresh',string $confidence='high'): array {
    return [
        'version'=>1,'hypothesis_ref'=>'hypothesis:'.H2,'initiative_id'=>'initiative:'.H,
        'expected_outcome_ref'=>'outcome:'.H3,'primary_metric_ref'=>'metric:'.H4,
        'constraint_refs'=>['constraint:'.H5],'evidence_refs'=>['evidence:'.H6],
        'source_ref'=>'source:'.H,'freshness'=>$fresh,'confidence'=>$confidence,
    ];
}
function experiment(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'experiment_ref'=>'experiment:'.H3,'initiative_id'=>'initiative:'.H,
        'hypothesis_ref'=>'hypothesis:'.H2,'kind'=>'prototype','primary_metric_ref'=>'metric:'.H4,
        'evaluation_window'=>['start_at'=>100,'end_at'=>200],
        'validation_cost_ref'=>'cost:'.H5,'state'=>'EXPERIMENT_READY',
    ],$overrides);
}
function metric(string $id,float|int|null $value,string $status='measured',string $fresh='fresh',string $nature='observed',float $confidence=0.9): array {
    return [
        'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-alpha','product_id'=>'product-alpha',
        'surface'=>'web','category'=>'activation','period'=>['start_at'=>110,'end_at'=>190],
        'status'=>$status,'value'=>$value,'unit'=>'percent',
        'source_ref'=>'aggregate:usage/alpha','evidence_ref'=>'evidence:product/alpha',
        'sample_size'=>$status==='unknown'?0:100,'freshness'=>$fresh,
        'confidence'=>$confidence,'nature'=>$nature,
    ];
}
function outcome(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'outcome_id'=>'outcome-alpha','experiment_ref'=>'experiment:'.H3,
        'venture_id'=>'venture-alpha','product_id'=>'product-alpha',
        'evaluation_window'=>['start_at'=>100,'end_at'=>200],
        'source_ref'=>'aggregate:discovery/alpha','evidence_ref'=>'evidence:product/discovery-alpha',
    ],$overrides);
}
function assessment(string $classification='VALIDATED',array $overrides=[]): array {
    return array_replace([
        'version'=>1,'assessment_ref'=>'assessment:'.H6,'experiment_ref'=>'experiment:'.H3,
        'initiative_id'=>'initiative:'.H,'hypothesis_ref'=>'hypothesis:'.H2,
        'primary_metric_ref'=>'metric:'.H4,'outcome_id'=>'outcome-alpha',
        'classification'=>$classification,'assessment_rule_ref'=>'rule:'.H5,
        'assessment_evidence_refs'=>['evidence:'.H6,'evidence:'.H5],
    ],$overrides);
}
function runAssessment(
    string $classification='VALIDATED',
    array $assessmentOverrides=[],
    array $experimentOverrides=[],
    array $outcomeOverrides=[],
    ?array $baseline=null,
    ?array $variant=null,
    string $hypothesisFresh='fresh',
    string $hypothesisConfidence='high'
): array {
    return ProductDiscoveryAssessment::assess(
        assessment($classification,$assessmentOverrides),
        experiment($experimentOverrides),
        initiative(),
        hypothesis($hypothesisFresh,$hypothesisConfidence),
        outcome($outcomeOverrides),
        $baseline??metric('metric-baseline',10),
        $variant??metric('metric-variant',15),
        'venture-alpha',
        'product-alpha'
    );
}
function bad(callable $fn): bool {
    try{$fn();return false;}catch(\InvalidArgumentException){return true;}
}

$case=$argv[1]??'';
if($case==='valid'){
    $out=runAssessment();
}elseif($case==='bindings'){
    $out=[
        'experiment'=>bad(fn()=>runAssessment('VALIDATED',['experiment_ref'=>'experiment:'.H4])),
        'initiative'=>bad(fn()=>runAssessment('VALIDATED',['initiative_id'=>'initiative:'.H4])),
        'hypothesis'=>bad(fn()=>runAssessment('VALIDATED',['hypothesis_ref'=>'hypothesis:'.H4])),
        'metric'=>bad(fn()=>runAssessment('VALIDATED',['primary_metric_ref'=>'metric:'.H3])),
        'outcome'=>bad(fn()=>runAssessment('VALIDATED',['outcome_id'=>'outcome-other'])),
        'outcome_experiment'=>bad(fn()=>runAssessment('VALIDATED',[],[],['experiment_ref'=>'experiment:'.H4])),
        'window'=>bad(fn()=>runAssessment('VALIDATED',[],[],['evaluation_window'=>['start_at'=>101,'end_at'=>200]])),
    ];
}elseif($case==='classification'){
    $out=[
        'validated'=>runAssessment('VALIDATED')['classification'],
        'invalidated'=>runAssessment('INVALIDATED')['classification'],
        'inconclusive'=>runAssessment('INCONCLUSIVE')['classification'],
        'unknown_class'=>bad(fn()=>runAssessment('BETTER')),
        'empty_rule'=>bad(fn()=>runAssessment('VALIDATED',['assessment_rule_ref'=>'rule:secret'])),
        'empty_evidence'=>bad(fn()=>runAssessment('VALIDATED',['assessment_evidence_refs'=>[]])),
    ];
}elseif($case==='unreliable'){
    $stale=metric('metric-variant',15,'measured','stale','observed',0.7);
    $inferred=metric('metric-variant',15,'measured','fresh','inferred',0.7);
    $unknown=metric('metric-variant',null,'unknown','unknown','observed',0.0);
    $out=[
        'stale_reject'=>bad(fn()=>runAssessment('VALIDATED',[],[],[],null,$stale)),
        'stale_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,$stale)['classification'],
        'inferred_reject'=>bad(fn()=>runAssessment('INVALIDATED',[],[],[],null,$inferred)),
        'inferred_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,$inferred)['classification'],
        'unknown_reject'=>bad(fn()=>runAssessment('VALIDATED',[],[],[],null,$unknown)),
        'unknown_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,$unknown)['classification'],
        'zero_confidence_reject'=>bad(fn()=>runAssessment('VALIDATED',[],[],[],null,metric('metric-variant',15,'measured','fresh','observed',0.0))),
        'zero_confidence_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,metric('metric-variant',15,'measured','fresh','observed',0.0))['classification'],
        'hypothesis_stale_reject'=>bad(fn()=>runAssessment('VALIDATED',[],[],[],null,null,'stale','medium')),
        'hypothesis_stale_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,null,'stale','medium')['classification'],
        'hypothesis_unknown_reject'=>bad(fn()=>runAssessment('INVALIDATED',[],[],[],null,null,'unknown','unknown')),
        'hypothesis_unknown_ok'=>runAssessment('INCONCLUSIVE',[],[],[],null,null,'unknown','unknown')['classification'],
    ];
}elseif($case==='numbers'){
    $out=[
        'higher'=>runAssessment('INVALIDATED'),
        'lower'=>runAssessment(
            'VALIDATED',[],[],[],metric('metric-baseline',20),metric('metric-variant',5)
        ),
    ];
}elseif($case==='schema'){
    $extra=assessment();
    $extra['extra']='x';
    $out=[
        'sorted'=>runAssessment()['assessment_evidence_refs'],
        'duplicate'=>bad(fn()=>runAssessment('VALIDATED',[
            'assessment_evidence_refs'=>['evidence:'.H5,'evidence:'.H5],
        ])),
        'extra'=>bad(fn()=>ProductDiscoveryAssessment::assess(
            $extra,experiment(),initiative(),hypothesis(),outcome(),
            metric('metric-baseline',10),metric('metric-variant',15),
            'venture-alpha','product-alpha'
        )),
        'bad_ref'=>bad(fn()=>runAssessment('VALIDATED',['assessment_ref'=>'assessment:secret@example.com'])),
        'sensitive_text'=>bad(fn()=>runAssessment('VALIDATED',['outcome_id'=>'outcome-secret'],[],['outcome_id'=>'outcome-secret'])),
        'direct_pii_text'=>bad(fn()=>runAssessment('VALIDATED',['outcome_id'=>'outcome-1234567890'],[],['outcome_id'=>'outcome-1234567890'])),
        'numeric_opaque_ref'=>runAssessment('VALIDATED',['assessment_ref'=>'assessment:12345678901234567890123456789012'])['assessment_ref'],
    ];
}elseif($case==='source'){
    $out=['source'=>file_get_contents(__DIR__.'/../src/ProductDiscoveryAssessment.php')];
}else{
    fwrite(STDERR,"unknown scenario\n");exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
