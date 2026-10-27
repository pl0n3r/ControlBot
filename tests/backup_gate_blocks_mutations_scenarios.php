<?php
declare(strict_types=1);
/** ControlBot #979: only synthetic fixtures; never contacts real storage. */
require __DIR__.'/../src/CapabilityPolicy.php';
require __DIR__.'/../src/BackupReceipt.php';
require __DIR__.'/../src/BackupGate.php';
require __DIR__.'/../src/ProductionOperation.php';

use ControlBot\Production\BackupGate;
use ControlBot\Production\CapabilityPolicy;
use ControlBot\Production\ProductionOperation;

const WRITE_AT = 1799996520;

function catalog(string $type, string $name): array
{
    $constant = (new ReflectionClass($type))->getReflectionConstant($name);
    if (!$constant instanceof ReflectionClassConstant) {
        throw new RuntimeException('Missing policy catalog: '.$type);
    }
    $value = $constant->getValue();
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Empty policy catalog');
    }
    return $value;
}

function scope979(): array
{
    return [
        'project' => 'fixture-controlbot', 'environment' => 'production',
        'resource' => 'fixture:protected',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue' => 'pl0n3r/ControlBot#979',
    ];
}

function fixture979(string $type, array $changes = []): array
{
    return array_replace([
        'version' => 1,
        'receipt_id' => '11111111-2222-4333-8444-555555555555',
        ...scope979(),
        'backup_type' => $type,
        'created_at' => '2027-01-15T07:00:00Z',
        'verified_at' => '2027-01-15T07:01:00Z',
        'status' => 'ready',
        'artifact_ref' => 'artifact:synthetic:fixture-979',
        'integrity' => ['algorithm' => 'sha256', 'digest' => str_repeat('a', 64)],
        'restore_capability' => 'manual',
        'expires_at' => '2027-01-15T08:00:00Z',
    ], $changes);
}

function gate979(string $capability, ?array $receipt, ?int $at = null, array $restrictions = []): array
{
    return BackupGate::evaluate($capability, scope979(), $receipt, $at ?? WRITE_AT, $restrictions);
}

// Discovery is from code, not an independent hardcoded allowlist.
$policy = catalog(CapabilityPolicy::class, 'CATALOG');
$types = catalog(BackupGate::class, 'REQUIRED_TYPE');
$operations = catalog(ProductionOperation::class, 'CATALOG');
$protected = array_keys(array_filter($policy, static fn (string $v): bool => $v === 'backup_required'));
sort($protected);
$gateTypes = array_keys($types);
sort($gateTypes);
$writes = [];
foreach ($operations as $id => $definition) {
    if (!is_array($definition) || count($definition) < 3) {
        throw new RuntimeException('Malformed operation catalog');
    }
    $operation = ProductionOperation::fromId($id);
    if ($operation->effect() === 'write') {
        $writes[$id] = $operation->capability();
    }
}
ksort($writes);
$unprotected = [];
foreach ($writes as $id => $capability) {
    // The act of CREATING a backup cannot itself need a previous backup.
    // This exception never implies a real backup has been created.
    if ($id !== 'database.backup' && ($policy[$capability] ?? null) !== 'backup_required') {
        $unprotected[] = $id;
    }
}
$exceptions = (($writes['database.backup'] ?? null) === 'database.backup'
    && ($policy['database.backup'] ?? null) === 'automatic') ? [] : ['database.backup'];
$out = ['mode' => 'SYNTHETIC_ONLY', 'production_executed' => false,
        'backup_verified' => false, 'readiness' => 'UNKNOWN'];
$case = $argv[1] ?? '';
if ($case === 'principal') {
    $checks = [];
    foreach ($protected as $capability) {
        $checks[$capability] = [
            'missing' => gate979($capability, null),
            'fixture' => gate979($capability, fixture979($types[$capability] ?? 'database_dump')),
        ];
    }
    $out['protected'] = $protected;
    $out['gate_types'] = $gateTypes;
    $out['production_writes'] = $writes;
    $out['unprotected'] = $unprotected;
    $out['exception_violations'] = $exceptions;
    $out['checks'] = $checks;
} elseif ($case === 'negatives') {
    $checks = [];
    foreach ($protected as $capability) {
        $type = $types[$capability] ?? 'database_dump';
        $other = $type === 'database_dump' ? 'cron_snapshot' : 'database_dump';
        $extra = fixture979($type);
        $extra['untrusted'] = true;
        $missingIntegrity = fixture979($type);
        unset($missingIntegrity['integrity']);
        $checks[$capability] = [
            'missing' => gate979($capability, null),
            'not_ready' => gate979($capability, fixture979($type, ['status' => 'running'])),
            'extra' => gate979($capability, $extra),
            'missing_integrity' => gate979($capability, $missingIntegrity),
            'wrong_type' => gate979($capability, fixture979($other)),
            'expired' => gate979($capability, fixture979($type, ['expires_at' => '2027-01-15T07:02:00Z'])),
            'unbounded' => gate979($capability, fixture979($type, ['expires_at' => null])),
            'stale' => gate979($capability, fixture979($type, ['expires_at' => '2027-01-17T08:00:00Z']), strtotime('2027-01-16T07:01:01Z')),
            'future' => gate979($capability, fixture979($type, ['verified_at' => '2027-01-15T07:05:00Z'])),
            'project' => gate979($capability, fixture979($type, ['project' => 'fixture-foreign'])),
            'run' => gate979($capability, fixture979($type, ['run_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff'])),
            'issue' => gate979($capability, fixture979($type, ['issue' => 'pl0n3r/ControlBot#999'])),
            'owner' => gate979($capability, fixture979($type), null, ['owner_required']),
            'forbidden' => gate979($capability, fixture979($type), null, ['forbidden']),
        ];
    }
    $out['checks'] = $checks;
} elseif ($case === 'privacy') {
    $checks = [];
    foreach ($protected as $capability) {
        $record = fixture979($types[$capability] ?? 'database_dump');
        $decision = gate979($capability, $record);
        $json = json_encode($decision, JSON_THROW_ON_ERROR);
        $checks[$capability] = [
            'leaks_ref' => str_contains($json, $record['artifact_ref']),
            'leaks_digest' => str_contains($json, $record['integrity']['digest']),
            'evidence' => $decision['evidence'] ?? null,
        ];
    }
    $out['checks'] = $checks;
} elseif ($case === 'unknown') {
    $out['checks'] = [
        'unknown' => gate979('unregistered.mutating', null),
        'read' => gate979('hostinger.read', null),
    ];
    $out['prohibitions'] = [];
    foreach ($policy as $capability => $classification) {
        if ($classification === 'owner_required' || $classification === 'forbidden') {
            $out['prohibitions'][$capability] = $classification;
            $out['checks'][$capability] = gate979($capability, null);
        }
    }
} else {
    fwrite(STDERR, "Scenario invalid\n");
    exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
