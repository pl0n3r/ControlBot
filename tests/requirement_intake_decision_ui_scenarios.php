<?php
declare(strict_types=1);

require __DIR__.'/../src/RequirementIntake.php';
require __DIR__.'/../src/RequirementIntakeDecisionUi.php';

use ControlBot\Requirements\RequirementIntake;
use ControlBot\Requirements\RequirementIntakeDecisionUi;

$draft = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'text',
    'source_ref'=>'controlbot:requirement-source/decision-review',
    'captured_at'=>200,
    'content'=>"Problem: Support requests are manually triaged\nUser: product owner\nObjective: normalize requests; propose reviewable work\nOut of scope: deploy automatically\nDependency: controlbot project catalog\nControlBot requirement intake",
]);
$proposal = RequirementIntake::analyze($draft,[
    [
        'project_ref'=>'controlbot:project/controlbot',
        'title'=>'ControlBot',
        'aliases'=>['ControlBot requirement intake','decision center'],
    ],
]);
$view = RequirementIntakeDecisionUi::project($proposal);
$replay = RequirementIntakeDecisionUi::project($proposal);

$unknownDraft = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'text',
    'source_ref'=>'controlbot:requirement-source/decision-unknown',
    'captured_at'=>210,
    'content'=>"Problem: Intake is inconsistent\nUser: owner",
]);
$unknownProposal = RequirementIntake::analyze($unknownDraft,[]);
$unknownView = RequirementIntakeDecisionUi::project($unknownProposal);

$secretDraft = RequirementIntake::normalize([
    'version'=>1,
    'source_kind'=>'transcript',
    'source_ref'=>'controlbot:requirement-source/decision-redaction',
    'captured_at'=>220,
    'content'=>"Problem: connect provider\nUser: owner\npassword=hunter2 token=supersecrettoken bearer ABCDEFGHIJKLMNOPQRSTUVWXYZ",
]);
$secretProposal = RequirementIntake::analyze($secretDraft,[]);
$secretView = RequirementIntakeDecisionUi::project($secretProposal);

echo json_encode([
    'proposal'=>$proposal,
    'view'=>$view,
    'replay'=>$replay,
    'unknown_proposal'=>$unknownProposal,
    'unknown_view'=>$unknownView,
    'secret_view'=>$secretView,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
