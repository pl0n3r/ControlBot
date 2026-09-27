<?php
declare(strict_types=1);

require __DIR__ . '/../src/ProjectModel.php';

use ControlBot\Project\ProjectModel;

$name = $argv[1] ?? '';

function aggregates(array $overrides = []): array {
    return array_replace([
        'roadmap' => null,
        'agents' => null,
        'decisions' => null,
        'health' => null,
        'incidents' => null,
        'costs' => null,
    ], $overrides);
}

function project(array $overrides = []): array {
    return array_replace([
        'version' => 1,
        'project_id' => 'project-controlbot',
        'slug' => 'controlbot',
        'name' => 'ControlBot',
        'phase' => 'building',
        'priority' => 'critical',
        'repositories' => [],
        'environments' => [],
        'aggregate_refs' => aggregates(),
        'history_refs' => [],
    ], $overrides);
}

function repo(string $id, string $name, int $at = 2000): array {
    return [
        'repository_id' => $id,
        'full_name' => $name,
        'source_ref' => 'https://github.com/' . $name,
        'observed_at' => $at,
    ];
}

if ($name === 'empty') {
    $out = ProjectModel::normalize(project());
} elseif ($name === 'multiple') {
    $out = ProjectModel::normalize(project([
        'repositories' => [
            repo('repo-controlbot', 'pl0n3r/ControlBot'),
            repo('repo-runner', 'pl0n3r/FactoryRunner'),
        ],
        'environments' => [
            ['environment_id'=>'env-dev','kind'=>'dev','source_ref'=>'controlbot:env/controlbot/dev','observed_at'=>2000],
            ['environment_id'=>'env-prod','kind'=>'prod','source_ref'=>'controlbot:env/controlbot/prod','observed_at'=>2000],
        ],
    ]));
} elseif ($name === 'aggregate') {
    $out = ProjectModel::normalize(project([
        'aggregate_refs' => aggregates([
            'roadmap' => ['ref'=>'https://github.com/pl0n3r/ControlBot/issues/1','observed_at'=>2000],
            'agents' => ['ref'=>'controlbot:runtime/project-controlbot','observed_at'=>1990],
            'health' => ['ref'=>'controlbot:health/project-controlbot','observed_at'=>1980],
            'costs' => ['ref'=>'controlbot:budget/project-controlbot','observed_at'=>1970],
        ]),
    ]));
} elseif ($name === 'aggregate-state') {
    try {
        ProjectModel::normalize(project([
            'aggregate_refs' => aggregates([
                'health' => ['ref'=>'controlbot:health/project-controlbot','observed_at'=>1980,'status'=>'healthy'],
            ]),
        ]));
        $out = ['blocked'=>false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked'=>true];
    }
} elseif ($name === 'duplicate') {
    try {
        ProjectModel::normalize(project([
            'repositories' => [
                repo('repo-controlbot', 'pl0n3r/ControlBot'),
                repo('repo-controlbot-2', 'pl0n3r/ControlBot'),
            ],
        ]));
        $out = ['blocked'=>false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked'=>true];
    }
} elseif ($name === 'reassociate') {
    $base = project([
        'repositories' => [
            repo('repo-controlbot', 'pl0n3r/ControlBot'),
            repo('repo-runner', 'pl0n3r/FactoryRunner'),
        ],
        'history_refs' => ['controlbot:project/project-controlbot/created'],
    ]);
    $out = ProjectModel::reassociateRepositories($base, [
        repo('repo-controlbot', 'pl0n3r/ControlBot', 2100),
    ]);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
