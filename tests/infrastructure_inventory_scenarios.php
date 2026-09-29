<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/InfrastructureResource.php';

use ControlBot\Infrastructure\InfrastructureProvider;
use ControlBot\Infrastructure\InfrastructureResource;

$name = $argv[1] ?? '';

function provider(string $id, string $kind, string $vendor, array $capabilities): array
{
    return [
        'version' => 1,
        'provider_id' => $id,
        'kind' => $kind,
        'vendor' => $vendor,
        'adapter_ref' => 'controlbot:adapter/' . $id,
        'capabilities' => $capabilities,
        'source_ref' => 'controlbot:provider/' . $id,
        'observed_at' => 2000,
    ];
}

function capability(string $name, array $scopes): array
{
    return ['capability' => $name, 'scopes' => $scopes];
}

function account(string $id, string $providerId): array
{
    return [
        'version' => 1,
        'account_id' => $id,
        'provider_id' => $providerId,
        'alias' => $id,
        'source_ref' => 'controlbot:account/' . $id,
        'observed_at' => 2000,
    ];
}

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

function blocked(callable $fn): array
{
    try {
        $fn();
        return ['blocked' => false];
    } catch (InvalidArgumentException) {
        return ['blocked' => true];
    }
}

if ($name === 'multi-provider') {
    $out = InfrastructureProvider::normalizeInventory(
        [
            provider('provider-primary', 'hosting', 'vendor-one', [
                capability('inventory.read', ['provider', 'project']),
                capability('health.read', ['environment', 'resource']),
            ]),
            provider('provider-backup', 'storage', 'vendor-two', [
                capability('backup.read', ['project', 'resource']),
                capability('backup.write', ['resource']),
            ]),
        ],
        [
            account('account-primary', 'provider-primary'),
            account('account-backup', 'provider-backup'),
        ],
    );
} elseif ($name === 'typed-resources') {
    $out = InfrastructureResource::normalizeInventory([
        resource('env-prod', 'environment', [
            'environment_ref' => null,
            'source_ref' => 'controlbot:environment/controlbot-prod',
        ]),
        resource('service-web', 'service', [
            'service_ref' => null,
            'parent_ref' => 'controlbot:resource/env-prod',
            'release_evidence' => [
                'version' => 1,
                'sha' => str_repeat('a', 40),
                'source_ref' => 'https://github.com/pl0n3r/ControlBot',
                'observed_at' => 1990,
            ],
        ]),
        resource('database-primary', 'database', [
            'service_ref' => 'controlbot:resource/service-web',
            'parent_ref' => 'controlbot:resource/env-prod',
            'backup_refs' => ['controlbot:backup/db-primary-2000'],
        ]),
        resource('storage-media', 'storage'),
        resource('dns-primary', 'dns'),
        resource('certificate-primary', 'certificate'),
        resource('backup-primary', 'backup'),
        resource('network-primary', 'network'),
    ]);
} elseif ($name === 'unknown-provider-kind') {
    $out = blocked(static fn() => InfrastructureProvider::normalizeProvider(
        provider('provider-primary', 'hostinger', 'hostinger', [])
    ));
} elseif ($name === 'unknown-capability') {
    $out = blocked(static fn() => InfrastructureProvider::normalizeProvider(
        provider('provider-primary', 'hosting', 'vendor-one', [
            capability('shell.execute', ['resource']),
        ])
    ));
} elseif ($name === 'unknown-scope') {
    $out = blocked(static fn() => InfrastructureProvider::normalizeProvider(
        provider('provider-primary', 'hosting', 'vendor-one', [
            capability('inventory.read', ['global']),
        ])
    ));
} elseif ($name === 'unknown-resource-kind') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        resource('queue-primary', 'queue')
    ));
} elseif ($name === 'deterministic') {
    $rows = [
        resource('storage-media', 'storage'),
        resource('service-web', 'service', [
            'release_evidence' => [
                'version' => 1,
                'sha' => str_repeat('b', 40),
                'source_ref' => 'https://github.com/pl0n3r/ControlBot',
                'observed_at' => 1900,
            ],
        ]),
    ];
    $first = InfrastructureResource::normalizeInventory($rows);
    $second = InfrastructureResource::normalizeInventory(array_reverse($rows));
    $out = ['first' => $first, 'second' => $second];
} elseif ($name === 'duplicate-resource') {
    $out = blocked(static fn() => InfrastructureResource::normalizeInventory([
        resource('storage-media', 'storage'),
        resource('storage-media', 'storage'),
    ]));
} elseif ($name === 'duplicate-provider') {
    $p = provider('provider-primary', 'hosting', 'vendor-one', []);
    $out = blocked(static fn() => InfrastructureProvider::normalizeInventory([$p, $p], []));
} elseif ($name === 'unknown-field') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        [...resource('storage-media', 'storage'), 'region' => 'us-east-1']
    ));
} elseif ($name === 'invalid-ref') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        resource('storage-media', 'storage', ['project_ref' => 'project-controlbot'])
    ));
} elseif ($name === 'provider-secret-ref') {
    $out = blocked(static fn() => InfrastructureProvider::normalizeProvider(
        array_replace(provider('provider-primary', 'hosting', 'vendor-one', []), [
            'adapter_ref' => 'controlbot:secret-token',
        ])
    ));
} elseif ($name === 'secret-ref') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        resource('storage-media', 'storage', ['source_ref' => 'controlbot:token=supersecretvalue'])
    ));
} elseif ($name === 'secret-id') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        resource('token-secret', 'storage')
    ));
} elseif ($name === 'bad-release-sha') {
    $out = blocked(static fn() => InfrastructureResource::normalize(
        resource('service-web', 'service', [
            'release_evidence' => [
                'version' => 1,
                'sha' => 'main',
                'source_ref' => 'https://github.com/pl0n3r/ControlBot',
                'observed_at' => 1900,
            ],
        ])
    ));
} elseif ($name === 'account-provider-missing') {
    $out = blocked(static fn() => InfrastructureProvider::normalizeInventory(
        [provider('provider-primary', 'hosting', 'vendor-one', [])],
        [account('account-orphan', 'provider-missing')],
    ));
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
