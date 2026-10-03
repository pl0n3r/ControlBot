<?php
declare(strict_types=1);

require __DIR__ . '/../src/ProjectUi.php';

use ControlBot\Project\ProjectUi;

function fixture(string $id, string $slug, string $title, string $repo): array
{
    return [
        'version'=>1,'project_id'=>$id,'slug'=>$slug,'title'=>$title,'phase'=>'building','priority'=>'high',
        'repositories'=>[['repository_id'=>'repo-'.$slug,'repository'=>$repo,'source_ref'=>'https://github.com/'.$repo,'observed_at'=>1000]],
        'environments'=>[['environment_id'=>'prod-'.$slug,'kind'=>'prod','source_ref'=>'controlbot:environment/'.$id.'/prod','observed_at'=>1001]],
        'aggregate_refs'=>[
            'roadmap'=>['ref'=>'https://github.com/'.$repo.'/issues/1','observed_at'=>1002],
            'agents'=>['ref'=>'controlbot:agents/'.$id,'observed_at'=>1003],
            'decisions'=>['ref'=>'controlbot:decisions/'.$id,'observed_at'=>1004],
            'health'=>['ref'=>'controlbot:health/'.$id,'observed_at'=>1005],
            'incidents'=>['ref'=>'controlbot:incidents/'.$id,'observed_at'=>1006],
            'costs'=>['ref'=>'controlbot:costs/'.$id,'observed_at'=>1007],
        ],
        'history_refs'=>[],
    ];
}

$scenario = $argv[1] ?? '';
if ($scenario === 'list') {
    echo ProjectUi::renderList([
        fixture('project-controlbot','controlbot','ControlBot','pl0n3r/ControlBot'),
        fixture('project-condor','condor','Condor','pl0n3r/Condor'),
    ]);
    exit;
}
if ($scenario === 'detail') {
    echo ProjectUi::renderDetail(fixture('project-controlbot','controlbot','ControlBot','pl0n3r/ControlBot'));
    exit;
}
if ($scenario === 'missing') {
    $project = fixture('project-controlbot','controlbot','ControlBot','pl0n3r/ControlBot');
    foreach (array_keys($project['aggregate_refs']) as $key) $project['aggregate_refs'][$key] = null;
    echo ProjectUi::renderDetail($project);
    exit;
}
if ($scenario === 'escape') {
    echo ProjectUi::renderList([fixture('project-controlbot','controlbot','<script>alert(1)</script>','pl0n3r/ControlBot')]);
    exit;
}
fwrite(STDERR, "scenario inválido\n");
exit(2);
