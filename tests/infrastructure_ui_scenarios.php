<?php
declare(strict_types=1);

foreach (['Provider', 'Resource', 'Observation', 'Impact', 'CenterUi'] as $name) {
    require __DIR__ . '/../src/Infrastructure' . $name . '.php';
}
use ControlBot\Infrastructure\InfrastructureCenterUi;

function resource(string $id, string $kind, array $replace = []): array
{
    return array_replace([
        'version' => 1,
        'resource_id' => $id,
        'kind' => $kind,
        'provider_id' => 'provider-primary',
        'account_id' => 'account-primary',
        'project_ref' => 'controlbot:project/project-controlbot',
        'venture_ref' => 'controlbot:venture/venture-platform',
        'environment_ref' => 'controlbot:environment/controlbot-prod',
        'service_ref' => null,
        'parent_ref' => null,
        'release_evidence' => null,
        'cost_ref' => 'controlbot:cost/project-controlbot',
        'backup_refs' => [],
        'source_ref' => 'controlbot:resource/' . $id,
        'observed_at' => 2000,
    ], $replace);
}

function observation(string $id, array $replace = []): array
{
    $unknown = ['state' => 'unknown', 'source_ref' => null, 'observed_at' => null];
    return array_replace([
        'version' => 1,
        'resource_id' => $id,
        'state' => 'online',
        'source_ref' => 'controlbot:observation/' . $id,
        'observed_at' => 2000,
        'freshness' => 'fresh',
        'backup_freshness' => $unknown,
        'restore_verification' => $unknown,
        'cost_attribution' => $unknown + ['cost_ref' => null],
        'release_drift' => $unknown + ['expected_sha' => null, 'observed_sha' => null],
        'incident_refs' => [],
    ], $replace);
}

function rejected(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

$resources = [
    resource('env-prod', 'environment', [
        'environment_ref' => null,
        'source_ref' => 'controlbot:environment/controlbot-prod',
    ]),
    resource('service-web', 'service', ['parent_ref' => 'controlbot:resource/env-prod']),
    resource('database-primary', 'database', [
        'service_ref' => 'controlbot:resource/service-web',
        'parent_ref' => 'controlbot:resource/env-prod',
    ]),
];

$database = observation('database-primary', [
    'state' => 'degraded',
    'freshness' => 'stale',
    'backup_freshness' => [
        'state' => 'stale',
        'source_ref' => 'controlbot:backup/database',
        'observed_at' => 1900,
    ],
    'restore_verification' => [
        'state' => 'verified',
        'source_ref' => 'controlbot:restore/database',
        'observed_at' => 1950,
    ],
    'cost_attribution' => [
        'state' => 'attributed',
        'cost_ref' => 'controlbot:cost/project-controlbot',
        'source_ref' => 'controlbot:cost-evidence/project',
        'observed_at' => 2000,
    ],
    'release_drift' => [
        'state' => 'drift',
        'expected_sha' => str_repeat('a', 40),
        'observed_sha' => str_repeat('b', 40),
        'source_ref' => 'https://github.com/pl0n3r/ControlBot',
        'observed_at' => 2000,
    ],
    'incident_refs' => ['https://github.com/pl0n3r/ControlBot/issues/190'],
]);

$binding = [[
    'capability_ref' => 'controlbot:capability/commerce',
    'project_ref' => 'controlbot:project/project-controlbot',
    'venture_ref' => 'controlbot:venture/venture-platform',
    'source_ref' => 'controlbot:binding/commerce',
    'observed_at' => 2000,
]];

$name = $argv[1] ?? '';
if ($name === 'cockpit') {
    $out = InfrastructureCenterUi::project(
        $resources,
        [observation('service-web'), $database],
        $binding,
    );
} elseif ($name === 'invalid') {
    $crossScope = $resources;
    $crossScope[2]['project_ref'] = 'controlbot:project/other';
    $out = [
        'duplicate_observation' => rejected(fn() => InfrastructureCenterUi::project(
            $resources,
            [observation('service-web'), observation('service-web')],
            $binding,
        )),
        'orphan_observation' => rejected(fn() => InfrastructureCenterUi::project(
            $resources,
            [observation('missing')],
            $binding,
        )),
        'scalar_observation' => rejected(fn() => InfrastructureCenterUi::project(
            $resources,
            ['invalid'],
            $binding,
        )),
        'cross_scope' => rejected(fn() => InfrastructureCenterUi::project(
            $crossScope,
            [],
            $binding,
        )),
        'caller_action' => rejected(fn() => InfrastructureCenterUi::project(
            $resources,
            [],
            $binding,
            [['status' => 'planned', 'authority_decision' => 'allow']],
        )),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
