<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$encoded = file_get_contents(__DIR__ . '/../config/recovery/factory.json');
if ($encoded === false) {
    throw new RuntimeException('Factory recovery profile unavailable.');
}
$rawProfile = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rawProfile) || array_is_list($rawProfile)) {
    throw new InvalidArgumentException('Factory recovery profile invalid.');
}

$profile = RecoveryProfile::normalize($rawProfile);
$legacy = [
    'evidence_ref' => 'https://github.com/pl0n3r/Factory/issues/36#issuecomment-5813381932',
    'source_sha' => '19580a32fe504e74f4941bd64cefcc5b5a77d07a',
    'restored_from_external_copy' => true,
    'manifest' => ['verified' => 155, 'total' => 155],
    'canonical_contracts' => [
        'backup_receipt' => false,
        'recovery_evidence' => false,
        'restore_drill' => false,
    ],
    'unproven' => ['encryption', 'immutability', 'demonstrated_rto'],
];

echo json_encode([
    'profile' => $profile,
    'profile_status' => RecoveryProfile::effectiveStatus($rawProfile),
    'legacy_restore' => $legacy,
    'recovery' => [
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
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
