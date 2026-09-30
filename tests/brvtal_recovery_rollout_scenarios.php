<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$encoded = file_get_contents(__DIR__ . '/../config/recovery/brvtal.json');
if ($encoded === false) {
    throw new RuntimeException('BRVTAL recovery profile unavailable.');
}
$rawProfile = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rawProfile) || array_is_list($rawProfile)) {
    throw new InvalidArgumentException('BRVTAL recovery profile invalid.');
}

$profile = RecoveryProfile::normalize($rawProfile);

$legacyCapability = [
    'source_issue' => 389,
    'scheduled_backup_capability' => true,
    'google_drive_optional' => true,
    'primary_immutable_verified' => false,
    'canonical_contracts' => [
        'backup_receipt' => false,
        'recovery_evidence' => false,
        'restore_drill' => false,
    ],
];

echo json_encode([
    'profile' => $profile,
    'profile_status' => RecoveryProfile::effectiveStatus($rawProfile),
    'legacy_capability' => $legacyCapability,
    'provider_decision' => [
        'issue' => 470,
        'state' => 'pending',
        'primary_offsite_immutable' => 'unknown',
    ],
    'recovery' => [
        'canonical_backup_receipt' => 'unknown',
        'offsite_copy' => 'unknown',
        'immutable_copy' => 'unknown',
        'canonical_restore_drill' => 'unknown',
        'observed_rpo_minutes' => null,
        'demonstrated_rto_minutes' => null,
        'dr_status' => 'UNKNOWN',
        'restorable' => false,
        'execution' => false,
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
