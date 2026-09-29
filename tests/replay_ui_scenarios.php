<?php
declare(strict_types=1);
require __DIR__.'/../src/AgentReplayCore.php';
require __DIR__.'/../src/AgentReplayLifecycle.php';
require __DIR__.'/../src/ReplayUi.php';

use ControlBot\Replay\ReplayUi;

function ui_event(string $id,string $source,string $kind,int $at,array $x=[]): array {
    return array_replace([
        'event_id'=>$id,'occurred_at'=>$at,'observed_at'=>$at+1,'sequence'=>$at,
        'source'=>$source,'kind'=>$kind,'actor_type'=>'agent','actor_ref'=>'agent:replay-ui',
        'work_item_id'=>'controlbot-383','project_id'=>'controlbot','repo'=>'pl0n3r/ControlBot',
        'issue_number'=>383,'pr_number'=>380,'commit_sha'=>str_repeat('b',40),
        'execution_order_id'=>null,'execution_event_id'=>null,
        'summary'=>'Replay UI evidence '.$id,
        'evidence_ref'=>'controlbot:evidence/'.$id,
        'payload_digest'=>hash('sha256',$id.'|'.$kind),
    ],$x);
}
function ui_row(string $category,string $stage,array $event): array {
    return ['category'=>$category,'stage'=>$stage,'event'=>$event];
}
function output_ui(array $rows,string $active='all'): void {
    echo ReplayUi::render($rows,$active),PHP_EOL;
}

$case=$argv[1]??'';
$active=$argv[2]??'all';

if($case==='filters'){
    output_ui([
        ui_row('code','commit',ui_event('evt-code','github_commit','success',100,['summary'=>'Code commit evidence','evidence_ref'=>'https://github.com/pl0n3r/ControlBot/commit/'.str_repeat('b',40)])),
        ui_row('ci','check',ui_event('evt-ci','github_actions','success',101,['summary'=>'CI check evidence','evidence_ref'=>'https://github.com/pl0n3r/ControlBot/actions/runs/123'])),
        ui_row('coordination','reservation',ui_event('evt-coordination','factory','success',102,['summary'=>'Coordination reservation evidence'])),
        ui_row('decisions','plan',ui_event('evt-decision','controlbot','requested',103,['summary'=>'Decision evidence'])),
        ui_row('production','deploy',ui_event('evt-production','production','success',104,['summary'=>'Production deploy evidence'])),
        ui_row('security','check',ui_event('evt-security','github_actions','blocked',105,['summary'=>'Security review evidence'])),
    ],$active);exit;
}
if($case==='event_rows'){
    output_ui([
        ui_row('code','commit',ui_event('evt-row','github_commit','success',100,[
            'actor_type'=>'agent','actor_ref'=>'agent:alpha','summary'=>'Verified code change',
            'evidence_ref'=>'external:change/evt-row'
        ])),
    ]);exit;
}
if($case==='states'){
    $rows=[
        ui_row('ci','check',ui_event('evt-success','github_actions','success',100)),
        ui_row('ci','check',ui_event('evt-startup','github_actions','startup_failure',101)),
        ui_row('ci','check',ui_event('evt-skip','github_actions','skipped',102)),
        ui_row('ci','check',ui_event('evt-fail','github_actions','failure',103)),
        ui_row('ci','check',ui_event('evt-conflict','github_actions','success',104,['summary'=>'Conflicting success','payload_digest'=>hash('sha256','conflict-a')])),
        ui_row('ci','check',ui_event('evt-conflict','github_actions','failure',104,['summary'=>'Conflicting failure','payload_digest'=>hash('sha256','conflict-b')])),
    ];
    output_ui($rows);exit;
}
if($case==='explicit'){
    output_ui([
        ui_row('security','check',ui_event('evt-explicit','github_actions','blocked',100,['summary'=>'Explicit security category'])),
    ],$active);exit;
}
if($case==='contradictory'){
    try{
        $event=ui_event('evt-category','github_actions','success',100);
        output_ui([ui_row('ci','check',$event),ui_row('security','check',$event)]);
    }catch(Throwable $e){echo json_encode(['rejected'=>true],JSON_THROW_ON_ERROR),PHP_EOL;}
    exit;
}
if($case==='escape'){
    output_ui([
        ui_row('code','commit',ui_event('evt-escape','github_commit','success',100,['summary'=>'<script>alert(1)</script>'])),
    ]);exit;
}
if($case==='secret'){
    try{
        output_ui([ui_row('code','commit',ui_event('evt-secret','github_commit','success',100,['summary'=>'api_key=abcdefghijklmnop']))]);
    }catch(Throwable $e){echo json_encode(['rejected'=>true],JSON_THROW_ON_ERROR),PHP_EOL;}
    exit;
}
fwrite(STDERR,"Unknown replay UI scenario\n");exit(2);
