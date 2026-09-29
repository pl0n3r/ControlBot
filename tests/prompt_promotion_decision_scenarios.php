<?php
declare(strict_types=1);

require __DIR__.'/../src/PromptRegistry.php';
require __DIR__.'/../src/PromptEvaluation.php';
require __DIR__.'/../src/PromptPromotionDecision.php';

use ControlBot\Prompt\PromptEvaluation;
use ControlBot\Prompt\PromptPromotionDecision;

function rejected(callable $fn): bool {
    try {$fn(); return false;} catch (InvalidArgumentException) {return true;}
}
function versionrow(int $version,string $status,?int $supersedes,array $replace=[]): array {
    return array_replace([
        'template_id'=>'support-agent','version'=>$version,'task_class'=>'support','provider_scope'=>'openai',
        'body_ref'=>'prompt:support/v'.$version,
        'variables_schema'=>['message'=>['type'=>'string','required'=>true]],
        'status'=>$status,'created_at'=>1000+$version,'created_by'=>'owner:pl0n3r','supersedes'=>$supersedes,
    ],$replace);
}
function history(array $currentReplace=[],array $candidateReplace=[]): array {
    return [
        versionrow(1,'approved',null,$currentReplace),
        versionrow(2,'candidate',1,$candidateReplace),
    ];
}
function evaluation(string $decision='candidate_better',array $replace=[],string $templateId='support-agent'): array {
    $set=[
        'evaluation_set_id'=>'support-v1','version'=>1,'task_class'=>'support',
        'sample_size'=>40,'metric_names'=>['acceptance_rate','rework_rate'],
    ];
    $current=[
        'template_id'=>$templateId,'prompt_version'=>1,'task_class'=>'support',
        'evaluation_set_id'=>'support-v1','evaluation_set_version'=>1,'sample_size'=>40,
        'metrics'=>['acceptance_rate'=>0.80,'rework_rate'=>0.20],
        'safety_result'=>'pass','policy_result'=>'pass',
    ];
    $candidate=[
        'template_id'=>$templateId,'prompt_version'=>2,'task_class'=>'support',
        'evaluation_set_id'=>'support-v1','evaluation_set_version'=>1,'sample_size'=>40,
        'metrics'=>$decision==='candidate_better'
            ? ['acceptance_rate'=>0.90,'rework_rate'=>0.10]
            : ['acceptance_rate'=>0.80,'rework_rate'=>0.20],
        'safety_result'=>'pass','policy_result'=>'pass',
    ];
    return array_replace(PromptEvaluation::compare($set,$current,$candidate),$replace);
}
function decide(?array $rows=null,?array $eval=null): array {
    return PromptPromotionDecision::decide($rows??history(),'support-agent',2,$eval??evaluation());
}

$scenario=$argv[1]??'';
if($scenario==='eligibility'){
    echo json_encode([
        'valid'=>decide(),
        'current_not_approved'=>rejected(fn()=>decide(history(['status'=>'candidate']))),
        'candidate_not_candidate'=>rejected(fn()=>decide(history([],['status'=>'approved']))),
        'wrong_successor'=>rejected(fn()=>decide(history([],['supersedes'=>null]))),
        'contract_drift'=>rejected(fn()=>decide(history([],['provider_scope'=>'anthropic']))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='binding'){
    $versionDrift=evaluation('candidate_better',['candidate_version'=>3]);
    $authority=evaluation('candidate_better',['authority'=>'human_approval_required']);
    $fingerprint=evaluation('candidate_better',['evaluation_set_fingerprint'=>str_repeat('a',64)]);
    $otherTemplate=evaluation('candidate_better',[],'other-agent');
    echo json_encode([
        'version'=>rejected(fn()=>decide(null,$versionDrift)),
        'authority'=>rejected(fn()=>decide(null,$authority)),
        'fingerprint'=>rejected(fn()=>decide(null,$fingerprint)),
        'template'=>rejected(fn()=>decide(null,$otherTemplate)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='decision'){
    echo json_encode([
        'better'=>decide(),
        'hold'=>decide(null,evaluation('keep_current')),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='human_gate'){
    $rows=history(); $before=$rows;
    $out=decide($rows);
    echo json_encode([
        'human_gate_required'=>$out['human_gate_required'],
        'authority'=>$out['authority'],
        'history_unchanged'=>$rows===$before,
        'current_status'=>$rows[0]['status'],
        'candidate_status'=>$rows[1]['status'],
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='deterministic'){
    $one=decide(); $two=decide();
    echo json_encode([
        'same'=>$one===$two,
        'fingerprint_same'=>$one['fingerprint']===$two['fingerprint'],
        'one'=>$one,
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='pure'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/PromptPromotionDecision.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','file_put_contents','scheduler','productionauthority'];
    $hits=[]; foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    $out=decide();
    echo json_encode(['hits'=>$hits,'authority'=>$out['authority'],'human_gate_required'=>$out['human_gate_required']],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown scenario\n"); exit(2);
