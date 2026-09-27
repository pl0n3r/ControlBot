<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/ProjectModel.php';
require __DIR__ . '/../src/ProjectProvisioning.php';
require __DIR__ . '/../src/ProjectBackfill.php';

use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;
use ControlBot\Project\GitHubFactoryProvisioningAdapter;
use ControlBot\Project\ProjectBackfill;
use ControlBot\Project\ProjectModel;
use ControlBot\Project\ProjectProvisioner;
use ControlBot\Project\ProjectProvisioningAdapter;

$name = $argv[1] ?? '';

final class FakeProjectProvisioningAdapter implements ProjectProvisioningAdapter
{
    public int $dispatches = 0;
    public array $lastInputs = [];

    public function dispatch(array $inputs): string
    {
        $this->dispatches++;
        $this->lastInputs = $inputs;
        return 'https://github.com/pl0n3r/factory/actions/workflows/provision-project.yml';
    }
}

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

function existingProjectDefinitions(): array
{
    return [
        [
            'project_id' => 'project-controlbot',
            'slug' => 'controlbot',
            'title' => 'ControlBot',
            'phase' => 'building',
            'priority' => 'critical',
            'repository' => 'pl0n3r/ControlBot',
        ],
        [
            'project_id' => 'project-condor',
            'slug' => 'condor',
            'title' => 'Condor',
            'phase' => 'building',
            'priority' => 'high',
            'repository' => 'pl0n3r/Condor',
        ],
        [
            'project_id' => 'project-brvtal',
            'slug' => 'brvtal',
            'title' => 'BRVTAL',
            'phase' => 'building',
            'priority' => 'high',
            'repository' => 'pl0n3r/brvtal',
        ],
        [
            'project_id' => 'project-grindflow',
            'slug' => 'grindflow',
            'title' => 'GrindFlow',
            'phase' => 'building',
            'priority' => 'high',
            'repository' => 'pl0n3r/GrindFlow',
        ],
    ];
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
} elseif ($name === 'provision') {
    $adapter = new FakeProjectProvisioningAdapter();
    $base = project(['phase' => 'planned']);
    $first = ProjectProvisioner::request($base, 'pl0n3r/NewProduct', true, $adapter, 2000);
    $confirmed = ProjectProvisioner::confirm($base, $first['repository']);
    $second = ProjectProvisioner::request($confirmed, 'pl0n3r/NewProduct', true, $adapter, 3000);
    $out = [
        'first' => $first,
        'confirmed_project' => $confirmed,
        'second' => $second,
        'dispatches' => $adapter->dispatches,
        'inputs' => $adapter->lastInputs,
    ];
} elseif ($name === 'provision-real-adapter') {
    $calls = [];
    $sender = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = [$method, $url, $headers, $body];
        if (str_contains((string) parse_url($url, PHP_URL_PATH), '/actions/workflows/provision-project.yml/dispatches')) {
            return ['status' => 204, 'body' => ''];
        }
        return ['status' => 404, 'body' => '{}'];
    };
    $gateway = new Gateway(new ApiClient('ghp_test_only_token', new ApiTransport($sender)));
    $adapter = new GitHubFactoryProvisioningAdapter($gateway);
    $base = project(['phase' => 'planned']);
    $result = ProjectProvisioner::request($base, 'pl0n3r/NewProduct', true, $adapter, 2000);
    $out = [
        'result' => $result,
        'calls' => $calls,
    ];
} elseif ($name === 'provision-real-adapter-invalid') {
    $calls = [];
    $sender = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = [$method, $url, $headers, $body];
        return ['status' => 204, 'body' => ''];
    };
    $gateway = new Gateway(new ApiClient('ghp_test_only_token', new ApiTransport($sender)));
    $adapter = new GitHubFactoryProvisioningAdapter($gateway);
    $base = [
        'project_id' => 'project-controlbot',
        'project_slug' => 'controlbot',
        'target_repository' => 'pl0n3r/NewProduct',
        'governance_ref' => 'pl0n3r/factory@v1',
        'idempotency_key' => str_repeat('a', 64),
    ];
    $blocked = [];
    foreach ([
        array_diff_key($base, ['governance_ref' => true]),
        [...$base, 'unexpected' => 'value'],
    ] as $inputs) {
        try {
            $adapter->dispatch($inputs);
            $blocked[] = false;
        } catch (InvalidArgumentException) {
            $blocked[] = true;
        }
    }
    $out = ['blocked' => $blocked, 'calls' => $calls];
} elseif ($name === 'provision-unapproved') {
    try {
        $adapter = new FakeProjectProvisioningAdapter();
        ProjectProvisioner::request(project(['phase' => 'planned']), 'pl0n3r/NewProduct', false, $adapter, 2000);
        $out = ['blocked' => false];
    } catch (InvalidArgumentException) {
        $out = ['blocked' => true];
    }
} elseif ($name === 'backfill') {
    $definitions = existingProjectDefinitions();
    $first = ProjectBackfill::apply([], $definitions, 2000);
    $second = ProjectBackfill::apply($first, $definitions, 3000);
    $out = ['first' => $first, 'second' => $second];
} elseif ($name === 'backfill-conflict') {
    try {
        $existing = [project(['repositories' => [repo('repo-condor', 'pl0n3r/Condor')]])];
        ProjectBackfill::apply($existing, existingProjectDefinitions(), 2000);
        $out = ['blocked' => false];
    } catch (InvalidArgumentException) {
        $out = ['blocked' => true];
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
