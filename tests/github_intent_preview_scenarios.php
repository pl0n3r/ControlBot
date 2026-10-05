<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';
require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/CapabilityGrant.php';
require __DIR__.'/../src/GitHubIntentPolicy.php';
require __DIR__.'/../src/GitHubIntentPreview.php';

use ControlBot\GitHub\GitHubIntentEnvelope;
use ControlBot\GitHub\GitHubIntentPolicy;
use ControlBot\GitHub\GitHubIntentPreview;
use ControlBot\Production\CapabilityGrant;

const NOW=1791158400;

function env(): array
{
    return GitHubIntentEnvelope::envelope([
        'version'=>1,'intent_id'=>str_repeat('4',32),
        'project_ref'=>'controlbot:project/controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','type'=>'issue.create',
        'params'=>['payload_ref'=>'controlbot:payload/issue-create-680'],
        'idempotency_key'=>'intent:controlbot:680:1',
        'evidence_refs'=>['github:evidence/main-96c31c2','controlbot:evidence/policy-679'],
    ]);
}

function ctx(string $capability='hostinger.read'): array
{
    return [
        'capability'=>$capability,'restrictions'=>[],
        'control_issue'=>'pl0n3r/ControlBot#680',
        'run_id'=>'55555555-5555-4555-8555-555555555555',
        'subject'=>'owner:pl0n3r','evidence_observed_at'=>NOW-60,
        'evidence_max_age_seconds'=>300,
    ];
}

function grant(): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'66666666-6666-4666-8666-666666666666',
        'capability'=>'hostinger.read','project'=>'controlbot','environment'=>'github',
        'resource'=>'github:pl0n3r:controlbot','operation'=>'issue.create',
        'issue'=>'pl0n3r/ControlBot#680','run_id'=>'55555555-5555-4555-8555-555555555555',
        'subject'=>'owner:pl0n3r','issued_at'=>'2026-10-04T23:55:00Z',
        'expires_at'=>'2026-10-05T00:25:00Z',
        'backup_receipt_id'=>null,'owner_approval_id'=>null,
        'idempotency_key'=>'grant:intent:680:1','revoked_at'=>null,
    ]);
}

$case=$argv[1]??'';
if($case==='preview'){
    $intent=env();
    $allow=GitHubIntentPolicy::evaluate($intent,grant(),ctx(),NOW);
    $owner=GitHubIntentPolicy::evaluate($intent,null,ctx('database.restore'),NOW);
    $out=[
        'allow'=>GitHubIntentPreview::preview($intent,$allow),
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
