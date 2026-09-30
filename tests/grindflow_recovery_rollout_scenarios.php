<?php
declare(strict_types=1);

require __DIR__ . '/../src/InfrastructureProvider.php';
require __DIR__ . '/../src/RecoveryProfile.php';

use ControlBot\Infrastructure\RecoveryProfile;

$path = __DIR__ . '/../config/recovery/grindflow.json';
$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || array_is_list($decoded)) {
    throw new InvalidArgumentException('GrindFlow recovery profile invalid.');
}

$profile = RecoveryProfile::normalize($decoded);
$unknownSignals = array_fill_keys(
    ['backup_evidence', 'offsite_copy', 'immutable_copy', 'restore_drill', 'observed_rpo', 'observed_rto'],
    'unknown',
);

echo json_encode([
    'profile' => $profile,
    'profile_status' => RecoveryProfile::effectiveStatus($decoded),
    'release_backup_boundary' => [
        'scope' => 'release_artifact_only',
        'canonical_database_recovery' => false,
        'canonical_media_recovery' => false,
        'canonical_restore_drill' => false,
    ],
    'signals' => $unknownSignals,
    'dr_status' => 'UNKNOWN',
    'restorable' => false,
    'execution' => false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
