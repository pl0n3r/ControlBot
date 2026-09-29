<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductDiscovery.php';
use ControlBot\Business\ProductDiscovery;

function ref(string $namespace,string $char): string { return $namespace.':'.str_repeat($char,32); }
function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function initiative(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'initiative_id'=>ref('initiative','1'),'scope'=>'venture','venture_id'=>'venture-condor',
        'market_ref'=>ref('market','2'),'problem_ref'=>ref('problem','3'),'segment_ref'=>ref('segment','4'),
        'evidence_refs'=>[ref('evidence','6'),ref('evidence','5')],'source_ref'=>ref('source','7'),
        'freshness'=>'fresh','confidence'=>'medium','state'=>'RESEARCHING','responsible_ref'=>ref('responsible','8'),
    ],$overrides);
}
function hypothesis(array $overrides=[]): array {
    return array_replace([
        'version'=>1,'hypothesis_ref'=>ref('hypothesis','9'),'initiative_id'=>ref('initiative','1'),
        'expected_outcome_ref'=>ref('outcome','a'),'primary_metric_ref'=>ref('metric','b'),
        'constraint_refs'=>[ref('constraint','d'),ref('constraint','c')],
        'evidence_refs'=>[ref('evidence','e')],'source_ref'=>ref('source','f'),
        'freshness'=>'fresh','confidence'=>'medium',
    ],$overrides);
}

$case=$argv[1]??'';
if($case==='initiative'){
    $raw=initiative();
    $group=initiative(['initiative_id'=>ref('initiative','a'),'scope'=>'group','venture_id'=>null,'market_ref'=>null,'evidence_refs'=>[],'state'=>'IDEA']);
    $out=[
        'venture'=>ProductDiscovery::initiative($raw),
        'group'=>ProductDiscovery::initiative($group),
        'has_repo'=>array_key_exists('repo',$raw)||array_key_exists('project_ref',$raw),
        'bad_group_scope'=>rejected(fn()=>ProductDiscovery::initiative(initiative(['scope'=>'group']))),
        'bad_venture_scope'=>rejected(fn()=>ProductDiscovery::initiative(initiative(['venture_id'=>null]))),
    ];
}elseif($case==='hypothesis'){
    $base=initiative(['state'=>'HYPOTHESIS']);
    $missingOutcome=hypothesis(); unset($missingOutcome['expected_outcome_ref']);
    $missingMetric=hypothesis(); unset($missingMetric['primary_metric_ref']);
    $wrong=hypothesis(['initiative_id'=>ref('initiative','0')]);
    $out=[
        'good'=>ProductDiscovery::hypothesis(hypothesis(),$base),
        'missing_outcome'=>rejected(fn()=>ProductDiscovery::hypothesis($missingOutcome,$base)),
        'missing_metric'=>rejected(fn()=>ProductDiscovery::hypothesis($missingMetric,$base)),
        'wrong_initiative'=>rejected(fn()=>ProductDiscovery::hypothesis($wrong,$base)),
    ];
}elseif($case==='freshness'){
    $stale=ProductDiscovery::initiative(initiative(['freshness'=>'stale','confidence'=>'medium']));
    $unknown=ProductDiscovery::initiative(initiative(['freshness'=>'unknown','confidence'=>'unknown']));
    $base=initiative(['state'=>'HYPOTHESIS']);
    $out=[
        'stale'=>$stale,'unknown'=>$unknown,
        'stale_high'=>rejected(fn()=>ProductDiscovery::initiative(initiative(['freshness'=>'stale','confidence'=>'high']))),
        'unknown_high'=>rejected(fn()=>ProductDiscovery::initiative(initiative(['freshness'=>'unknown','confidence'=>'high']))),
        'unknown_medium_hypothesis'=>rejected(fn()=>ProductDiscovery::hypothesis(hypothesis(['freshness'=>'unknown','confidence'=>'medium']),$base)),
    ];
}elseif($case==='closed'){
    $extra=initiative();$extra['repo']='pl0n3r/example';
    $badProblem=initiative(['problem_ref'=>'problem:customer@example.com']);
    $duplicate=initiative(['evidence_refs'=>[ref('evidence','5'),ref('evidence','5')]]);
    $badScope=initiative(['scope'=>'product']);
    $base=initiative(['state'=>'HYPOTHESIS']);
    $duplicateConstraints=hypothesis(['constraint_refs'=>[ref('constraint','c'),ref('constraint','c')]]);
    $extraHypothesis=hypothesis();$extraHypothesis['decision']='BUILD';
    $out=[
        'initiative'=>ProductDiscovery::initiative(initiative()),
        'hypothesis'=>ProductDiscovery::hypothesis(hypothesis(),$base),
        'extra'=>rejected(fn()=>ProductDiscovery::initiative($extra)),
        'bad_ref'=>rejected(fn()=>ProductDiscovery::initiative($badProblem)),
        'duplicate_evidence'=>rejected(fn()=>ProductDiscovery::initiative($duplicate)),
        'bad_scope'=>rejected(fn()=>ProductDiscovery::initiative($badScope)),
        'duplicate_constraints'=>rejected(fn()=>ProductDiscovery::hypothesis($duplicateConstraints,$base)),
        'extra_hypothesis'=>rejected(fn()=>ProductDiscovery::hypothesis($extraHypothesis,$base)),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ProductDiscovery::class);
    $methods=array_values(array_map(
        static fn(ReflectionMethod $m): string=>$m->getName(),
        array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ProductDiscovery::class)
    ));
    sort($methods,SORT_STRING);
    $out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown product discovery scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
