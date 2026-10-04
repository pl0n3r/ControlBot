<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';

use ControlBot\GitHub\GitHubIntentEnvelope;

function bad(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

function raw(string $type, array $params, array $overrides=[]): array
{
    return array_replace([
        'version'=>1,
        'intent_id'=>'11111111111111111111111111111111',
        'project_ref'=>'controlbot:project/controlbot',
        'repository_ref'=>'pl0n3r/ControlBot',
        'type'=>$type,
        'params'=>$params,
        'idempotency_key'=>'intent:controlbot:678:1',
        'evidence_refs'=>[
            'controlbot:evidence/issue-677-a',
            'github:evidence/main-9d5c73e',
        ],
    ], $overrides);
}

$known = [
    'issue.create'=>['payload_ref'=>'controlbot:payload/issue-create-1'],
    'issue.update'=>['issue_number'=>55,'payload_ref'=>'controlbot:payload/issue-update-55'],
    'issue.close'=>['issue_number'=>55],
    'issue.reserve'=>['issue_number'=>55,'reservation_ref'=>'controlbot:reservation/reserve-55'],
    'issue.release'=>['issue_number'=>55,'reservation_ref'=>'controlbot:reservation/release-55'],
    'pr.review'=>['pr_number'=>678,'review_ref'=>'controlbot:review/pr-678'],
    'pr.merge'=>['pr_number'=>678,'expected_head_sha'=>str_repeat('a',40),'merge_method'=>'squash'],
    'workflow.dispatch'=>['workflow_ref'=>'ci.yml','git_ref'=>'main','inputs_ref'=>'controlbot:workflow-inputs/ci-main'],
    'release.approve'=>['release_ref'=>'controlbot:release/v1-0-0','candidate_sha'=>str_repeat('b',40),'approval_ref'=>'controlbot:approval/release-v1-0-0'],
    'project.freeze'=>['reason_ref'=>'controlbot:reason/freeze-1'],
    'project.unfreeze'=>['reason_ref'=>'controlbot:reason/unfreeze-1'],
];

$case=$argv[1]??'';
if($case==='known'){
    $rows=[];
    foreach($known as $type=>$params){
        $rows[$type]=GitHubIntentEnvelope::envelope(raw($type,$params));
    }
    $out=['rows'=>$rows];
}elseif($case==='invalid'){
    $extraTop=raw('issue.close',$known['issue.close']);$extraTop['command']='git push';
    $extraParam=raw('issue.close',['issue_number'=>55,'force'=>true]);
    $shell=raw('issue.create',['command_ref'=>'controlbot:payload/git-push']);
    $freeGit=raw('workflow.dispatch',['workflow_ref'=>'ci.yml','git'=>'push origin main','inputs_ref'=>'controlbot:workflow-inputs/ci-main']);
    $secret=raw('issue.create',['payload_ref'=>'controlbot:payload/secret-token']);
    $secretEvidence=raw('issue.close',$known['issue.close'],['evidence_refs'=>['controlbot:evidence/password-value']]);
    $out=[
        'unknown_type'=>bad(fn()=>GitHubIntentEnvelope::envelope(raw('repo.shell',['payload_ref'=>'controlbot:payload/x']))),
        'extra_top'=>bad(fn()=>GitHubIntentEnvelope::envelope($extraTop)),
        'extra_param'=>bad(fn()=>GitHubIntentEnvelope::envelope($extraParam)),
        'shell_freeform'=>bad(fn()=>GitHubIntentEnvelope::envelope($shell)),
        'git_freeform'=>bad(fn()=>GitHubIntentEnvelope::envelope($freeGit)),
        'secret_param'=>bad(fn()=>GitHubIntentEnvelope::envelope($secret)),
        'secret_evidence'=>bad(fn()=>GitHubIntentEnvelope::envelope($secretEvidence)),
        'bad_git_ref'=>bad(fn()=>GitHubIntentEnvelope::envelope(raw('workflow.dispatch',[
            'workflow_ref'=>'ci.yml','git_ref'=>'main;git push','inputs_ref'=>'controlbot:workflow-inputs/ci-main',
        ]))),
    ];
}else{
    fwrite(STDERR,"Unknown github intent envelope scenario\n");
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
