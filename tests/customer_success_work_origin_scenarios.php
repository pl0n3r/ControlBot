<?php
declare(strict_types=1);
require __DIR__.'/../src/CustomerSuccessCore.php';
require __DIR__.'/../src/CustomerSuccessWorkOrigin.php';

use ControlBot\CustomerSuccess\CustomerSuccessWorkOrigin;

function csRef(string $ns,string $c): string { return $ns.':'.str_repeat($c,32); }
function csBlocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function csIntent(array $o=[]): array { return array_replace([
    'version'=>1,'work_id'=>'work-success-001','group_id'=>'pl0n3r-group','work_type'=>'engineering',
    'requested_capabilities'=>['coding','support_analysis'],'required_roles'=>['ingenieria-software','qa'],
    'authority_level'=>'operational','priority_class'=>'medium','depends_on'=>[],'claims'=>['src/customer'],
    'policy_ref'=>'factory:customer-success-v1','observed_at'=>'2026-09-29T14:30:00-05:00',
],$o); }
function csSupport(array $o=[]): array { return array_replace([
    'version'=>1,'signal_id'=>csRef('support','1'),'venture_id'=>'venture-condor','product_ref'=>csRef('product','2'),
    'category'=>'bug','severity'=>'critical','pattern_ref'=>csRef('pattern','3'),'knowledge_ref'=>csRef('knowledge','4'),
    'resolution_state'=>'escalated','evidence_ref'=>csRef('evidence','5'),'freshness'=>'fresh',
    'observed_at'=>1700000000,'escalation_ref'=>csRef('escalation','6'),
],$o); }
function csSnapshot(): array {
    $names=['activation','adoption','churn_risk','onboarding','reliability_impact','renewal_signal','satisfaction','support_burden','usage_recency'];
    $dimensions=[];
    foreach($names as $i=>$name) $dimensions[]=[
        'name'=>$name,'status'=>'measured','value_ref'=>csRef('metric',dechex(($i%15)+1)),
        'evidence_ref'=>csRef('evidence',dechex((($i+4)%15)+1)),'freshness'=>'fresh',
        'confidence'=>0.9,'nature'=>$name==='churn_risk'?'inferred':'observed',
    ];
    return [
        'version'=>1,'snapshot_id'=>csRef('success','a'),'venture_id'=>'venture-condor',
        'product_ref'=>csRef('product','b'),'observed_at'=>1700000000,'dimensions'=>$dimensions,
    ];
}

$case=$argv[1]??'';
if($case==='scope'){
    $out=[
        'support'=>CustomerSuccessWorkOrigin::fromSupportSignal(csIntent(),csSupport(),'venture-condor'),
        'health'=>CustomerSuccessWorkOrigin::fromHealthException(csIntent(['work_id'=>'work-health-001']),csSnapshot(),'support_burden','venture-condor'),
        'cross'=>csBlocked(fn()=>CustomerSuccessWorkOrigin::fromSupportSignal(csIntent(),csSupport(),'venture-brvtal')),
    ];
}elseif($case==='explicit'){
    $out=CustomerSuccessWorkOrigin::fromSupportSignal(csIntent([
        'work_type'=>'knowledge_documentation','priority_class'=>'medium','authority_level'=>'l2_team',
        'requested_capabilities'=>['support_analysis'],'required_roles'=>['contenido'],
    ]),csSupport(['severity'=>'critical']),'venture-condor');
}elseif($case==='deterministic'){
    $first=CustomerSuccessWorkOrigin::fromSupportSignal(csIntent([
        'requested_capabilities'=>['support_analysis','coding'],'required_roles'=>['qa','ingenieria-software'],'claims'=>['b','a'],
    ]),csSupport(),'venture-condor');
    $second=CustomerSuccessWorkOrigin::fromSupportSignal(csIntent([
        'requested_capabilities'=>['coding','support_analysis'],'required_roles'=>['ingenieria-software','qa'],'claims'=>['a','b'],
    ]),csSupport(),'venture-condor');
    $changed=CustomerSuccessWorkOrigin::fromSupportSignal(csIntent(['work_type'=>'product']),csSupport(),'venture-condor');
    $out=['first'=>$first,'second'=>$second,'changed'=>$changed];
}elseif($case==='limited'){
    $unknown=csSupport(['freshness'=>'unknown','observed_at'=>null,'evidence_ref'=>null]);
    $stale=csSupport(['freshness'=>'stale']);
    $intent=csIntent(); unset($intent['observed_at']);
    $out=[
        'missing_observed'=>csBlocked(fn()=>CustomerSuccessWorkOrigin::fromSupportSignal($intent,csSupport(),'venture-condor')),
        'unknown'=>CustomerSuccessWorkOrigin::fromSupportSignal(csIntent(['work_id'=>'work-unknown']),$unknown,'venture-condor'),
        'stale'=>CustomerSuccessWorkOrigin::fromSupportSignal(csIntent(['work_id'=>'work-stale']),$stale,'venture-condor'),
        'churn'=>CustomerSuccessWorkOrigin::fromHealthException(csIntent(['work_id'=>'work-churn']),csSnapshot(),'churn_risk','venture-condor'),
    ];
}elseif($case==='optional'){
    $out=CustomerSuccessWorkOrigin::fromSupportSignal(csIntent([
        'project_id'=>'controlbot','repository_ref'=>'pl0n3r/ControlBot',
        'budget_ref'=>'capital:budget-1','approval_ref'=>'owner:approval-1',
    ]),csSupport(),'venture-condor');
}elseif($case==='pure'){
    $ref=new ReflectionClass(CustomerSuccessWorkOrigin::class);
    $methods=array_values(array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===CustomerSuccessWorkOrigin::class
    )));
    sort($methods,SORT_STRING); $out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown customer success work origin scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
