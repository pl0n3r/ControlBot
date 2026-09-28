<?php
declare(strict_types=1);

require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/CapabilityGrant.php';

use ControlBot\Production\CapabilityGrant;
use ControlBot\Production\CapabilityPolicy;

$scenario = $argv[1] ?? '';
$issued = '2026-09-28T08:00:00Z';
$expires = '2026-09-28T08:30:00Z';
$now = strtotime('2026-09-28T08:10:00Z');

function grantRecord(
    string $capability,
    string $operation,
    ?string $backup = null,
    ?string $approval = null,
    ?string $revoked = null,
    string $project = 'brvtal',
    string $environment = 'production',
    string $resource = 'database:primary',
    string $run = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    string $subject = 'agent-controlbot-01',
    string $issued = '2026-09-28T08:00:00Z',
    string $expires = '2026-09-28T08:30:00Z',
): array {
    return [
        'version' => 1,
        'grant_id' => '11111111-2222-4333-8444-555555555555',
        'capability' => $capability,
        'project' => $project,
        'environment' => $environment,
        'resource' => $resource,
        'operation' => $operation,
        'issue' => 'pl0n3r/brvtal#681',
        'run_id' => $run,
        'subject' => $subject,
        'issued_at' => $issued,
        'expires_at' => $expires,
        'backup_receipt_id' => $backup,
        'owner_approval_id' => $approval,
        'idempotency_key' => 'idem:brvtal-681-01',
        'revoked_at' => $revoked,
    ];
}

function scope(array $record, array $replace = []): array
{
    return array_replace([
        'capability' => $record['capability'],
        'project' => $record['project'],
        'environment' => $record['environment'],
        'resource' => $record['resource'],
        'operation' => $record['operation'],
        'issue' => $record['issue'],
        'run_id' => $record['run_id'],
        'subject' => $record['subject'],
    ], $replace);
}

if ($scenario === 'unknown') {
    $out = [
        'unknown' => CapabilityPolicy::classify('hostinger.read.extra'),
        'restricted' => CapabilityPolicy::classify('hostinger.read', ['owner_required']),
    ];
} elseif ($scenario === 'read') {
    $record = grantRecord('hostinger.read', 'read');
    $grant = CapabilityGrant::issue($record);
    $out = [
        'policy' => CapabilityPolicy::classify('hostinger.read'),
        'auth' => $grant->authorize(scope($record), $now),
        'backup' => $grant->safeRecord()['backup_receipt_id'],
        'approval' => $grant->safeRecord()['owner_approval_id'],
    ];
} elseif ($scenario === 'backup') {
    $missing = null;
    try {
        CapabilityGrant::issue(grantRecord('migration.registry.reconcile', 'reconcile'));
    } catch (Throwable $e) {
        $missing = $e->getMessage();
    }
    $record = grantRecord(
        'migration.registry.reconcile',
        'reconcile',
        '22222222-3333-4444-8555-666666666666'
    );
    $grant = CapabilityGrant::issue($record);
    $out = ['missing' => $missing, 'auth' => $grant->authorize(scope($record), $now)];
} elseif ($scenario === 'owner') {
    $missing = null;
    try {
        CapabilityGrant::issue(grantRecord('database.destructive', 'delete_rows'));
    } catch (Throwable $e) {
        $missing = $e->getMessage();
    }
    $record = grantRecord(
        'database.destructive',
        'delete_rows',
        null,
        '33333333-4444-4555-8666-777777777777'
    );
    $grant = CapabilityGrant::issue($record);
    $forbidden = null;
    try {
        CapabilityGrant::issue(grantRecord('shell.arbitrary', 'execute'));
    } catch (Throwable $e) {
        $forbidden = $e->getMessage();
    }
    $out = ['missing' => $missing, 'auth' => $grant->authorize(scope($record), $now), 'forbidden' => $forbidden];
} elseif ($scenario === 'scope') {
    $record = grantRecord('hostinger.read', 'read');
    $grant = CapabilityGrant::issue($record);
    $cases = [];
    foreach ([
        'project' => 'condor',
        'environment' => 'staging',
        'resource' => 'site:secondary',
        'operation' => 'status',
        'issue' => 'pl0n3r/condor#999',
        'run_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'subject' => 'agent-controlbot-02',
    ] as $key => $value) {
        $cases[$key] = $grant->authorize(scope($record, [$key => $value]), $now);
    }
    $out = $cases;
} elseif ($scenario === 'lifecycle') {
    date_default_timezone_set('America/Bogota');
    $record = grantRecord('hostinger.read', 'read');
    $grant = CapabilityGrant::issue($record);
    $out = [
        'expired' => $grant->authorize(scope($record), strtotime('2026-09-28T08:31:00Z')),
        'revoked' => $grant->revoke('2026-09-28T08:11:00Z')->authorize(scope($record), $now),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
