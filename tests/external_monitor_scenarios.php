<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalMonitorCore.php';
use ControlBot\Observability\ExternalMonitorCore;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function probe(array $x=[]): array { return array_replace([
    'version'=>1,'endpoint_kind'=>'health','observed_at'=>1000,'outcome'=>'response','http_status'=>200,'latency_ms'=>120,
    'reported_version'=>'0.1.0','reported_sha'=>str_repeat('a',40),'freshness'=>'fresh','source_ref'=>'external:probe/1',
],$x); }
function workflow(string $visibility,string $conclusion,int $at,string $sha='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'): array {
    $startup=$conclusion==='startup_failure';$unknown=$conclusion==='unknown';
    return ['version'=>1,'visibility'=>$visibility,'observed_at'=>$at,'conclusion'=>$conclusion,
        'runner_assigned'=>$unknown?null:!$startup,'steps_present'=>$unknown?null:!$startup,'sha'=>$sha,
        'source_ref'=>'github:actions/'.$visibility.'/'.$at];
}
$case=$argv[1]??'';
if($case==='probe'){
    $bad=probe();$bad['http_status']=700;
    echo json_encode([
        'healthy'=>ExternalMonitorCore::assess(probe(),[],null,1010,60),
        'timeout'=>ExternalMonitorCore::assess(probe(['outcome'=>'timeout','http_status'=>null,'latency_ms'=>60000]),[],null,1010,60),
        'server'=>ExternalMonitorCore::assess(probe(['http_status'=>503]),[],null,1010,60),
        'invalid'=>rejected(fn()=>ExternalMonitorCore::probe($bad)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='freshness'){
    echo json_encode([
        'missing'=>ExternalMonitorCore::assess(null,[],null,1010,60),
        'stale'=>ExternalMonitorCore::assess(probe(['freshness'=>'stale']),[],null,1010,60),
        'unknown'=>ExternalMonitorCore::assess(probe(['freshness'=>'unknown']),[],null,1010,60),
        'expired'=>ExternalMonitorCore::assess(probe(['observed_at'=>900]),[],null,1010,60),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='workflow'){
    $bad=workflow('private','startup_failure',1100);$bad['runner_assigned']=true;
    echo json_encode([
        'success'=>ExternalMonitorCore::assess(null,[workflow('private','success',1000)],null,1200,60),
        'failure'=>ExternalMonitorCore::assess(null,[workflow('private','failure',1100)],null,1200,60),
        'startup'=>ExternalMonitorCore::assess(null,[workflow('private','startup_failure',1100)],null,1200,60),
        'startup_null_steps'=>ExternalMonitorCore::assess(null,[array_replace(workflow('private','startup_failure',1101),['steps_present'=>null])],null,1200,60),
        'invalid'=>rejected(fn()=>ExternalMonitorCore::workflow($bad)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='incident78'){
    $sha=str_repeat('c',40);
    $rows=[workflow('public','success',1190,str_repeat('d',40)),workflow('private','startup_failure',1150,$sha),workflow('private','success',1000,$sha)];
    echo json_encode(ExternalMonitorCore::assess(null,$rows,'exhausted',1200,60),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='alert'){
    $sha=str_repeat('c',40);$rows=[workflow('private','success',1000,$sha),workflow('private','startup_failure',1150,$sha),workflow('public','success',1190,str_repeat('d',40))];
    $secret=probe(['source_ref'=>'external:token=supersecret']);
    echo json_encode([
        'capacity'=>ExternalMonitorCore::assess(null,$rows,'exhausted',1200,60),
        'capacity_without_workflow'=>ExternalMonitorCore::assess(null,[],'exhausted',1200,60),
        'critical_capacity'=>ExternalMonitorCore::assess(null,[],'critical',1200,60),
        'outage'=>ExternalMonitorCore::assess(probe(['outcome'=>'timeout','http_status'=>null,'latency_ms'=>60000]),[],null,1010,60),
        'secret_rejected'=>rejected(fn()=>ExternalMonitorCore::probe($secret)),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='determinism'){
    $sha=str_repeat('e',40);
    $a=workflow('private','success',1100,$sha);$a['source_ref']='github:actions/private/tie-a';
    $b=workflow('private','startup_failure',1100,$sha);$b['source_ref']='github:actions/private/tie-b';
    $first=ExternalMonitorCore::assess(null,[$a,$b],'exhausted',1200,60);
    $second=ExternalMonitorCore::assess(null,[$b,$a],'exhausted',1200,60);
    echo json_encode(['first'=>$first,'second'=>$second],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown external monitor scenario\n");exit(2);
