<?php
declare(strict_types=1);

require __DIR__.'/../src/RequirementIntake.php';

use ControlBot\Requirements\RequirementIntake;

$free = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'text',
    'source_ref'=>'controlbot:requirement-source/free-text',
    'captured_at'=>100,
    'content'=>"Problem: Orders are tracked manually\nUser: operations team\nObjective: centralize orders; expose current status",
]);

$sharedContent="Problem: Requests arrive without structure\nUser: product owner\nObjective: normalize requirements";
$text = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'text',
    'source_ref'=>'controlbot:requirement-source/shared',
    'captured_at'=>110,
    'content'=>$sharedContent,
]);
$transcript = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'transcript',
    'source_ref'=>'controlbot:requirement-source/shared',
    'captured_at'=>110,
    'content'=>$sharedContent,
]);

$analysisDraft = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'text',
    'source_ref'=>'controlbot:requirement-source/epic-analysis',
    'captured_at'=>120,
    'content'=>"Problem: Customer requests are manually triaged\nUser: operations owner\nObjective: normalize requests; propose epics\nOut of scope: deploy automatically\nDependency: controlbot project catalog\nConstraint: no external mutations\nControlBot requirement intake",
]);
$proposal = RequirementIntake::analyze($analysisDraft,[
    [
        'project_ref'=>'controlbot:project/controlbot',
        'title'=>'ControlBot',
        'aliases'=>['ControlBot requirement intake','control center'],
    ],
    [
        'project_ref'=>'controlbot:project/other',
        'title'=>'Unrelated Project',
        'aliases'=>['warehouse'],
    ],
]);

$secretDraft = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'transcript',
    'source_ref'=>'controlbot:requirement-source/redaction',
    'captured_at'=>130,
    'content'=>"Problem: connect provider\nUser: owner\npassword=hunter2 token=supersecrettoken bearer ABCDEFGHIJKLMNOPQRSTUVWXYZ",
]);
$secretProposal = RequirementIntake::analyze($secretDraft,[]);

echo json_encode([
    'free'=>$free,
    'text'=>$text,
    'transcript'=>$transcript,
    'analysis_draft'=>$analysisDraft,
    'proposal'=>$proposal,
    'secret_draft'=>$secretDraft,
    'secret_proposal'=>$secretProposal,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
