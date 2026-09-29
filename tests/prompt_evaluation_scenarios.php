<?php
declare(strict_types=1);

require __DIR__.'/../src/PromptEvaluation.php';

use ControlBot\Prompt\PromptEvaluation;

function rejected(callable $fn): bool {
    try {$fn(); return false;} catch (InvalidArgumentException) {return true;}
}
function setrow(array $r=[]): array {
    return array_replace([
        'evaluation_set_id'=>'support-v1','version'=>1,'task_class'=>'support',
        'sample_size'=>40,'metric_names'=>['acceptance_rate','rework_rate'],
    ],$r);
}
function resultrow(int $version,array $metrics,array $r=[]): array {
    return array_replace([
        'template_id'=>'support-agent','prompt_version'=>$version,'task_class'=>'support',
        'evaluation_set_id'=>'support-v1','evaluation_set_version'=>1,'sample_size'=>40,
        'metrics'=>$metrics,'safety_result'=>'pass','policy_result'=>'pass',
    ],$r);
}
$current=resultrow(1,['acceptance_rate'=>0.80,'rework_rate'=>0.20]);
$candidate=resultrow(2,['acceptance_rate'=>0.90,'rework_rate'=>0.10]);

$scenario=$argv[1]??'';
if($scenario==='compatibility'){
    echo json_encode([
        'valid'=>PromptEvaluation::compare(setrow(),$current,$candidate),
        'task'=>rejected(fn()=>PromptEvaluation::compare(setrow(['task_class'=>'other']),$current,$candidate)),
        'set'=>rejected(fn()=>PromptEvaluation::compare(setrow(['version'=>2]),$current,$candidate)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='metrics'){
    $bad=$candidate; $bad['metrics']=['unknown_metric'=>1.0];
    $shape=$candidate; $shape['metrics']=['acceptance_rate'=>0.9];
    $sample=$candidate; $sample['sample_size']=0;
    echo json_encode([
        'unknown'=>rejected(fn()=>PromptEvaluation::compare(setrow(),$current,$bad)),
        'shape'=>rejected(fn()=>PromptEvaluation::compare(setrow(),$current,$shape)),
        'sample'=>rejected(fn()=>PromptEvaluation::compare(setrow(),$current,$sample)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='safety'){
    $unsafe=$candidate; $unsafe['safety_result']='fail';
    $policy=$candidate; $policy['policy_result']='fail';
    echo json_encode([
        'unsafe'=>PromptEvaluation::compare(setrow(),$current,$unsafe),
        'policy'=>PromptEvaluation::compare(setrow(),$current,$policy),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='keep'){
    $tie=resultrow(2,['acceptance_rate'=>0.80,'rework_rate'=>0.20]);
    $smallCurrent=$current; $smallCurrent['sample_size']=10;
    $smallCandidate=$candidate; $smallCandidate['sample_size']=10;
    $smallSet=setrow(['sample_size'=>10]);
    echo json_encode([
        'tie'=>PromptEvaluation::compare(setrow(),$current,$tie),
        'small'=>PromptEvaluation::compare($smallSet,$smallCurrent,$smallCandidate,20),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='deterministic'){
    $a=PromptEvaluation::compare(setrow(),$current,$candidate);
    $b=PromptEvaluation::compare(
        setrow(['metric_names'=>['rework_rate','acceptance_rate']]),
        resultrow(1,['rework_rate'=>0.20,'acceptance_rate'=>0.80]),
        resultrow(2,['rework_rate'=>0.10,'acceptance_rate'=>0.90])
    );
    echo json_encode(['same'=>$a===$b,'a'=>$a,'b'=>$b],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($scenario==='pure'){
    $source=strtolower(file_get_contents(__DIR__.'/../src/PromptEvaluation.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','file_put_contents','scheduler','promptregistry::register'];
    $hits=[]; foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    $out=PromptEvaluation::compare(setrow(),$current,$candidate);
    echo json_encode(['hits'=>$hits,'authority'=>$out['authority'],'decision'=>$out['decision']],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown scenario\n"); exit(2);
