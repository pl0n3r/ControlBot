<?php
declare(strict_types=1);
require __DIR__.'/../src/AgentReplayCore.php';
require __DIR__.'/../src/AgentReplayLifecycle.php';
use ControlBot\Replay\AgentReplayLifecycle;

function replay_event(string $id,string $source,string $kind,int $at,array $x=[]): array {
    $base=['event_id'=>$id,'occurred_at'=>$at,'observed_at'=>$at+1,'sequence'=>$at,'source'=>$source,'kind'=>$kind,'actor_type'=>'system','actor_ref'=>'system:replay','work_item_id'=>'controlbot-379','project_id'=>'controlbot','repo'=>'pl0n3r/ControlBot','issue_number'=>379,'pr_number'=>null,'commit_sha'=>str_repeat('a',40),'execution_order_id'=>null,'execution_event_id'=>null,'summary'=>'Verified replay evidence '.$id,'evidence_ref'=>'controlbot:evidence/'.$id,'payload_digest'=>hash('sha256',$id.'|'.$kind)];
    return array_replace($base,$x);
}
function entry(string $stage,array $event): array { return ['stage'=>$stage,'event'=>$event]; }

$case=$argv[1]??'';
if($case==='complete'){
    $rows=[entry('deploy',replay_event('evt-deploy','production','success',108)),entry('issue',replay_event('evt-issue','github_issue','requested',100)),entry('reservation',replay_event('evt-reservation','factory','success',101)),entry('plan',replay_event('evt-plan','controlbot','requested',102)),entry('commit',replay_event('evt-commit','github_commit','success',103)),entry('review',replay_event('evt-review','github_pr','success',104,['pr_number'=>379])),entry('check',replay_event('evt-check','github_actions','success',105,['pr_number'=>379])),entry('merge',replay_event('evt-merge','github_pr','success',106,['pr_number'=>379]))];
    echo json_encode(AgentReplayLifecycle::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='missing'){
    $rows=[entry('issue',replay_event('evt-missing-issue','github_issue','requested',100)),entry('plan',replay_event('evt-missing-plan','controlbot','requested',102)),entry('commit',replay_event('evt-missing-commit','github_commit','success',103))];
    echo json_encode(AgentReplayLifecycle::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='incident78'){
    $rows=[entry('check',replay_event('evt-78-success','github_actions','success',100,['summary'=>'Private workflow completed'])),entry('check',replay_event('evt-78-startup','github_actions','startup_failure',101,['summary'=>'Private workflow startup failure']))];
    echo json_encode(AgentReplayLifecycle::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pr86'){
    $rows=[entry('issue',replay_event('evt-86-issue','github_issue','requested',100,['issue_number'=>78])),entry('check',replay_event('evt-86-draft-skip','github_actions','skipped',101,['pr_number'=>86,'summary'=>'Draft-aware workflow skipped']))];
    echo json_encode(AgentReplayLifecycle::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='manual'){
    $rows=[entry('manual',replay_event('evt-manual','controlbot','attempted',100,['summary'=>'Manual action recorded'])),entry('documental',replay_event('evt-doc','github_issue','requested',101,['summary'=>'Documentation action recorded']))];
    echo json_encode(AgentReplayLifecycle::build($rows),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='determinism'){
    $a=entry('check',replay_event('evt-conflict','github_actions','success',100,['summary'=>'Check success','payload_digest'=>hash('sha256','variant-a')]));
    $b=entry('check',replay_event('evt-conflict','github_actions','failure',100,['summary'=>'Check failure','payload_digest'=>hash('sha256','variant-b')]));
    echo json_encode(['first'=>AgentReplayLifecycle::build([$a,$b]),'second'=>AgentReplayLifecycle::build([$b,$a])],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown lifecycle scenario\n");exit(2);
