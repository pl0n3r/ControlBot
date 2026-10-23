<?php
declare(strict_types=1);
require __DIR__.'/../src/GitHubIntentEnvelope.php';
require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/CapabilityGrant.php';
require __DIR__.'/../src/GitHubIntentPolicy.php';
require __DIR__.'/../src/GitHubIntentAuthorityRequirements.php';
require __DIR__.'/../src/GitHubIntentTrustedEvidence.php';

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


function server(): array
{
    return ['project_ref'=>'controlbot:project/controlbot', 'repository_ref'=>'pl0n3r/ControlBot'];
}

function trustedSnapshot(int $observed=NOW-120): array
{
    return [
        'version'=>1, 'project_id'=>'controlbot', 'observed_at'=>$observed,
        'repositories'=>[[
            'repository_id'=>'controlbot-repo',
            'repository'=>'pl0n3r/ControlBot',
            'source_ref'=>'https://github.com/pl0n3r/ControlBot',
            'observed_at'=>$observed,
            'main_sha'=>str_repeat('a', 40),
            'checks'=>['items'=>[], 'truncated'=>false],
            'pull_requests'=>['items'=>[], 'truncated'=>false],
            'issues'=>['items'=>[], 'truncated'=>false],
            'latest_release'=>null, 'latest_workflow'=>null,
        ]],
    ];
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
}elseif($case==='trusted_valid'){
    $injected=server();$injected['evidence_max_age_seconds']=900;
    $spoofed=env();$spoofed['evidence_observed_at']=NOW;
    $out=[
        'current'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),trustedSnapshot(),NOW),
        'no_caller_ttl'=>GitHubIntentPolicy::evaluateTrusted(env(),null,$injected,trustedSnapshot(),NOW),
        'no_caller_timestamp'=>GitHubIntentPolicy::evaluateTrusted($spoofed,null,server(),trustedSnapshot(),NOW),
        'last_valid_second'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),trustedSnapshot(NOW-300),NOW),
    ];
}elseif($case==='trusted_closed'){
    $other=server();$other['repository_ref']='other/Repo';
    $extra=server();$extra['evidence_ref']=EVIDENCE;
    $mismatch=env();$mismatch['repository_ref']='other/Repo';
    $duplicated=trustedSnapshot();$duplicated['repositories'][]=$duplicated['repositories'][0];
    $strange=env();$strange['type']='repo.shell';
    $out=[
        'missing_grant'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),trustedSnapshot(),NOW),
        'foreign_grant'=>GitHubIntentPolicy::evaluateTrusted(env(),foreignGrant(),server(),trustedSnapshot(),NOW),
        'foreign_owner_approval'=>GitHubIntentPolicy::evaluateTrusted(env(),foreignGrant('database.restore','33333333-3333-4333-8333-333333333333'),server(),trustedSnapshot(),NOW),
        'stale'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),trustedSnapshot(NOW-301),NOW),
        'mismatched_scope'=>GitHubIntentPolicy::evaluateTrusted(env(),null,$other,trustedSnapshot(),NOW),
        'mismatched_intent'=>GitHubIntentPolicy::evaluateTrusted($mismatch,null,server(),trustedSnapshot(),NOW),
        'extra_context'=>GitHubIntentPolicy::evaluateTrusted(env(),null,$extra,trustedSnapshot(),NOW),
        'duplicate_source'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),$duplicated,NOW),
        'unknown_type'=>GitHubIntentPolicy::evaluateTrusted($strange,null,server(),trustedSnapshot(),NOW),
        'invalid_clock'=>GitHubIntentPolicy::evaluateTrusted(env(),null,server(),trustedSnapshot(),0),
    ];
}else{
    fwrite(STDERR,"Unknown github intent policy scenario\n");exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
