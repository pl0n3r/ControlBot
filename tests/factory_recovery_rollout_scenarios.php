<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

function jsonFile(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new InvalidArgumentException('fixture unavailable.');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('fixture invalid.');
    }
    return $decoded;
}

function rolloutProfile(): array
{
    return jsonFile(__DIR__ . '/../config/recovery/factory.json');
}

function legacyRestoreProvenance(): array
{
    return [
        'evidence_ref' => 'https://github.com/pl0n3r/Factory/issues/36#issuecomment-5813381932',
        'source_sha' => '19580a32fe504e74f4941bd64cefcc5b5a77d07a',
        'restored_from_external_copy' => true,
        'manifest_files_verified' => 155,
        'manifest_files_total' => 155,
        'canonical_backup_receipt' => false,
        'canonical_recovery_evidence' => false,
        'canonical_restore_drill' => false,
        'encryption' => 'unknown',
        'immutability' => 'unknown',
        'demonstrated_rto_minutes' => null,
    ];
}

function operationalStatus(): array
{
    return [
        'profile_status' => RecoveryProfile::effectiveStatus(rolloutProfile()),
        'legacy_external_restore' => 'verified',
        'canonical_backup_receipt' => 'unknown',
        'canonical_recovery_evidence' => 'unknown',
        'canonical_restore_drill' => 'unknown',
        'encryption' => 'unknown',
        'immutability' => 'unknown',
        'demonstrated_rto_minutes' => null,
        'dr_status' => 'UNKNOWN',
        'restorable' => false,
        'execution' => false,
    ];
}

$case = $argv[1] ?? '';
$out = match ($case) {
    'profile' => [
        'profile' => RecoveryProfile::normalize(rolloutProfile()),
        'effective_status' => RecoveryProfile::effectiveStatus(rolloutProfile()),
    ],
    'legacy' => legacyRestoreProvenance(),
    'operational-status' => operationalStatus(),
    default => throw new InvalidArgumentException('scenario invalid.'),
};

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
