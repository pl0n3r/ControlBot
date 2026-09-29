<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/InfrastructureResource.php';
require __DIR__ . '/../src/InfrastructureObservation.php';
require __DIR__ . '/../src/InfrastructureImpact.php';

use ControlBot\Infrastructure\InfrastructureImpact;
use ControlBot\Infrastructure\InfrastructureObservation;

$name = $argv[1] ?? '';

function resource(string $id, string $kind, array $overrides = []): array
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
    ], $overrides);
}

function observation(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'resource_id' => 'service-web',
        'state' => 'online',
        'source_ref' => 'controlbot:observation/service-web-2000',
        'observed_at' => 2000,
        'freshness' => 'fresh',
        'backup_freshness' => [
            'state' => 'unknown',
            'source_ref' => null,
            'observed_at' => null,
        ],
        'restore_verification' => [
            'state' => 'unknown',
            'source_ref' => null,
            'observed_at' => null,
        ],
        'cost_attribution' => [
            'state' => 'unknown',
            'cost_ref' => null,
            'source_ref' => null,
            'observed_at' => null,
        ],
        'release_drift' => [
            'state' => 'unknown',
            'expected_sha' => null,
            'observed_sha' => null,
            'source_ref' => null,
            'observed_at' => null,
        ],
        'incident_refs' => [],
    ], $overrides);
}

function binding(
    string $capability,
    string $venture = 'controlbot:venture/venture-platform',
    string $project = 'controlbot:project/project-controlbot',
): array {
    return [
        'capability_ref' => 'controlbot:capability/' . $capability,
        'project_ref' => $project,
        'venture_ref' => $venture,
        'source_ref' => 'controlbot:binding/' . $capability,
        'observed_at' => 2000,
    ];
}

function blocked(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

function scopedRelationBlocked(string $relation, string $field, string $value): bool
{
    $environment = resource('env-prod', 'environment', [
        'environment_ref' => null,
        'source_ref' => 'controlbot:environment/controlbot-prod',
    ]);
    if ($relation === 'service') {
        $resources = [
            $environment,
            resource('service-web', 'service', ['parent_ref' => null]),
            resource('database-primary', 'database', [
                $field => $value,
                'service_ref' => 'controlbot:resource/service-web',
                'parent_ref' => null,
            ]),
        ];
    } elseif ($relation === 'environment') {
        $resources = [
            $environment,
            resource('service-web', 'service', [
                $field => $value,
                'parent_ref' => null,
            ]),
        ];
    } elseif ($relation === 'parent') {
        $resources = [
            resource('parent-storage', 'storage', ['environment_ref' => null]),
            resource('child-storage', 'storage', [
                $field => $value,
                'environment_ref' => null,
                'parent_ref' => 'controlbot:resource/parent-storage',
            ]),
        ];
    } else {
        throw new InvalidArgumentException('scope relation invalid.');
    }

    return blocked(static fn() => InfrastructureImpact::build($resources, []));
}

function ambiguousEnvironmentSourceBlocked(): bool
{
    return blocked(static fn() => InfrastructureImpact::build([
        resource('env-prod-a', 'environment', [
            'environment_ref' => null,
            'source_ref' => 'controlbot:environment/controlbot-prod',
        ]),
        resource('env-prod-b', 'environment', [
            'environment_ref' => null,
            'source_ref' => 'controlbot:environment/controlbot-prod',
        ]),
    ], []));
}

$resources = [
    resource('env-prod', 'environment', [
        'environment_ref' => null,
        'source_ref' => 'controlbot:environment/controlbot-prod',
    ]),
    resource('service-web', 'service', [
        'parent_ref' => 'controlbot:resource/env-prod',
    ]),
    resource('database-primary', 'database', [
        'service_ref' => 'controlbot:resource/service-web',
        'parent_ref' => 'controlbot:resource/env-prod',
        'backup_refs' => ['controlbot:backup/database-primary-2000'],
    ]),
];

$bindings = [
    binding('commerce'),
    binding(
        'other-venture',
        'controlbot:venture/venture-other',
        'controlbot:project/project-other',
    ),
];

if ($name === 'freshness') {
    $fresh = observation();
    $stale = observation(['freshness' => 'stale']);
    $unknown = observation(['freshness' => 'unknown']);
    $out = [
        'fresh' => InfrastructureObservation::normalize($fresh),
        'fresh_effective' => InfrastructureObservation::effectiveState($fresh),
        'stale_effective' => InfrastructureObservation::effectiveState($stale),
        'unknown_effective' => InfrastructureObservation::effectiveState($unknown),
        'absent_effective' => InfrastructureObservation::effectiveState(null),
    ];
} elseif ($name === 'impact') {
    $out = [
        'graph' => InfrastructureImpact::build($resources, $bindings),
        'database' => InfrastructureImpact::impactForResource(
            $resources,
            $bindings,
            'database-primary',
        ),
        'platform_resources' => InfrastructureImpact::resourcesForVenture(
            $resources,
            $bindings,
            'controlbot:venture/venture-platform',
        ),
        'other_resources' => InfrastructureImpact::resourcesForVenture(
            $resources,
            $bindings,
            'controlbot:venture/venture-other',
        ),
    ];
} elseif ($name === 'recovery-signals') {
    $out = InfrastructureObservation::normalize(observation([
        'backup_freshness' => [
            'state' => 'stale',
            'source_ref' => 'controlbot:backup/database-primary-2000',
            'observed_at' => 1800,
        ],
        'restore_verification' => [
            'state' => 'verified',
            'source_ref' => 'controlbot:restore/database-primary-1990',
            'observed_at' => 1990,
        ],
    ]));
} elseif ($name === 'evidence-signals') {
    $out = [
        'evidenced' => InfrastructureObservation::normalize(observation([
            'cost_attribution' => [
                'state' => 'attributed',
                'cost_ref' => 'controlbot:cost/project-controlbot',
                'source_ref' => 'controlbot:cost-evidence/project-controlbot-2000',
                'observed_at' => 2000,
            ],
            'release_drift' => [
                'state' => 'drift',
                'expected_sha' => str_repeat('a', 40),
                'observed_sha' => str_repeat('b', 40),
                'source_ref' => 'https://github.com/pl0n3r/ControlBot',
                'observed_at' => 2000,
            ],
        ])),
        'unknown' => InfrastructureObservation::normalize(observation()),
        'cost_missing_evidence_blocked' => blocked(static fn() =>
            InfrastructureObservation::normalize(observation([
                'cost_attribution' => [
                    'state' => 'attributed',
                    'cost_ref' => null,
                    'source_ref' => null,
                    'observed_at' => null,
                ],
            ]))
        ),
        'release_state_mismatch_blocked' => blocked(static fn() =>
            InfrastructureObservation::normalize(observation([
                'release_drift' => [
                    'state' => 'current',
                    'expected_sha' => str_repeat('a', 40),
                    'observed_sha' => str_repeat('b', 40),
                    'source_ref' => 'https://github.com/pl0n3r/ControlBot',
                    'observed_at' => 2000,
                ],
            ]))
        ),
    ];
} elseif ($name === 'invalid') {
    $out = [
        'sensitive_source' => blocked(static fn() =>
            InfrastructureObservation::normalize(observation([
                'source_ref' => 'controlbot:secret-token',
            ]))
        ),
        'duplicate_incident' => blocked(static fn() =>
            InfrastructureObservation::normalize(observation([
                'incident_refs' => [
                    'https://github.com/pl0n3r/ControlBot/issues/1',
                    'https://github.com/pl0n3r/ControlBot/issues/1',
                ],
            ]))
        ),
        'duplicate_binding' => blocked(static fn() =>
            InfrastructureImpact::build($resources, [binding('commerce'), binding('commerce')])
        ),
        'dangling_service' => blocked(static fn() =>
            InfrastructureImpact::build([
                resource('database-primary', 'database', [
                    'service_ref' => 'controlbot:resource/service-missing',
                ]),
            ], [binding('commerce')])
        ),
        'dangling_environment' => blocked(static fn() =>
            InfrastructureImpact::build([
                resource('service-web', 'service', [
                    'environment_ref' => 'controlbot:environment/missing-prod',
                ]),
            ], [binding('commerce')])
        ),
        'cross_scope_service_project' => scopedRelationBlocked(
            'service', 'project_ref', 'controlbot:project/project-other'
        ),
        'cross_scope_service_venture' => scopedRelationBlocked(
            'service', 'venture_ref', 'controlbot:venture/venture-other'
        ),
        'cross_scope_service_environment' => scopedRelationBlocked(
            'service', 'environment_ref', 'controlbot:environment/other-prod'
        ),
        'cross_scope_environment_project' => scopedRelationBlocked(
            'environment', 'project_ref', 'controlbot:project/project-other'
        ),
        'cross_scope_environment_venture' => scopedRelationBlocked(
            'environment', 'venture_ref', 'controlbot:venture/venture-other'
        ),
        'cross_scope_parent_project' => scopedRelationBlocked(
            'parent', 'project_ref', 'controlbot:project/project-other'
        ),
        'cross_scope_parent_venture' => scopedRelationBlocked(
            'parent', 'venture_ref', 'controlbot:venture/venture-other'
        ),
        'ambiguous_environment_source' => ambiguousEnvironmentSourceBlocked(),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
