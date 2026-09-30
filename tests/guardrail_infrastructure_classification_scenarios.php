<?php
declare(strict_types=1);
require __DIR__.'/../src/SchedulerCore.php';
require __DIR__.'/../src/GuardrailInfrastructureClassification.php';

use ControlBot\Guardrail\GuardrailInfrastructureClassification;

$fp=str_repeat('a',64);
$other=str_repeat('b',64);
$agent=['health'=>'stuck','pause_required'=>true,'escalation_required'=>false,'alert_fingerprint'=>str_repeat('c',64)];

function runEvidence(string $source,string $visibility,bool $startup,int $runner,?array $steps,string $fp,string $dep,string $evidence,string $fresh='fresh',string $outcome='failure'): array{
    return [
        'source_ref'=>$source,'visibility'=>$visibility,'startup_failure'=>$startup,
        'runner_id'=>$runner,'steps'=>$steps,'outcome'=>$outcome,'failure_fingerprint'=>$fp,
        'dependency_ref'=>$dep,'evidence_ref'=>$evidence,'observed_at'=>2000,'freshness'=>$fresh,
    ];
}
function external(string $state,string $fp,string $fresh='fresh',int $observedAt=2000): array{
    return [
        'state'=>$state,'kind'=>'quota','dependency_ref'=>'controlbot:dependency/github-actions-private',
        'fingerprint'=>$fp,'evidence_ref'=>'controlbot:evidence/actions-quota','observed_at'=>$observedAt,'freshness'=>$fresh,
    ];
}

$onePre=GuardrailInfrastructureClassification::project(
    $agent,
    [runEvidence('pl0n3r/ControlBot#484','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-startup')],
    external('blocked',$fp)
);

$blockedRuns=[
    runEvidence('pl0n3r/ControlBot#484','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-startup'),
    runEvidence('pl0n3r/Condor#350','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/condor-startup'),
    runEvidence('pl0n3r/factory#424','public',false,42,[['name'=>'setup','status'=>'success']],$other,'controlbot:dependency/github-actions-public','controlbot:evidence/factory-healthy','fresh','success'),
];
$blocked=GuardrailInfrastructureClassification::project($agent,$blockedRuns,external('blocked',$fp));
$reversedRuns=[$blockedRuns[1],$blockedRuns[0],$blockedRuns[2]];
$blockedReversed=GuardrailInfrastructureClassification::project($agent,$reversedRuns,external('blocked',$fp));
$publicFailed=$blockedRuns;$publicFailed[2]['outcome']='failure';
$publicFailed=GuardrailInfrastructureClassification::project($agent,$publicFailed,external('blocked',$fp));
$publicUnknown=$blockedRuns;$publicUnknown[2]['outcome']='unknown';
$publicUnknown=GuardrailInfrastructureClassification::project($agent,$publicUnknown,external('blocked',$fp));
$publicNotStarted=$blockedRuns;
$publicNotStarted[2]['runner_id']=0;
$publicNotStarted[2]['steps']=null;
$publicNotStarted=GuardrailInfrastructureClassification::project($agent,$publicNotStarted,external('blocked',$fp));
$deduped=GuardrailInfrastructureClassification::project($agent,$blockedRuns,external('blocked',$fp),$fp);

$stale=GuardrailInfrastructureClassification::project($agent,$blockedRuns,external('blocked',$fp,'stale'));
$olderExternal=GuardrailInfrastructureClassification::project($agent,$blockedRuns,external('blocked',$fp,'fresh',1999));
$sameRepo=[
    runEvidence('pl0n3r/ControlBot#484','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-startup-a'),
    runEvidence('pl0n3r/ControlBot#484','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-startup-b'),
    $blockedRuns[2],
];
$duplicateSameRepo=GuardrailInfrastructureClassification::project($agent,$sameRepo,external('blocked',$fp));
$sameRepositoryDifferentIssues=[
    runEvidence('pl0n3r/ControlBot#484','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-484'),
    runEvidence('pl0n3r/ControlBot#485','private',true,0,null,$fp,'controlbot:dependency/github-actions-private','controlbot:evidence/controlbot-485'),
    $blockedRuns[2],
];
$sameRepositoryDifferentIssues=GuardrailInfrastructureClassification::project($agent,$sameRepositoryDifferentIssues,external('blocked',$fp));
$conflicting=$blockedRuns;
$conflicting[1]['failure_fingerprint']=$other;
$conflict=GuardrailInfrastructureClassification::project($agent,$conflicting,external('blocked',$fp));

$agentRun=[runEvidence('pl0n3r/ControlBot#484','private',false,42,[['name'=>'test','status'=>'failure']],$other,'controlbot:dependency/none','controlbot:evidence/agent-failure')];
$agentOnly=GuardrailInfrastructureClassification::project($agent,$agentRun,null);
$unresolvedExternalAgent=GuardrailInfrastructureClassification::project($agent,$agentRun,external('unknown',$other));
$olderHealthyAgent=GuardrailInfrastructureClassification::project($agent,$agentRun,external('healthy',$other,'fresh',1999));

$recovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,external('healthy',$other,'fresh',2001),$fp
);
$recoveryReversed=GuardrailInfrastructureClassification::project(
    $agent,$reversedRuns,external('healthy',$other,'fresh',2001),$fp
);
$sameTimestampRecovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,external('healthy',$other,'fresh',2000),$fp
);
$otherDependency=external('healthy',$other,'fresh',2001);
$otherDependency['dependency_ref']='controlbot:dependency/unrelated-capacity';
$unrelatedRecovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,$otherDependency,$fp
);
$mismatchedActiveRecovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,external('healthy',$other,'fresh',2001),$other
);
$unchangedFingerprintRecovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,external('healthy',$fp,'fresh',2001),$fp
);
$olderRecovery=GuardrailInfrastructureClassification::project(
    $agent,$blockedRuns,external('healthy',$other,'fresh',1999),$fp
);
$oldHealthy=external('healthy',$other);
$oldHealthy['observed_at']=1999;
$oldRecovery=GuardrailInfrastructureClassification::project($agent,$blockedRuns,$oldHealthy,$fp);

echo json_encode([
    'one_pre'=>$onePre,'blocked'=>$blocked,'blocked_reversed'=>$blockedReversed,
    'public_failed'=>$publicFailed,'public_unknown'=>$publicUnknown,
    'public_not_started'=>$publicNotStarted,'deduped'=>$deduped,
    'stale'=>$stale,'older_external'=>$olderExternal,'duplicate_same_repo'=>$duplicateSameRepo,
    'same_repository_different_issues'=>$sameRepositoryDifferentIssues,'conflict'=>$conflict,
    'agent_only'=>$agentOnly,'unresolved_external_agent'=>$unresolvedExternalAgent,'older_healthy_agent'=>$olderHealthyAgent,
    'recovery'=>$recovery,'recovery_reversed'=>$recoveryReversed,
    'same_timestamp_recovery'=>$sameTimestampRecovery,'unrelated_recovery'=>$unrelatedRecovery,
    'mismatched_active_recovery'=>$mismatchedActiveRecovery,
    'unchanged_fingerprint_recovery'=>$unchangedFingerprintRecovery,'older_recovery'=>$olderRecovery,'old_recovery'=>$oldRecovery,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
