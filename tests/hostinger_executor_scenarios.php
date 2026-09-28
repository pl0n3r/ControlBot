<?php
declare(strict_types=1);

require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/CapabilityGrant.php';
require __DIR__ . '/../src/ConnectionProfile.php';
require __DIR__ . '/../src/SecretReference.php';
require __DIR__ . '/../src/SecretsBroker.php';
require __DIR__ . '/../src/ProductionOperation.php';
require __DIR__ . '/../src/HostingerExecutor.php';

use ControlBot\Production\CapabilityGrant;
use ControlBot\Production\ConnectionProfile;
use ControlBot\Production\HostingerExecutor;
use ControlBot\Production\ProductionOperation;
use ControlBot\Production\SecretReference;
use ControlBot\Production\SecretsBroker;

const NOW = 1_799_997_000;
const SECRET = 'fixture-hostinger-secret-839201';

function secretRef(string $capability): SecretReference
{
    return SecretReference::fromRecord([
        'version' => 1,
        'reference_id' => '11111111-2222-4333-8444-555555555555',
        'capability' => $capability,
        'project' => 'brvtal',
        'environment' => 'production',
        'provider' => 'hostinger',
        'secret_kind' => 'password',
        'generation' => 1,
        'issued_at' => '2027-01-15T07:00:00Z',
        'revoked_at' => null,
    ]);
}

function profile(): ConnectionProfile
{
    return ConnectionProfile::fromServerRecord([
        'version' => 1,
        'profile_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'project' => 'brvtal',
        'environment' => 'production',
        'provider' => 'hostinger',
        'transport' => 'ssh',
        'host' => 'example.internal',
        'port' => 22,
        'username_ref' => 'vault:user:brvtal',
        'secret_ref' => '11111111-2222-4333-8444-555555555555',
        'host_fingerprint' => 'SHA256:abcdefghijklmnop',
        'status' => 'connected',
        'verified_at' => '2027-01-15T07:00:00+00:00',
        'last_health_at' => '2027-01-15T07:00:00+00:00',
        'revoked_at' => null,
    ]);
}

function request(string $idempotency = 'idem:brvtal-681-001'): array
{
    return [
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'issue' => 'pl0n3r/brvtal#681',
        'run_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'subject' => 'hostinger-executor',
        'idempotency_key' => $idempotency,
        'now' => NOW,
    ];
}

function grant(string $operationId, string $capability, string $idempotency = 'idem:brvtal-681-001'): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version' => 1,
        'grant_id' => '99999999-8888-4777-8666-555555555555',
        'capability' => $capability,
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'operation' => $operationId,
        'issue' => 'pl0n3r/brvtal#681',
        'run_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'subject' => 'hostinger-executor',
        'issued_at' => '2027-01-15T07:00:00Z',
        'expires_at' => '2027-01-15T08:00:00Z',
        'backup_receipt_id' => $capability === 'migration.registry.reconcile'
            ? '77777777-6666-4555-8444-333333333333'
            : null,
        'owner_approval_id' => null,
        'idempotency_key' => $idempotency,
        'revoked_at' => null,
    ]);
}

function brokerFor(SecretReference $reference): SecretsBroker
{
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($reference, SECRET);
    return $broker;
}

$name = $argv[1] ?? '';

if ($name === 'missing_grant') {
    $calls = 0;
    $ref = secretRef('ssh.readonly');
    $executor = new HostingerExecutor(
        brokerFor($ref),
        static function () use (&$calls): array {
            $calls++;
            return ['status' => 'success', 'code' => 'ok', 'summary' => 'unexpected', 'artifacts' => [], 'duration_ms' => 1];
        },
    );
    $out = [
        'result' => $executor->execute(
            ProductionOperation::fromId('ssh.readonly'),
            null,
            profile(),
            $ref,
            request(),
        ),
        'transport_calls' => $calls,
    ];
} elseif ($name === 'grant_mismatch') {
    $calls = 0;
    $ref = secretRef('migration.verify');
    $executor = new HostingerExecutor(
        brokerFor($ref),
        static function () use (&$calls): array {
            $calls++;
            return ['status' => 'success', 'code' => 'ok', 'summary' => 'unexpected', 'artifacts' => [], 'duration_ms' => 1];
        },
    );
    $out = [
        'result' => $executor->execute(
            ProductionOperation::fromId('migration.verify'),
            grant('migration.status', 'migration.status'),
            profile(),
            $ref,
            request(),
        ),
        'transport_calls' => $calls,
    ];
} elseif ($name === 'redaction') {
    $calls = 0;
    $ref = secretRef('ssh.readonly');
    $executor = new HostingerExecutor(
        brokerFor($ref),
        static function (array $descriptor, string $secret) use (&$calls): array {
            $calls++;
            return [
                'status' => 'success',
                'code' => 'ssh_read_ok',
                'summary' => 'password=' . $secret . ' owner@example.com SELECT * FROM users ' . str_repeat('x', 900),
                'artifacts' => ['artifact:ssh:read:1'],
                'duration_ms' => 25,
            ];
        },
    );
    $result = $executor->execute(
        ProductionOperation::fromId('ssh.readonly'),
        grant('ssh.readonly', 'ssh.readonly'),
        profile(),
        $ref,
        request(),
    );
    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $out = [
        'result' => $result,
        'transport_calls' => $calls,
        'contains_secret' => str_contains($encoded, SECRET),
        'contains_email' => str_contains($encoded, 'owner@example.com'),
        'contains_sql' => str_contains(strtoupper($encoded), 'SELECT * FROM'),
        'summary_length' => strlen($result['result']['evidence']['summary'] ?? ''),
    ];
} elseif ($name === 'terminal_states') {
    $ref = secretRef('ssh.readonly');

    $timeoutExecutor = new HostingerExecutor(
        brokerFor($ref),
        static fn (): array => [
            'status' => 'success',
            'code' => 'late_success',
            'summary' => 'late',
            'artifacts' => [],
            'duration_ms' => 9_000,
        ],
    );
    $partialExecutor = new HostingerExecutor(
        brokerFor($ref),
        static fn (): array => [
            'status' => 'partial',
            'code' => 'partial_read',
            'summary' => 'partial bounded result',
            'artifacts' => [],
            'duration_ms' => 10,
        ],
    );

    $out = [
        'timeout' => $timeoutExecutor->execute(
            ProductionOperation::fromId('ssh.readonly'),
            grant('ssh.readonly', 'ssh.readonly'),
            profile(),
            $ref,
            request(),
        ),
        'partial' => $partialExecutor->execute(
            ProductionOperation::fromId('ssh.readonly'),
            grant('ssh.readonly', 'ssh.readonly'),
            profile(),
            $ref,
            request(),
        ),
    ];
} elseif ($name === 'replay') {
    $calls = 0;
    $ref = secretRef('migration.registry.reconcile');
    $executor = new HostingerExecutor(
        brokerFor($ref),
        static function () use (&$calls): array {
            $calls++;
            return [
                'status' => 'success',
                'code' => 'reconcile_ok',
                'summary' => 'registry reconciled',
                'artifacts' => ['artifact:migration:receipt:1'],
                'duration_ms' => 50,
            ];
        },
    );

    $operation = ProductionOperation::fromId('migration.registry.reconcile');
    $grant = grant('migration.registry.reconcile', 'migration.registry.reconcile');
    $request = request();

    $out = [
        'first' => $executor->execute($operation, $grant, profile(), $ref, $request),
        'second' => $executor->execute($operation, $grant, profile(), $ref, $request),
        'transport_calls' => $calls,
    ];
} elseif ($name === 'fake_transport') {
    $calls = 0;
    $ref = secretRef('health.check');
    $executor = new HostingerExecutor(
        brokerFor($ref),
        static function (array $descriptor, string $secret) use (&$calls): array {
            $calls++;
            return [
                'status' => 'success',
                'code' => 'health_ok',
                'summary' => 'fake transport only, credential bytes=' . strlen($secret),
                'artifacts' => ['artifact:health:fake:1'],
                'duration_ms' => 5,
            ];
        },
    );

    $out = [
        'result' => $executor->execute(
            ProductionOperation::fromId('health.check'),
            grant('health.check', 'health.check'),
            profile(),
            $ref,
            request('idem:brvtal-health-001'),
        ),
        'transport_calls' => $calls,
        'transport_kind' => 'fake',
        'network_used' => false,
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
