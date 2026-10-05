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
const EVIDENCE='github:evidence/main-05f6cf2';

function env(): array
{
    return GitHubIntentEnvelope::envelope([
        'version'=>1,'intent_id'=>str_repeat('1',32),
        'project_ref'=>'controlbot:project/controlbot',
        'repository_ref'=>'pl0n3r/ControlBot','type'=>'issue.create',
        'params'=>['payload_ref'=>'controlbot:payload/issue-create-1'],
        'idempotency_key'=>'intent:controlbot:679:1',
        'evidence_refs'=>[EVIDENCE],
    ]);
}

function ctx(int $seen=NOW-100,string $evidence=EVIDENCE): array
{
    return [
        'restrictions'=>[],'control_issue'=>'pl0n3r/ControlBot#679',
        'run_id'=>'11111111-1111-4111-8111-111111111111',
        'subject'=>'owner:pl0n3r','evidence_ref'=>$evidence,
        'evidence_observed_at'=>$seen,'evidence_max_age_seconds'=>300,
    ];
}

function foreignGrant(string $capability='hostinger.read',?string $approval=null): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'22222222-2222-4222-8222-222222222222',
        'capability'=>$capability,'project'=>'controlbot','environment'=>'github',
        'resource'=>'github:pl0n3r:controlbot','operation'=>'issue.create',
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
        'foreign'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(),NOW),
        'foreign_owner'=>GitHubIntentPolicy::evaluate(
            env(),
            foreignGrant('database.restore','33333333-3333-4333-8333-333333333333'),
            ctx(),
            NOW,
        ),
        'missing'=>GitHubIntentPolicy::evaluate(env(),null,ctx(),NOW),
    ];
}elseif($case==='closed'){
    $unknown=env();$unknown['type']='repo.shell';
    $ambiguous=env();$ambiguous['evidence_refs']=[EVIDENCE,EVIDENCE];
    $out=[
        'unknown'=>GitHubIntentPolicy::evaluate($unknown,null,ctx(),NOW),
        'stale'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(NOW-1000),NOW),
        'scope_mismatch'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(),NOW),
        'missing_authority'=>GitHubIntentPolicy::evaluate(env(),null,ctx(),NOW),
        'ambiguous'=>GitHubIntentPolicy::evaluate($ambiguous,null,ctx(),NOW),
    ];
}elseif($case==='binding'){
    $out=[
        'hostinger'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(),NOW),
        'database_owner'=>GitHubIntentPolicy::evaluate(
            env(),
            foreignGrant('database.restore','33333333-3333-4333-8333-333333333333'),
            ctx(),
            NOW,
        ),
    ];
}elseif($case==='freshness'){
    $out=[
        'unbound_ref'=>GitHubIntentPolicy::evaluate(
            env(),foreignGrant(),ctx(NOW-100,'github:evidence/other'),NOW
        ),
        'raw_fresh'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(),NOW),
        'stale_ref'=>GitHubIntentPolicy::evaluate(env(),foreignGrant(),ctx(NOW-1000),NOW),
    ];
}elseif($case==='invalid_context'){
    $execution=env();$execution['execution']=true;
    $extra=ctx();$extra['extra']='x';
    $future=ctx(NOW+1);
    $badTtl=ctx();$badTtl['evidence_max_age_seconds']=0;
    $badAuthority=ctx();$badAuthority['restrictions']='invalid';
    $out=[
        'execution'=>GitHubIntentPolicy::evaluate($execution,null,ctx(),NOW),
        'list_context'=>GitHubIntentPolicy::evaluate(env(),null,[],NOW),
        'extra_context'=>GitHubIntentPolicy::evaluate(env(),null,$extra,NOW),
        'zero_now'=>GitHubIntentPolicy::evaluate(env(),null,ctx(),0),
        'future_observed'=>GitHubIntentPolicy::evaluate(env(),null,$future,NOW),
        'bad_ttl'=>GitHubIntentPolicy::evaluate(env(),null,$badTtl,NOW),
        'bad_authority'=>GitHubIntentPolicy::evaluate(env(),null,$badAuthority,NOW),
    ];
}else{
    fwrite(STDERR,"Unknown github intent policy scenario\n");exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
