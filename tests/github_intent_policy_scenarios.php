<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';
require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/CapabilityGrant.php';
require __DIR__.'/../src/GitHubIntentPolicy.php';

use ControlBot\GitHub\GitHubIntentEnvelope;
use ControlBot\GitHub\GitHubIntentPolicy;
use ControlBot\Production\CapabilityGrant;

const NOW=1791157800;

function env(): array
{
    return GitHubIntentEnvelope::envelope([
        'version'=>1,'intent_id'=>str_repeat('1',32),
        'project_ref'=>'controlbot:project/controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','type'=>'issue.create',
        'params'=>['payload_ref'=>'controlbot:payload/issue-create-1'],
        'idempotency_key'=>'intent:controlbot:679:1',
        'evidence_refs'=>['github:evidence/main-05f6cf2'],
    ]);
}

function ctx(int $seen=NOW-100,string $capability='hostinger.read'): array
{
    return [
        'capability'=>$capability,'restrictions'=>[],
        'control_issue'=>'pl0n3r/ControlBot#679',
        'run_id'=>'11111111-1111-4111-8111-111111111111',
        'subject'=>'owner:pl0n3r','evidence_observed_at'=>$seen,
        'evidence_max_age_seconds'=>300,
    ];
}

function grant(string $resource='github:pl0n3r:controlbot',string $capability='hostinger.read',?string $approval=null): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'22222222-2222-4222-8222-222222222222',
        'capability'=>$capability,'project'=>'controlbot','environment'=>'github',
        'resource'=>$resource,'operation'=>'issue.create',
        'issue'=>'pl0n3r/ControlBot#679',
        'run_id'=>'11111111-1111-4111-8111-111111111111',
        'subject'=>'owner:pl0n3r','issued_at'=>'2026-10-04T23:45:00Z',
        'expires_at'=>'2026-10-05T00:15:00Z',
        'backup_receipt_id'=>null,'owner_approval_id'=>$approval,
        'idempotency_key'=>'grant:intent:679:1','revoked_at'=>null,
    ]);
}

$case=$argv[1]??'';
if($case==='reuse'){
    $out=[
        'allow'=>GitHubIntentPolicy::evaluate(env(),grant(),ctx(),NOW),
        'owner_required'=>GitHubIntentPolicy::evaluate(env(),null,ctx(NOW-100,'database.restore'),NOW),
        'owner_allow'=>GitHubIntentPolicy::evaluate(
            env(),
            grant('github:pl0n3r:controlbot','database.restore','33333333-3333-4333-8333-333333333333'),
            ctx(NOW-100,'database.restore'),
            NOW,
        ),
    ];
}elseif($case==='closed'){
    $unknown=env(); $unknown['type']='repo.shell';
    $ambiguous=env(); $ambiguous['evidence_refs']=['github:evidence/main-05f6cf2','github:evidence/main-05f6cf2'];
    $out=[
        'unknown'=>GitHubIntentPolicy::evaluate($unknown,null,ctx(),NOW),
        'stale'=>GitHubIntentPolicy::evaluate(env(),grant(),ctx(NOW-1000),NOW),
        'scope_mismatch'=>GitHubIntentPolicy::evaluate(env(),grant('github:pl0n3r:other'),ctx(),NOW),
        'missing_authority'=>GitHubIntentPolicy::evaluate(env(),null,ctx(),NOW),
        'ambiguous'=>GitHubIntentPolicy::evaluate($ambiguous,null,ctx(),NOW),
    ];
}else{
    fwrite(STDERR,"Unknown github intent policy scenario\n"); exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
