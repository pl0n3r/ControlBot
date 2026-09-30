<?php
declare(strict_types=1);
require __DIR__.'/../src/PromptRegistry.php';
require __DIR__.'/../src/PromptEvaluation.php';
require __DIR__.'/../src/PromptPromotionDecision.php';
require __DIR__.'/../src/PromptUi.php';

use ControlBot\Prompt\PromptEvaluation;
use ControlBot\Prompt\PromptPromotionDecision;
use ControlBot\Prompt\PromptUi;

function rejected(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function ver(int $v,string $status,?int $sup,array $x=[]): array {return array_replace([
    'template_id'=>'support-agent','version'=>$v,'task_class'=>'support','provider_scope'=>'openai',
    'body_ref'=>'prompt:support/v'.$v,'variables_schema'=>['message'=>['type'=>'string','required'=>true]],
    'status'=>$status,'created_at'=>1000+$v,'created_by'=>'owner:pl0n3r','supersedes'=>$sup,
],$x);}
function history(): array {return [ver(1,'approved',null),ver(2,'deprecated',1),ver(3,'approved',2),ver(4,'candidate',3)];}
function setrow(int $sample=40): array {return ['evaluation_set_id'=>'support-v1','version'=>1,'task_class'=>'support','sample_size'=>$sample,'metric_names'=>['acceptance_rate','rework_rate']];}
function resultrow(int $v,array $metrics,int $sample=40,array $x=[]): array {return array_replace([
    'template_id'=>'support-agent','prompt_version'=>$v,'task_class'=>'support','evaluation_set_id'=>'support-v1',
    'evaluation_set_version'=>1,'sample_size'=>$sample,'metrics'=>$metrics,'safety_result'=>'pass','policy_result'=>'pass',
],$x);}
function bundle(string $mode='better'): array {
    $sample=$mode==='insufficient'?10:40; $set=setrow($sample);
    $current=resultrow(3,['acceptance_rate'=>0.80,'rework_rate'=>0.20],$sample);
    $candidate=resultrow(4,$mode==='tie'?['acceptance_rate'=>0.80,'rework_rate'=>0.20]:['acceptance_rate'=>0.90,'rework_rate'=>0.10],$sample);
    if($mode==='unsafe') $candidate['safety_result']='fail';
    $evaluation=PromptEvaluation::compare($set,$current,$candidate,20); $history=history();
    $promotion=PromptPromotionDecision::decide($history,'support-agent',4,$evaluation);
    return compact('history','set','current','candidate','promotion');
}
function render_case(string $mode='better'): string {$b=bundle($mode);return PromptUi::render($b['history'],$b['set'],$b['current'],$b['candidate'],$b['promotion']);}

$case=$argv[1]??'';
if($case==='projection'){echo render_case(),PHP_EOL;exit;}
if($case==='hold'){echo render_case('insufficient'),PHP_EOL;exit;}
if($case==='unsafe'){echo render_case('unsafe'),PHP_EOL;exit;}
if($case==='tamper'){
    $b=bundle();$b['promotion']['evaluation_set_fingerprint']=str_repeat('a',64);
    echo json_encode(['rejected'=>rejected(fn()=>PromptUi::render($b['history'],$b['set'],$b['current'],$b['candidate'],$b['promotion']))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='binding'){
    $b=bundle();$b['candidate']['evaluation_set_version']=2;
    echo json_encode(['rejected'=>rejected(fn()=>PromptUi::render($b['history'],$b['set'],$b['current'],$b['candidate'],$b['promotion']))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='secret'){
    $b=bundle();$b['history'][3]['body_ref']='prompt:api-token/value';
    echo json_encode(['rejected'=>rejected(fn()=>PromptUi::render($b['history'],$b['set'],$b['current'],$b['candidate'],$b['promotion']))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $source=strtolower((string)file_get_contents(__DIR__.'/../src/PromptUi.php'));
    $forbidden=['new pdo','mysqli','curl_','shell_exec','proc_open','passthru(','system(','exec(','file_put_contents','scheduler','promptregistry::register','promptevaluation::compare','promptpromotiondecision::decide'];
    $hits=[];foreach($forbidden as $needle)if(str_contains($source,$needle))$hits[]=$needle;
    echo json_encode(['hits'=>$hits,'escaper'=>str_contains($source,'htmlspecialchars(')],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown Prompt UI scenario\n");exit(2);
