<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GitHubProjectEntrypoint.php';

use ControlBot\GitHub\GitHubProjectEntrypoint;

function project_snapshot(string $id, string $repo, int $observedAt = 900): array
{
    return ['version'=>1,'project_id'=>$id,'observed_at'=>$observedAt,'repositories'=>[[
        'repository_id'=>$id.'-repo','repository'=>$repo,'source_ref'=>'https://github.com/'.$repo,
        'observed_at'=>$observedAt,'main_sha'=>str_repeat('a',40),
        'checks'=>['items'=>[],'truncated'=>false],
        'pull_requests'=>['items'=>[],'truncated'=>false],
        'issues'=>['items'=>[],'truncated'=>false],
        'latest_release'=>null,'latest_workflow'=>null,
    ]]];
}

function projection(array $projects): string
{
    $path=tempnam(sys_get_temp_dir(),'cb-project-');
    if(!is_string($path)) throw new RuntimeException('temp failed');
    file_put_contents(
        $path,
        json_encode(['version'=>1,'projects'=>$projects],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
    );
    return $path;
}

function call_entry(string $path,string $route,string $method='GET',string $user='owner'): array
{
    return GitHubProjectEntrypoint::handle(
        ['method'=>$method,'path'=>$route,'remote_user'=>$user],
        ['owner_login'=>'owner','max_age_seconds'=>300],
        1000,
        $path
    );
}

$scenario=$argv[1]??'';
if($scenario==='exact'){
    $path=projection([
        project_snapshot('alpha-project','pl0n3r/Alpha'),
        project_snapshot('beta-project','pl0n3r/Beta'),
    ]);
    try{
        echo json_encode([
            'alpha'=>call_entry($path,'/projects/alpha-project/github'),
            'stale'=>GitHubProjectEntrypoint::handle(
                ['method'=>'GET','path'=>'/projects/alpha-project/github','remote_user'=>'owner'],
                ['owner_login'=>'owner','max_age_seconds'=>10],
                1000,
                $path
            ),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }finally{@unlink($path);}
    exit;
}

if($scenario==='closed'){
    $path=projection([
        project_snapshot('alpha-project','pl0n3r/Alpha'),
        project_snapshot('beta-project','pl0n3r/Beta'),
    ]);
    $dup=projection([
        project_snapshot('alpha-project','pl0n3r/Alpha'),
        project_snapshot('alpha-project','pl0n3r/Shadow'),
    ]);
    $invalid=tempnam(sys_get_temp_dir(),'cb-project-invalid-');
    if(!is_string($invalid)) throw new RuntimeException('temp failed');
    file_put_contents($invalid,'{invalid');
    try{
        echo json_encode([
            'unknown'=>call_entry($path,'/projects/missing-project/github'),
            'malformed'=>call_entry($path,'/projects/../github'),
            'duplicate'=>call_entry($dup,'/projects/alpha-project/github'),
            'invalid_projection'=>call_entry($invalid,'/projects/alpha-project/github'),
            'wrong_owner'=>call_entry($path,'/projects/alpha-project/github','GET','intruder'),
            'wrong_method'=>call_entry($path,'/projects/alpha-project/github','POST'),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }finally{@unlink($path);@unlink($dup);@unlink($invalid);}
    exit;
}

fwrite(STDERR,"unknown scenario\n");
exit(2);
