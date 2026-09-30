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
    return jsonFile(__DIR__ . '/../config/recovery/controlbot.json');
}

function projectData(): array
{
    return jsonFile(__DIR__ . '/../datos.yml');
}

function assertConstructionAttestation(array $data): array
{
    $attestation = $data['d063_attestation'] ?? null;
    if (($data['phase'] ?? null) !== 'construccion'
        || !is_array($attestation)
        || ($attestation['nothing_live'] ?? null) !== true
        || ($attestation['no_real_customer_data'] ?? null) !== true) {
        throw new InvalidArgumentException('Operational Recovery evidence required before leaving construction.');
    }
    return [
        'phase' => 'construccion',
        'nothing_live' => true,
        'no_real_customer_data' => true,
    ];
}

function operationalStatus(): array
{
    return [
        'profile_status' => RecoveryProfile::effectiveStatus(rolloutProfile()),
        'backup_evidence' => 'unknown',
        'offsite_copy' => 'unknown',
        'immutable_copy' => 'unknown',
        'restore_drill' => 'unknown',
        'dr_status' => 'UNKNOWN',
        'restorable' => false,
        'execution' => false,
    ];
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
    'profile' => [
        'profile' => RecoveryProfile::normalize(rolloutProfile()),
        'effective_status' => RecoveryProfile::effectiveStatus(rolloutProfile()),
    ],
    'attestation' => assertConstructionAttestation(projectData()),
    'attestation-live' => rejected(function (): void {
        $data = projectData();
        $data['phase'] = 'live';
        $data['d063_attestation']['nothing_live'] = false;
        assertConstructionAttestation($data);
    }),
    'attestation-real-data' => rejected(function (): void {
        $data = projectData();
        $data['d063_attestation']['no_real_customer_data'] = false;
        assertConstructionAttestation($data);
    }),
    'operational-status' => operationalStatus(),
    default => throw new InvalidArgumentException('scenario invalid.'),
};

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
