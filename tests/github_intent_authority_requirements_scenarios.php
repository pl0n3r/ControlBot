<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';
require __DIR__.'/../src/GitHubIntentAuthorityRequirements.php';
require __DIR__.'/../src/CapabilityPolicy.php';
use ControlBot\GitHub\GitHubIntentAuthorityRequirements as Requirements;
use ControlBot\Production\CapabilityPolicy;
const PROJECT = 'controlbot:project/controlbot';
const REPO = 'pl0n3r/ControlBot';

function intent(string $type): array {
    $params=[
        'issue.create'=>['payload_ref'=>'controlbot:payload/create'],
        'issue.update'=>['issue_number'=>824,'payload_ref'=>'controlbot:payload/update'],
        'issue.close'=>['issue_number'=>824],
        'issue.reserve'=>['issue_number'=>824,'reservation_ref'=>'controlbot:reservation/one'],
        'issue.release'=>['issue_number'=>824,'reservation_ref'=>'controlbot:reservation/one'],
        'pr.review'=>['pr_number'=>836,'review_ref'=>'github:review/one'],
        'pr.merge'=>['pr_number'=>836,'expected_head_sha'=>str_repeat('a',40),'merge_method'=>'squash'],
        'workflow.dispatch'=>['workflow_ref'=>'ci.yml','git_ref'=>'main','inputs_ref'=>'controlbot:inputs/one'],
        'release.approve'=>['release_ref'=>'github:release/one','candidate_sha'=>str_repeat('b',40),'approval_ref'=>'controlbot:approval/one'],
        'project.freeze'=>['reason_ref'=>'controlbot:reason/one'],
        'project.unfreeze'=>['reason_ref'=>'controlbot:reason/one'],
    ];
    return ['version'=>1,'intent_id'=>str_repeat('1',32),
        'project_ref'=>PROJECT,'repository_ref'=>REPO,'type'=>$type,
        'params'=>$params[$type]??[],'idempotency_key'=>'intent:controlbot:824:1',
        'evidence_refs'=>['github:evidence/main']];
}
$case=$argv[1]??'';
if($case==='catalog'){
    $out=[];
    foreach(['issue.create','issue.update','issue.close','issue.reserve','issue.release','pr.review',
             'pr.merge','workflow.dispatch','release.approve','project.freeze','project.unfreeze'] as $type){
        $row=Requirements::describe(intent($type),PROJECT,REPO);
        $out[$type]=['requirements'=>$row,'policy'=>CapabilityPolicy::classify($row['capability'])];
    }
}elseif($case==='fail_closed'){
    $base=intent('issue.create');
    $unknown=$base;$unknown['type']='repo.shell';
    $extra=$base;$extra['grant_issued']=true;
    $execution=$base;$execution['execution']=false;
    $duplicate=$base;$duplicate['evidence_refs'][]='github:evidence/main';
    $wildcard=$base;$wildcard['repository_ref']='*/*';
    $params=$base;$params['params']['admin']=true;
    $alternate=$base;$alternate['repository_ref']='other/Repo';
    $approval=$base;$approval['approval_verified']=true;
    $cases=[
        'unknown'=>[$unknown,PROJECT,REPO],
        'extra_authority'=>[$extra,PROJECT,REPO],
        'execution_spoof'=>[$execution,PROJECT,REPO],
        'duplicate'=>[$duplicate,PROJECT,REPO],
        'wildcard'=>[$wildcard,PROJECT,REPO],
        'wrong_params'=>[$params,PROJECT,REPO],
        'wrong_repository'=>[$alternate,PROJECT,REPO],
        'approval_spoof'=>[$approval,PROJECT,REPO],
        'project_mismatch'=>[$base,'controlbot:project/another',REPO],
        'repo_mismatch'=>[$base,PROJECT,'other/Repo'],
        'dot_owner'=>[$base,PROJECT,'./ControlBot'],
        'dot_repository'=>[$base,PROJECT,'pl0n3r/.'],
    ];
    $out=[];
    foreach($cases as $name=>[$raw,$project,$repo])
        $out[$name]=Requirements::describe($raw,$project,$repo);
}elseif($case==='boundary'){
    $out=['declaration'=>Requirements::describe(intent('issue.create'),PROJECT,REPO),
          'different_scope'=>Requirements::describe(intent('issue.create'),PROJECT,'other/Repo')];
}else{fwrite(STDERR,"Unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
