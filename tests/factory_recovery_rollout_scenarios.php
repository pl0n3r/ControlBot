<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$loadProfile = static function (): array {
    $raw = file_get_contents(__DIR__ . '/../config/recovery/factory.json');
    if ($raw === false) {
        throw new InvalidArgumentException('Factory RecoveryProfile fixture unavailable.');
    }
    $profile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($profile) || array_is_list($profile)) {
        throw new InvalidArgumentException('Factory RecoveryProfile fixture invalid.');
    }
    return $profile;
};

$legacy = [
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

$case = $argv[1] ?? '';
$out = match ($case) {
    'profile' => (static function () use ($loadProfile): array {
        $profile = $loadProfile();
        return [
            'profile' => RecoveryProfile::normalize($profile),
            'effective_status' => RecoveryProfile::effectiveStatus($profile),
        ];
    })(),
    'legacy' => $legacy,
    'operational-status' => [
        'profile_status' => RecoveryProfile::effectiveStatus($loadProfile()),
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
    ],
    default => throw new InvalidArgumentException('Factory recovery rollout scenario invalid.'),
};

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
