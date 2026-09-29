<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$name = $argv[1] ?? '';

function profile(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'project_ref' => 'controlbot:project/project-controlbot',
        'manifest_ref' => 'controlbot:recovery-manifest/project-controlbot-v1',
        'targets' => [
            'rpo_minutes' => 15,
            'rto_minutes' => 60,
        ],
        'retention' => [
            'hourly' => 24,
            'daily' => 7,
            'weekly' => 8,
            'monthly' => 12,
        ],
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

if ($name === 'valid') {
    $normalized = RecoveryProfile::normalize(profile());
    $out = [
        'profile' => $normalized,
        'effective_status' => RecoveryProfile::effectiveStatus($normalized),
    ];
} elseif ($name === 'explicit-not-applicable') {
    $out = RecoveryProfile::normalize(profile([
        'sources' => [
            'database' => 'not_applicable',
            'media' => 'required',
            'repository' => 'required',
            'configuration' => 'not_applicable',
        ],
    ]));
} elseif ($name === 'unknown-source-state') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'sources' => ['database' => 'optional'],
    ])));
} elseif ($name === 'all-not-applicable') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'sources' => [
            'database' => 'not_applicable',
            'media' => 'not_applicable',
            'repository' => 'not_applicable',
            'configuration' => 'not_applicable',
        ],
    ])));
} elseif ($name === 'bad-strategy') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'strategy' => ['copies_required' => 2],
    ])));
} elseif ($name === 'bad-rpo') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'targets' => ['rpo_minutes' => 0],
    ])));
} elseif ($name === 'empty-retention') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'retention' => ['hourly' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0],
    ])));
} elseif ($name === 'unknown-field') {
    $out = rejected(static fn() => RecoveryProfile::normalize([
        ...profile(),
        'provider' => 'vendor-one',
    ]));
} elseif ($name === 'sensitive-manifest-ref') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'manifest_ref' => 'controlbot:recovery-manifest/token-secret-value',
    ])));
} elseif ($name === 'encryption-disabled') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'encryption_required' => false,
    ])));
} elseif ($name === 'stale') {
    $candidate = profile(['freshness' => 'stale']);
    $out = ['effective_status' => RecoveryProfile::effectiveStatus($candidate)];
} elseif ($name === 'unknown') {
    $candidate = profile([
        'freshness' => 'unknown',
        'source_ref' => null,
        'observed_at' => null,
    ]);
    $out = ['effective_status' => RecoveryProfile::effectiveStatus($candidate)];
} elseif ($name === 'unknown-with-provenance') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'freshness' => 'unknown',
    ])));
} elseif ($name === 'stale-without-provenance') {
    $out = rejected(static fn() => RecoveryProfile::normalize(profile([
        'freshness' => 'stale',
        'source_ref' => null,
        'observed_at' => null,
    ])));
} elseif ($name === 'missing-profile') {
    $out = ['effective_status' => RecoveryProfile::effectiveStatus(null)];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
