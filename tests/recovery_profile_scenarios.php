<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

function profile(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'project_ref' => 'controlbot:project/project-controlbot',
        'manifest_ref' => 'controlbot:recovery-manifest/project-controlbot-v1',
        'targets' => ['rpo_minutes' => 15, 'rto_minutes' => 60],
        'retention' => ['hourly' => 24, 'daily' => 7, 'weekly' => 8, 'monthly' => 12],
        'sources' => [
            'database' => 'required',
            'media' => 'not_applicable',
            'repository' => 'required',
            'configuration' => 'required',
        ],
        'strategy' => [
            'copies_required' => 3,
            'media_types_required' => 2,
            'offsite_required' => true,
            'immutable_required' => true,
            'undetected_restore_failures_target' => 0,
        ],
        'encryption_required' => true,
        'restore_drill_cadence_hours' => 168,
        'source_ref' => 'https://github.com/pl0n3r/factory/issues/305',
        'observed_at' => 2000,
        'freshness' => 'fresh',
    ], $overrides);
}

function rejected(callable $fn): array
{
    try {
        $fn();
        return ['blocked' => false];
    } catch (InvalidArgumentException) {
        return ['blocked' => true];
    }
}

$case = $argv[1] ?? '';
$out = match ($case) {
    'valid' => [
        'profile' => RecoveryProfile::normalize(profile()),
        'effective_status' => RecoveryProfile::effectiveStatus(profile()),
    ],
    'explicit-not-applicable' => RecoveryProfile::normalize(profile(['sources' => [
        'database' => 'not_applicable', 'media' => 'required',
        'repository' => 'required', 'configuration' => 'not_applicable',
    ]])),
    'unknown-source-state' => rejected(fn() => RecoveryProfile::normalize(
        profile(['sources' => ['database' => 'optional']])
    )),
    'all-not-applicable' => rejected(fn() => RecoveryProfile::normalize(profile(['sources' => [
        'database' => 'not_applicable', 'media' => 'not_applicable',
        'repository' => 'not_applicable', 'configuration' => 'not_applicable',
    ]]))),
    'bad-strategy' => rejected(fn() => RecoveryProfile::normalize(
        profile(['strategy' => ['copies_required' => 2]])
    )),
    'bad-rpo' => rejected(fn() => RecoveryProfile::normalize(
        profile(['targets' => ['rpo_minutes' => 0]])
    )),
    'empty-retention' => rejected(fn() => RecoveryProfile::normalize(profile(['retention' => [
        'hourly' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0,
    ]]))),
    'unknown-field' => rejected(fn() => RecoveryProfile::normalize([
        ...profile(), 'provider' => 'vendor-one',
    ])),
    'sensitive-manifest-ref' => rejected(fn() => RecoveryProfile::normalize(
        profile(['manifest_ref' => 'controlbot:recovery-manifest/token-secret-value'])
    )),
    'encryption-disabled' => rejected(fn() => RecoveryProfile::normalize(
        profile(['encryption_required' => false])
    )),
    'stale' => ['effective_status' => RecoveryProfile::effectiveStatus(
        profile(['freshness' => 'stale'])
    )],
    'unknown' => ['effective_status' => RecoveryProfile::effectiveStatus(profile([
        'freshness' => 'unknown', 'source_ref' => null, 'observed_at' => null,
    ]))],
    'unknown-with-provenance' => rejected(fn() => RecoveryProfile::normalize(
        profile(['freshness' => 'unknown'])
    )),
    'stale-without-provenance' => rejected(fn() => RecoveryProfile::normalize(profile([
        'freshness' => 'stale', 'source_ref' => null, 'observed_at' => null,
    ]))),
    'missing-profile' => ['effective_status' => RecoveryProfile::effectiveStatus(null)],
    default => throw new InvalidArgumentException('scenario invalid'),
};

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
