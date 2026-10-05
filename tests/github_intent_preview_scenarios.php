<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';
require __DIR__.'/../src/GitHubIntentPolicy.php';
require __DIR__.'/../src/GitHubIntentPreview.php';

use ControlBot\GitHub\GitHubIntentEnvelope;
use ControlBot\GitHub\GitHubIntentPolicy;
use ControlBot\GitHub\GitHubIntentPreview;

const NOW=1791158400;

function env(): array
{
    return GitHubIntentEnvelope::envelope([
        'version'=>1,'intent_id'=>str_repeat('4',32),
        'project_ref'=>'controlbot:project/controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','type'=>'issue.create',
        'params'=>['payload_ref'=>'controlbot:payload/issue-create-680'],
        'idempotency_key'=>'intent:controlbot:680:1',
        'evidence_refs'=>['github:evidence/main-7369763','controlbot:evidence/policy-687'],
    ]);
}

function ctx(): array
{
    return [
        'restrictions'=>[],
        'control_issue'=>'pl0n3r/ControlBot#680',
        'run_id'=>'55555555-5555-4555-8555-555555555555',
        'subject'=>'owner:pl0n3r',
        'evidence_ref'=>'github:evidence/main-7369763',
        'evidence_observed_at'=>NOW-60,
        'evidence_max_age_seconds'=>300,
    ];
}

$case=$argv[1]??'';
if($case==='preview'){
    $intent=env();
    $live=GitHubIntentPolicy::evaluate($intent,null,ctx(),NOW);
    $owner=[
        'decision'=>'owner_decision_required',
        'reasons'=>['owner_approval_required'],
        'execution'=>false,
    ];
    $out=[
        'live'=>GitHubIntentPreview::preview($intent,$live),
        'owner'=>GitHubIntentPreview::preview($intent,$owner),
    ];
}elseif($case==='safe'){
    $intent=env();
    $deny=['decision'=>'deny','reasons'=>['missing_authority'],'execution'=>false];
    $unknown=['decision'=>'unknown','reasons'=>['unknown_capability'],'execution'=>false];
    $sensitive=$intent;$sensitive['params']['payload_ref']='controlbot:payload/secret-token';
    $badPolicy=['decision'=>'allow','reasons'=>['token:secret'],'execution'=>false];
    $out=[
        'deny'=>GitHubIntentPreview::preview($intent,$deny),
        'unknown'=>GitHubIntentPreview::preview($intent,$unknown),
        'sensitive'=>GitHubIntentPreview::preview($sensitive,$deny),
        'bad_policy'=>GitHubIntentPreview::preview($intent,$badPolicy),
    ];
}else{
    fwrite(STDERR,"Unknown github intent preview scenario\n");exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
