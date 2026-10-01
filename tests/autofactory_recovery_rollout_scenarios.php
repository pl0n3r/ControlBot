<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$encoded = file_get_contents(__DIR__ . '/../config/recovery/autofactory.json');
if ($encoded === false) {
    throw new RuntimeException('AutoFactory recovery profile unavailable.');
}
$rawProfile = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rawProfile) || array_is_list($rawProfile)) {
    throw new InvalidArgumentException('AutoFactory recovery profile invalid.');
}

$profile = RecoveryProfile::normalize($rawProfile);

echo json_encode([
    'profile' => $profile,
    'profile_status' => RecoveryProfile::effectiveStatus($rawProfile),
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
    'boundaries' => [
        'runtime_bridge_live' => false,
        'provider_provisioned' => false,
        'd061_lifted' => false,
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
