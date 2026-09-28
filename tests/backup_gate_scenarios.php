<?php
declare(strict_types=1);

require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/BackupReceipt.php';
require __DIR__ . '/../src/BackupGate.php';

use ControlBot\Production\BackupGate;

date_default_timezone_set('America/Bogota');

const WRITE_AT = 1_800_000_300;

function receipt(array $replace = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'receipt_id' => '11111111-2222-4333-8444-555555555555',
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'backup_type' => 'database_dump',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue' => 'pl0n3r/brvtal#681',
        'created_at' => '2027-01-15T07:00:00Z',
        'verified_at' => '2027-01-15T07:01:00Z',
        'status' => 'ready',
        'artifact_ref' => 'artifact:backup:brvtal:681',
        'integrity' => [
            'algorithm' => 'sha256',
            'digest' => str_repeat('a', 64),
        ],
        'restore_capability' => 'manual',
        'expires_at' => '2027-01-15T08:00:00Z',
    ], $replace);
}

function scope(array $replace = []): array
{
    return array_replace([
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue' => 'pl0n3r/brvtal#681',
    ], $replace);
}

$name = $argv[1] ?? '';

if ($name === 'missing') {
    $out = BackupGate::evaluate(
        'migration.registry.reconcile',
        scope(),
        null,
        WRITE_AT,
    );
} elseif ($name === 'scope') {
    $cases = [];
    foreach ([
        'project' => 'condor',
        'environment' => 'staging',
        'resource' => 'database:secondary',
        'run_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'issue' => 'pl0n3r/brvtal#999',
    ] as $key => $value) {
        $cases[$key] = BackupGate::evaluate(
            'migration.registry.reconcile',
            scope(),
            receipt([$key => $value]),
            WRITE_AT,
        );
    }
    $out = $cases;
} elseif ($name === 'causality') {
    $out = [
        'not_ready' => BackupGate::evaluate(
            'migration.registry.reconcile',
            scope(),
            receipt(['status' => 'running']),
            WRITE_AT,
        ),
        'verified_after_write' => BackupGate::evaluate(
            'migration.registry.reconcile',
            scope(),
            receipt([
                'created_at' => '2027-01-15T07:10:00Z',
                'verified_at' => '2027-01-15T07:11:00Z',
            ]),
            strtotime('2027-01-15T07:10:30Z'),
        ),
        'expired' => BackupGate::evaluate(
            'migration.registry.reconcile',
            scope(),
            receipt(['expires_at' => '2027-01-15T07:02:00Z']),
            strtotime('2027-01-15T07:03:00Z'),
        ),
        'ready' => BackupGate::evaluate(
            'migration.registry.reconcile',
            scope(),
            receipt(),
            strtotime('2027-01-15T07:02:00Z'),
        ),
    ];
} elseif ($name === 'readonly') {
    $out = [
        'read' => BackupGate::evaluate('hostinger.read', scope(), null, WRITE_AT),
        'status' => BackupGate::evaluate('migration.status', scope(), null, WRITE_AT),
        'owner' => BackupGate::evaluate('database.destructive', scope(), null, WRITE_AT),
    ];
} elseif ($name === 'evidence') {
    $record = receipt();
    $result = BackupGate::evaluate(
        'migration.registry.reconcile',
        scope(),
        $record,
        strtotime('2027-01-15T07:02:00Z'),
    );
    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $out = [
        'allowed' => $result['allowed'],
        'evidence' => $result['evidence'],
        'contains_artifact_ref' => str_contains($encoded, $record['artifact_ref']),
        'contains_digest' => str_contains($encoded, $record['integrity']['digest']),
        'contains_payload_key' => preg_match('/(dump_payload|sql_payload|password|token|private_key)/i', $encoded) === 1,
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
