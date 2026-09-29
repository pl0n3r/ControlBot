<?php
declare(strict_types=1);

foreach(['ProductDiscovery','ProductDiscoveryExperiment','ProductIntelligence','ProductExperimentOutcome','ProductDiscoveryAssessment','ProductDiscoveryDecision'] as $file)
    require __DIR__.'/../src/'.$file.'.php';

use ControlBot\Business\ProductDiscoveryDecision;

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
];}
function hyp(): array { return [
 'version'=>1,'hypothesis_ref'=>'hypothesis:'.B,'initiative_id'=>'initiative:'.A,
 'expected_outcome_ref'=>'outcome:'.C,'primary_metric_ref'=>'metric:'.D,
 'constraint_refs'=>['constraint:'.E],'evidence_refs'=>['evidence:'.F],
 'source_ref'=>'source:'.A,'freshness'=>'fresh','confidence'=>'high',
];}
function exp(): array { return [
 'version'=>1,'experiment_ref'=>'experiment:'.C,'initiative_id'=>'initiative:'.A,
 'hypothesis_ref'=>'hypothesis:'.B,'kind'=>'prototype','primary_metric_ref'=>'metric:'.D,
 'evaluation_window'=>['start_at'=>100,'end_at'=>200],'validation_cost_ref'=>'cost:'.E,'state'=>'EXPERIMENT_READY',
];}
function metric(string $id,int $value): array { return [
 'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-alpha','product_id'=>'product-alpha',
 'surface'=>'web','category'=>'activation','period'=>['start_at'=>110,'end_at'=>190],
 'status'=>'measured','value'=>$value,'unit'=>'percent','source_ref'=>'aggregate:usage/alpha',
 'evidence_ref'=>'evidence:product/alpha','sample_size'=>100,'freshness'=>'fresh','confidence'=>0.9,'nature'=>'observed',
];}
function outcome(): array { return [
 'version'=>1,'outcome_id'=>'outcome-alpha','experiment_ref'=>'experiment:'.C,
 'venture_id'=>'venture-alpha','product_id'=>'product-alpha','evaluation_window'=>['start_at'=>100,'end_at'=>200],
 'source_ref'=>'aggregate:discovery/alpha','evidence_ref'=>'evidence:product/discovery-alpha',
];}
function assess(string $class='VALIDATED'): array { return [
 'version'=>1,'assessment_ref'=>'assessment:'.F,'experiment_ref'=>'experiment:'.C,
 'initiative_id'=>'initiative:'.A,'hypothesis_ref'=>'hypothesis:'.B,'primary_metric_ref'=>'metric:'.D,
 'outcome_id'=>'outcome-alpha','classification'=>$class,'assessment_rule_ref'=>'rule:'.E,
 'assessment_evidence_refs'=>['evidence:'.F,'evidence:'.E],
];}
function decision(string $value='BUILD',array $extra=[]): array { return array_replace([
 'version'=>1,'decision_ref'=>'decision:'.A,'assessment_ref'=>'assessment:'.F,
 'experiment_ref'=>'experiment:'.C,'initiative_id'=>'initiative:'.A,'hypothesis_ref'=>'hypothesis:'.B,
 'decision'=>$value,'decision_reason_ref'=>'reason:'.D,
 'decision_evidence_refs'=>['evidence:'.F,'evidence:'.E],
],$extra);}
function runDecision(string $value='BUILD',string $class='VALIDATED',array $extra=[]): array {
 return ProductDiscoveryDecision::decide(
  decision($value,$extra),assess($class),exp(),ini(),hyp(),outcome(),
  metric('metric-baseline',10),metric('metric-variant',15),'venture-alpha','product-alpha'
 );
}
function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='bindings'){
 $out=[
  'assessment'=>bad(fn()=>runDecision('BUILD','VALIDATED',['assessment_ref'=>'assessment:'.A])),
  'experiment'=>bad(fn()=>runDecision('BUILD','VALIDATED',['experiment_ref'=>'experiment:'.A])),
  'initiative'=>bad(fn()=>runDecision('BUILD','VALIDATED',['initiative_id'=>'initiative:'.B])),
  'hypothesis'=>bad(fn()=>runDecision('BUILD','VALIDATED',['hypothesis_ref'=>'hypothesis:'.C])),
 ];
}elseif($case==='catalog'){
 $out=[];
 foreach(['BUILD','ITERATE','PARK','STOP','RESEARCH_MORE'] as $d)$out[strtolower($d)]=runDecision($d)['decision'];
 $out['invalid']=bad(fn()=>runDecision('SHIP'));
 $out['reason']=bad(fn()=>runDecision('BUILD','VALIDATED',['decision_reason_ref'=>'reason:nope']));
 $out['empty_evidence']=bad(fn()=>runDecision('BUILD','VALIDATED',['decision_evidence_refs'=>[]]));
 $out['duplicate']=bad(fn()=>runDecision('BUILD','VALIDATED',['decision_evidence_refs'=>['evidence:'.E,'evidence:'.E]]));
}elseif($case==='build'){
 $out=[
  'validated'=>runDecision('BUILD','VALIDATED'),
  'invalidated_reject'=>bad(fn()=>runDecision('BUILD','INVALIDATED')),
  'inconclusive_reject'=>bad(fn()=>runDecision('BUILD','INCONCLUSIVE')),
  'validated_not_inferred'=>runDecision('ITERATE','VALIDATED')['decision'],
 ];
}elseif($case==='gates'){
 $extra=decision();$extra['authority_verified']=true;
 $out=[
  'build'=>runDecision(),
  'self_certify'=>bad(fn()=>ProductDiscoveryDecision::decide(
   $extra,assess(),exp(),ini(),hyp(),outcome(),metric('metric-baseline',10),metric('metric-variant',15),
   'venture-alpha','product-alpha'
  )),
 ];
}elseif($case==='nonbuild'){
 $out=[];
 foreach(['ITERATE','PARK','STOP','RESEARCH_MORE'] as $d)$out[$d]=runDecision($d,'INVALIDATED');
}elseif($case==='schema'){
 $extra=decision();$extra['extra']='x';
 $first=runDecision('PARK','INVALIDATED');$second=runDecision('PARK','INVALIDATED');
 $out=[
  'extra'=>bad(fn()=>ProductDiscoveryDecision::decide(
   $extra,assess('INVALIDATED'),exp(),ini(),hyp(),outcome(),metric('metric-baseline',10),metric('metric-variant',15),
   'venture-alpha','product-alpha'
  )),
  'deterministic'=>$first===$second,
  'sorted'=>$first['decision_evidence_refs'],
  'source'=>file_get_contents(__DIR__.'/../src/ProductDiscoveryDecision.php'),
  'valid'=>$first,
 ];
}else{fwrite(STDERR,"scenario invalid\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
