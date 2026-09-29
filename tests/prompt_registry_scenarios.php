<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PromptRegistry.php';
use ControlBot\Prompt\PromptRegistry;

function ver(int $n=1,string $status='approved',?int $sup=null,int $at=100): array {
    return ['template_id'=>'owner-briefing','version'=>$n,'task_class'=>'briefing','provider_scope'=>'openai',
        'body_ref'=>'prompt:owner-briefing/v'.$n,'variables_schema'=>[
            'project'=>['type'=>'string','required'=>true],'items'=>['type'=>'array','required'=>false]],
        'status'=>$status,'created_at'=>$at,'created_by'=>'owner:pl0n3r','supersedes'=>$sup];
}
function bad(callable $fn): bool {try{$fn();return false;}catch(\InvalidArgumentException){return true;}}
$case=$argv[1]??''; $out=null;

if($case==='immutable'){
    $first=ver(); $before=json_encode(PromptRegistry::register([],$first)[0],JSON_THROW_ON_ERROR);
    $history=PromptRegistry::register([$first],ver(2,'candidate',1,200));
    $out=['count'=>count($history),'first_unchanged'=>$before===json_encode($history[0],JSON_THROW_ON_ERROR),
        'versions'=>array_column($history,'version'),'second_status'=>$history[1]['status']];
}elseif($case==='approved'){
    $history=[ver(1,'approved',null,100),ver(2,'candidate',1,200),ver(3,'revoked',2,300)];
    $out=['selected'=>PromptRegistry::active($history,'owner-briefing'),
        'missing_fails_closed'=>bad(fn()=>PromptRegistry::active([ver(1,'draft'),ver(2,'candidate',1,200)],'owner-briefing'))];
}elseif($case==='invalid'){
    $base=ver(); $extra=$base;$extra['debug']=true; $state=$base;$state['status']='live';
    $ref=$base;$ref['body_ref']='https://example.invalid/prompt'; $time=$base;$time['created_at']=0;
    $schema=$base;$schema['variables_schema']=[['type'=>'string','required'=>true]];
    $drift=ver(2,'candidate',1,200);$drift['variables_schema']['locale']=['type'=>'string','required'=>false];
    $full=[];for($i=1;$i<=128;$i++)$full[]=ver($i,'draft',$i===1?null:$i-1,$i*100);
    $out=[
        'duplicate'=>bad(fn()=>PromptRegistry::active([$base,$base],'owner-briefing')),
        'extra'=>bad(fn()=>PromptRegistry::active([$extra],'owner-briefing')),
        'state'=>bad(fn()=>PromptRegistry::active([$state],'owner-briefing')),
        'ref'=>bad(fn()=>PromptRegistry::active([$ref],'owner-briefing')),
        'timestamp'=>bad(fn()=>PromptRegistry::active([$time],'owner-briefing')),
        'schema'=>bad(fn()=>PromptRegistry::active([$schema],'owner-briefing')),
        'schema_drift'=>bad(fn()=>PromptRegistry::register([$base],$drift)),
        'history_limit'=>bad(fn()=>PromptRegistry::register($full,ver(129,'candidate',128,12900))),
    ];
}elseif($case==='rollback'){
    $history=[ver(1,'approved'),ver(2,'deprecated',1,200),ver(3,'approved',2,300)];
    $before=json_encode($history,JSON_THROW_ON_ERROR);$target=PromptRegistry::rollback($history,'owner-briefing',1);
    $out=['current'=>PromptRegistry::active($history,'owner-briefing')['version'],'rollback'=>$target['version'],
        'history_unchanged'=>$before===json_encode($history,JSON_THROW_ON_ERROR),
        'non_approved_rejected'=>bad(fn()=>PromptRegistry::rollback($history,'owner-briefing',2))];
}elseif($case==='secret'){
    $variable=ver();$variable['variables_schema']['api_token']=['type'=>'string','required'=>true];
    $body=ver();$body['body_ref']='prompt:private-key/value';$actor=ver();$actor['created_by']='actor:cookie-store';
    $out=['variable'=>bad(fn()=>PromptRegistry::active([$variable],'owner-briefing')),
        'body'=>bad(fn()=>PromptRegistry::active([$body],'owner-briefing')),
        'actor'=>bad(fn()=>PromptRegistry::active([$actor],'owner-briefing'))];
}elseif($case==='pure'){
    $ref=new ReflectionClass(PromptRegistry::class);
    $methods=array_values(array_map(fn($m)=>$m->getName(),array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),fn($m)=>$m->class===PromptRegistry::class)));
    sort($methods);$source=file_get_contents(__DIR__.'/../src/PromptRegistry.php');
    if($source===false)throw new RuntimeException('Unable to read PromptRegistry source.');
    $out=['methods'=>$methods,'source'=>$source];
}else throw new \InvalidArgumentException('Unknown scenario.');

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
