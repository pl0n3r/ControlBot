<?php
declare(strict_types=1);

require __DIR__.'/../src/CustomerSuccessCore.php';

use ControlBot\CustomerSuccess\CustomerSuccessCore;

const VENTURE='venture-condor';

function opaque(string $namespace,string $hex): string { return $namespace.':'.str_repeat($hex,32); }

function dimension(string $name): array {
    $inferred=in_array($name,['churn_risk','renewal_signal'],true);
    return [
        'name'=>$name,'status'=>'measured','value_ref'=>opaque('metric','1'),
        'evidence_ref'=>opaque('evidence','2'),'freshness'=>'fresh','confidence'=>$inferred?0.7:1.0,
        'nature'=>$inferred?'inferred':'observed',
    ];
}
function dimensions(): array {
    return array_map('dimension',[
        'activation','adoption','churn_risk','onboarding','reliability_impact',
        'renewal_signal','satisfaction','support_burden','usage_recency',
    ]);
}
function snapshot(string $venture=VENTURE): array {
    return [
        'version'=>1,'snapshot_id'=>opaque('success','3'),'venture_id'=>$venture,
        'product_ref'=>opaque('product','4'),'observed_at'=>1000,'dimensions'=>dimensions(),
    ];
}
function support(string $venture=VENTURE): array {
    return [
        'version'=>1,'signal_id'=>opaque('support','5'),'venture_id'=>$venture,
        'product_ref'=>opaque('product','4'),'category'=>'bug','severity'=>'high',
        'pattern_ref'=>opaque('pattern','6'),'knowledge_ref'=>opaque('knowledge','7'),
        'resolution_state'=>'investigating','evidence_ref'=>opaque('evidence','8'),
        'freshness'=>'fresh','observed_at'=>995,'escalation_ref'=>opaque('escalation','9'),
    ];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    echo json_encode([
        'snapshot'=>CustomerSuccessCore::snapshot(snapshot(),VENTURE),
        'support'=>CustomerSuccessCore::supportSignal(support(),VENTURE),
        'snapshot_cross'=>blocked(fn()=>CustomerSuccessCore::snapshot(snapshot('venture-other'),VENTURE)),
        'support_cross'=>blocked(fn()=>CustomerSuccessCore::supportSignal(support('venture-other'),VENTURE)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='dimensions'){
    $row=CustomerSuccessCore::snapshot(snapshot(),VENTURE);
    $withScore=snapshot(); $withScore['health_score']=92;
    echo json_encode([
        'snapshot'=>$row,
        'score_rejected'=>blocked(fn()=>CustomerSuccessCore::snapshot($withScore,VENTURE)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='churn'){
    $valid=CustomerSuccessCore::snapshot(snapshot(),VENTURE);
    $observed=snapshot();
    foreach($observed['dimensions'] as &$d) if($d['name']==='churn_risk') $d['nature']='observed';
    unset($d);
    echo json_encode([
        'valid'=>$valid,
        'observed_churn_rejected'=>blocked(fn()=>CustomerSuccessCore::snapshot($observed,VENTURE)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='privacy'){
    $a=support(); $a['trans'.'cript']='raw';
    $b=support(); $b['ticket'.'_body']='raw';
    $c=support(); $c['pattern_ref']='pattern:JohnDoe';
    $d=support(); $d['knowledge_ref']='knowledge:573001234567';
    echo json_encode([
        'conversation_field_rejected'=>blocked(fn()=>CustomerSuccessCore::supportSignal($a,VENTURE)),
        'body_field_rejected'=>blocked(fn()=>CustomerSuccessCore::supportSignal($b,VENTURE)),
        'human_ref_rejected'=>blocked(fn()=>CustomerSuccessCore::supportSignal($c,VENTURE)),
        'phone_ref_rejected'=>blocked(fn()=>CustomerSuccessCore::supportSignal($d,VENTURE)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='freshness'){
    $unknown=support(); $unknown['freshness']='unknown'; $unknown['observed_at']=null; $unknown['evidence_ref']=null;
    $invalid=support(); $invalid['freshness']='unknown';
    $stale=snapshot();
    foreach($stale['dimensions'] as &$d) if($d['name']==='adoption') $d['freshness']='stale';
    unset($d);
    $unknownDim=snapshot();
    foreach($unknownDim['dimensions'] as &$d) if($d['name']==='usage_recency'){
        $d['status']='unknown';$d['value_ref']=null;$d['evidence_ref']=null;$d['freshness']='unknown';$d['confidence']=0.0;
    }
    unset($d);
    echo json_encode([
        'unknown'=>CustomerSuccessCore::supportSignal($unknown,VENTURE),
        'unknown_with_evidence_rejected'=>blocked(fn()=>CustomerSuccessCore::supportSignal($invalid,VENTURE)),
        'stale'=>CustomerSuccessCore::snapshot($stale,VENTURE),
        'unknown_dimension'=>CustomerSuccessCore::snapshot($unknownDim,VENTURE),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='pure'){
    $source=file_get_contents(__DIR__.'/../src/CustomerSuccessCore.php');
    echo json_encode(['source'=>$source],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown customer success scenario\n"); exit(2);
