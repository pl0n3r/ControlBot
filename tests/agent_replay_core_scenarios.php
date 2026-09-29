<?php
declare(strict_types=1);
require __DIR__.'/../src/AgentReplayCore.php';
use ControlBot\Replay\AgentReplayCore;

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function event(array $x=[]): array { return array_replace([
    'event_id'=>'evt-00000001','occurred_at'=>1000,'observed_at'=>1001,'sequence'=>1,
    'source'=>'github_actions','kind'=>'success','actor_type'=>'workflow','actor_ref'=>'github:actions/controlbot-ci',
    'work_item_id'=>'controlbot-365','project_id'=>'controlbot','repo'=>'pl0n3r/ControlBot','issue_number'=>365,'pr_number'=>null,
    'commit_sha'=>str_repeat('a',40),'execution_order_id'=>null,'execution_event_id'=>null,
    'summary'=>'CI completed successfully','evidence_ref'=>'https://github.com/pl0n3r/ControlBot/actions/runs/100',
    'payload_digest'=>str_repeat('1',64),
],$x); }

$case=$argv[1]??'';
if($case==='stable'){
    $a=event(['event_id'=>'evt-00000002','occurred_at'=>1000,'sequence'=>2,'payload_digest'=>str_repeat('2',64)]);
    $b=event(['event_id'=>'evt-00000001','occurred_at'=>1000,'sequence'=>1,'payload_digest'=>str_repeat('1',64)]);
    $first=AgentReplayCore::build([$a,$b,$b]);
    $second=AgentReplayCore::build([$b,$a]);
    echo json_encode(['first'=>$first,'second'=>$second],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='states'){
    $kinds=['success','failure','skipped','startup_failure'];$out=[];
    foreach($kinds as $n=>$kind)$out[]=event(['event_id'=>'evt-state-'.($n+1),'occurred_at'=>1100+$n,'observed_at'=>1200+$n,'sequence'=>$n,'kind'=>$kind,'payload_digest'=>hash('sha256',$kind)]);
    echo json_encode(AgentReplayCore::build($out),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='handoff'){
    $rows=[
        event(['event_id'=>'evt-handoff-1','kind'=>'handoff','actor_type'=>'agent','actor_ref'=>'agent:alpha','work_item_id'=>'controlbot-365','payload_digest'=>hash('sha256','handoff-a')]),
        event(['event_id'=>'evt-handoff-2','occurred_at'=>1002,'observed_at'=>1003,'sequence'=>2,'kind'=>'attempted','actor_type'=>'agent','actor_ref'=>'agent:beta','work_item_id'=>'controlbot-365','payload_digest'=>hash('sha256','handoff-b')]),
    ];
    echo json_encode(AgentReplayCore::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='conflict'){
    $a=event(['event_id'=>'evt-conflict-1','kind'=>'success','summary'=>'Workflow reported success','payload_digest'=>hash('sha256','variant-a')]);
    $b=event(['event_id'=>'evt-conflict-1','kind'=>'failure','summary'=>'Workflow reported failure','payload_digest'=>hash('sha256','variant-b')]);
    echo json_encode(AgentReplayCore::build([$b,$a]),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='sensitive'){
    $samples=[
        event(['summary'=>'token=supersecret']),
        event(['summary'=>'owner@example.com']),
        event(['summary'=>'full transcript from browser session']),
        event(['summary'=>'chain-of-thought reasoning trace']),
        event(['evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/365?token=secret']),
    ];
    echo json_encode(['rejected'=>array_map(fn($row)=>rejected(fn()=>AgentReplayCore::build([$row])),$samples)],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='purity'){
    $row=event();
    echo json_encode(['first'=>AgentReplayCore::build([$row]),'second'=>AgentReplayCore::build([$row])],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown replay scenario\n");exit(2);
