<?php
declare(strict_types=1);

require __DIR__ . '/../src/ProjectModel.php';

use ControlBot\Project\ProjectModel;

$name = $argv[1] ?? '';

function aggregates(array $overrides = []): array
{
    return array_replace([
        'roadmap' => null,
        'agents' => null,
        'decisions' => null,
        'health' => null,
        'incidents' => null,
        'costs' => null,
    ], $overrides);
}

function project(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'project_id' => 'project-controlbot',
        'slug' => 'controlbot',
        'title' => 'ControlBot',
        'phase' => 'building',
        'priority' => 'critical',
        'repositories' => [],
        'environments' => [],
        'aggregate_refs' => aggregates(),
        'history_refs' => [],
    ], $overrides);
}

function repo(string $id, string $name, int $at = 2000): array
{
    return [
        'repository_id' => $id,
        'repository' => $name,
        'source_ref' => 'https://github.com/' . $name,
        'observed_at' => $at,
    ];
}

function env(string $id, string $kind, string $source, int $at = 2000): array
{
    return [
        'environment_id' => $id,
        'kind' => $kind,
        'source_ref' => $source,
        'observed_at' => $at,
    ];
}

function blockedNormalize(array $overrides): array
{
    try {
        ProjectModel::normalize(project($overrides));
        return ['blocked' => false];
    } catch (InvalidArgumentException) {
        return ['blocked' => true];
    }
}

$invalidScenarios = [
    'aggregate-state' => [
        'aggregate_refs' => aggregates([
            'health' => [
                'ref' => 'controlbot:health/project-controlbot',
                'observed_at' => 1980,
                'status' => 'healthy',
            ],
        ]),
    ],
    'duplicate' => [
        'repositories' => [
            repo('repo-controlbot', 'pl0n3r/ControlBot'),
            repo('repo-controlbot-2', 'pl0n3r/ControlBot'),
        ],
    ],
    'duplicate-environment' => [
        'environments' => [
            env('env-prod', 'prod', 'controlbot:env/controlbot/prod'),
            env('env-prod', 'staging', 'controlbot:env/controlbot/staging'),
        ],
    ],
    'duplicate-environment-source' => [
        'environments' => [
            env('env-prod', 'prod', 'controlbot:env/controlbot/shared'),
            env('env-staging', 'staging', 'controlbot:env/controlbot/shared'),
        ],
    ],
    'duplicate-repository-case' => [
        'repositories' => [
            repo('repo-controlbot', 'pl0n3r/ControlBot'),
            repo('repo-controlbot-copy', 'pl0n3r/controlbot'),
        ],
    ],
];

if (array_key_exists($name, $invalidScenarios)) {
    $out = blockedNormalize($invalidScenarios[$name]);
} elseif ($name === 'empty') {
    $out = ProjectModel::normalize(project());
} elseif ($name === 'multiple') {
    $out = ProjectModel::normalize(project([
        'repositories' => [
            repo('repo-controlbot', 'pl0n3r/ControlBot'),
            repo('repo-runner', 'pl0n3r/FactoryRunner'),
        ],
        'environments' => [
            env('env-dev', 'dev', 'controlbot:env/controlbot/dev'),
            env('env-prod', 'prod', 'controlbot:env/controlbot/prod'),
        ],
    ]));
} elseif ($name === 'aggregate') {
    $out = ProjectModel::normalize(project([
        'aggregate_refs' => aggregates([
            'roadmap' => ['ref' => 'https://github.com/pl0n3r/ControlBot/issues/1', 'observed_at' => 2000],
            'agents' => ['ref' => 'controlbot:runtime/project-controlbot', 'observed_at' => 1990],
            'health' => ['ref' => 'controlbot:health/project-controlbot', 'observed_at' => 1980],
            'costs' => ['ref' => 'controlbot:budget/project-controlbot', 'observed_at' => 1970],
        ]),
    ]));
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
} elseif ($name === 'reassociate-renamed') {
    $base = project([
        'repositories' => [repo('repo-controlbot', 'pl0n3r/ControlBot')],
    ]);
    $out = ProjectModel::reassociateRepositories($base, [
        repo('repo-controlbot', 'pl0n3r/controlbot-renamed', 2100),
    ]);
} elseif ($name === 'reassociate-full-history') {
    $history = [];
    for ($i = 1; $i <= 100; $i++) {
        $history[] = "controlbot:history/{$i}";
    }
    $base = project([
        'repositories' => [repo('repo-runner', 'pl0n3r/FactoryRunner')],
        'history_refs' => $history,
    ]);
    $out = ProjectModel::reassociateRepositories($base, []);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
