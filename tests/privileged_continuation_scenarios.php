<?php
declare(strict_types=1);

require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/CapabilityGrant.php';
require __DIR__ . '/../src/OwnerAction.php';
require __DIR__ . '/../src/ContinuationToken.php';
require __DIR__ . '/../src/ContinuationRuntime.php';

use ControlBot\Production\ContinuationRuntime;
use ControlBot\Production\OwnerAction;

$scenario = $argv[1] ?? '';
$now = strtotime('2026-09-28T09:05:00Z');

function actionRecord(
    string $capability = 'database.destructive',
    string $operation = 'delete_rows',
): array {
    return [
        'version' => 1,
        'capability' => $capability,
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'operation' => $operation,
        'issue' => 'pl0n3r/ControlBot#50',
        'title' => 'Autorizar operación privilegiada',
        'summary' => 'Eliminar filas seleccionadas por un plan ya validado.',
        'why' => 'La operación requiere autoridad explícita del dueño.',
        'backup' => 'Backup previo requerido por la política operativa.',
        'risk' => 'Riesgo alto y acotado al recurso indicado.',
        'reversibility' => 'Restaurar el backup previo si la verificación falla.',
        'scope' => 'Solo database:primary en producción para este run.',
    ];
}

function tokenRecord(): array
{
    return [
        'version' => 1,
        'continuation_id' => '11111111-2222-4333-8444-555555555555',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue' => 'pl0n3r/ControlBot#50',
        'project' => 'brvtal',
        'environment' => 'production',
        'blocked_on' => 'owner_approval',
        'expected_action' => 'delete_rows',
        'plan_digest' => str_repeat('b', 64),
        'source_sha' => str_repeat('a', 40),
        'risk_digest' => str_repeat('c', 64),
        'created_at' => '2026-09-28T09:00:00Z',
        'expires_at' => '2026-09-28T09:15:00Z',
        'resumed_at' => null,
        'consumed_at' => null,
    ];
}

function currentScope(): array
{
    return [
        'capability' => 'database.destructive',
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'database:primary',
        'operation' => 'delete_rows',
        'issue' => 'pl0n3r/ControlBot#50',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'source_sha' => str_repeat('a', 40),
        'plan_digest' => str_repeat('b', 64),
        'risk_digest' => str_repeat('c', 64),
    ];
}

function registeredRuntime(int $now): ContinuationRuntime
{
    $runtime = new ContinuationRuntime();
    $runtime->interrupt(actionRecord(), tokenRecord(), $now);
    return $runtime;
}

function approve(ContinuationRuntime $runtime, array $current, int $now, string $grantId = '22222222-3333-4444-8555-666666666666'): array
{
    return $runtime->approve(
        '11111111-2222-4333-8444-555555555555',
        $current,
        '33333333-4444-4555-8666-777777777777',
        $grantId,
        'idem:controlbot-50-approve',
        'owner-controlbot',
        '2026-09-28T09:05:00Z',
        '2026-09-28T09:10:00Z',
        $now,
    );
}

$out = [];

if ($scenario === 'approve') {
    $runtime = registeredRuntime($now);
    $out = approve($runtime, currentScope(), $now);
} elseif ($scenario === 'reject') {
    $runtime = registeredRuntime($now);
    $out = $runtime->reject(
        '11111111-2222-4333-8444-555555555555',
        currentScope(),
        '2026-09-28T09:05:00Z',
        $now,
    );
} elseif ($scenario === 'replay') {
    $runtime = registeredRuntime($now);
    $first = approve($runtime, currentScope(), $now);
    $second = $runtime->approve(
        '11111111-2222-4333-8444-555555555555',
        currentScope(),
        '44444444-5555-4666-8777-888888888888',
        '55555555-6666-4777-8888-999999999999',
        'idem:controlbot-50-second-click',
        'owner-controlbot',
        '2026-09-28T09:06:00Z',
        '2026-09-28T09:11:00Z',
        $now + 60,
    );
    $out = ['first' => $first, 'second' => $second, 'same' => $first === $second];
} elseif ($scenario === 'drift') {
    foreach ([
        'source_sha' => str_repeat('d', 40),
        'operation' => 'drop_table',
        'plan_digest' => str_repeat('e', 64),
        'risk_digest' => str_repeat('f', 64),
    ] as $field => $changed) {
        $runtime = registeredRuntime($now);
        $current = currentScope();
        $current[$field] = $changed;
        try {
            approve($runtime, $current, $now);
            $out[$field] = 'accepted';
        } catch (Throwable $error) {
            $out[$field] = $error->getMessage();
        }
    }
} elseif ($scenario === 'surface') {
    $invalid = actionRecord();
    $invalid['summary'] = 'token=super-secret-value';
    try {
        OwnerAction::fromRecord($invalid);
        $out['unsafe'] = 'accepted';
    } catch (Throwable $error) {
        $out['unsafe'] = $error->getMessage();
    }

    $runtime = registeredRuntime($now);
    $out['surface'] = $runtime->ownerSurface('11111111-2222-4333-8444-555555555555');
} elseif ($scenario === 'automatic') {
    $runtime = new ContinuationRuntime();
    $out = $runtime->interrupt(
        actionRecord('hostinger.read', 'read'),
        null,
        $now,
    );
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
