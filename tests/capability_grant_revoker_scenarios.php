<?php
declare(strict_types=1);
require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/CapabilityGrant.php';
require __DIR__ . '/../src/CapabilityGrantRevoker.php';

use ControlBot\Production\CapabilityGrant;
use ControlBot\Production\CapabilityGrantRevoker;
$scenario = $argv[1] ?? '';
$now = strtotime('2026-09-28T08:10:00Z');
function record(array $changes = []): array
{
    return array_replace([
        'version' => 1,
        'grant_id' => '11111111-2222-4333-8444-555555555555',
        'capability' => 'hostinger.read',
        'project' => 'brvtal',
        'environment' => 'production',
        'resource' => 'site:primary',
        'operation' => 'read',
        'issue' => 'pl0n3r/brvtal#681',
        'run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'subject' => 'agent-controlbot-01',
        'issued_at' => '2026-09-28T08:00:00Z',
        'expires_at' => '2026-09-28T08:30:00Z',
        'backup_receipt_id' => null,
        'owner_approval_id' => null,
        'idempotency_key' => 'idem:brvtal-681-01',
        'revoked_at' => null,
    ], $changes);
}

function scope(array $record): array
{
    $fields = ['capability', 'project', 'environment', 'resource', 'operation', 'issue', 'run_id', 'subject'];
    return array_intersect_key($record, array_flip($fields));
}
function view(array $result): array
{
    return [
        'status' => $result['status'],
        'reason' => $result['reason'],
        'revoked_at' => $result['grant']->safeRecord()['revoked_at'],
        'audit' => $result['audit'],
        'error_code' => $result['error_code'] ?? null,
    ];
}
$input = record();
$grant = CapabilityGrant::issue($input);
$guard = new CapabilityGrantRevoker();

if ($scenario === 'finish') {
    $calls = 0;
    $success = $guard->execute($grant, scope($input), $now, function () use (&$calls): bool { $calls++; return true; });
    $replay = $guard->execute($grant, scope($input), $now, function () use (&$calls): bool { $calls += 100; return true; });
    $after = $guard->authorize($grant, scope($input), $now);
    $input2 = record([
        'grant_id' => '22222222-3333-4444-8555-666666666666',
        'idempotency_key' => 'idem:brvtal-681-02',
    ]);
    $failed = $guard->execute(CapabilityGrant::issue($input2), scope($input2), $now, function (): void {
        throw new RuntimeException('private bearer token: totally-secret');
    });
    $input3 = record([
        'grant_id' => '33333333-4444-4555-8666-777777777777',
        'idempotency_key' => 'idem:brvtal-681-03',
    ]);
    $ambiguous = $guard->execute(CapabilityGrant::issue($input3), scope($input3), $now, function (): bool { return false; });
    $out = [
        'ambiguous' => view($ambiguous), 'ambiguous_after' => $guard->authorize($ambiguous['grant'], scope($input3), $now),
        'success' => view($success), 'replay' => view($replay), 'after' => $after,
        'failed' => view($failed), 'failed_after' => $guard->authorize($failed['grant'], scope($input2), $now),
        'calls' => $calls,
    ];
} elseif ($scenario === 'deny') {
    $calls = 0;
    $expired = $guard->execute($grant, scope($input), strtotime('2026-09-28T08:31:00Z'), function () use (&$calls): bool { $calls++; return true; });
    $revoked = CapabilityGrant::issue(record(['revoked_at' => '2026-09-28T08:09:00Z']));
    $revokedResult = $guard->execute($revoked, scope($input), $now, function () use (&$calls): bool { $calls++; return true; });
    $reentrant = null;
    $success = $guard->execute($grant, scope($input), $now, function () use ($guard, $grant, $input, $now, &$reentrant, &$calls): bool {
        $calls++;
        $reentrant = view($guard->execute($grant, scope($input), $now, function () use (&$calls): bool { $calls += 100; return true; }));
        return true;
    });
    $newId = record(['grant_id' => '22222222-3333-4444-8555-666666666666']);
    $collision = $guard->execute(CapabilityGrant::issue($newId), scope($newId), $now, function () use (&$calls): bool { $calls += 100; return true; });
    $scopeCollision = $guard->execute($grant, array_replace(scope($input), ['project' => 'condor']), $now, function () use (&$calls): bool { $calls += 100; return true; });
    $out = [
        'scope_collision' => view($scopeCollision),
        'expired' => view($expired), 'revoked' => view($revokedResult),
        'reentrant' => $reentrant, 'consumed' => $guard->authorize($grant, scope($input), $now),
        'collision' => view($collision), 'calls' => $calls, 'success' => view($success),
    ];
} elseif ($scenario === 'inflight_key') {
    // Two valid grants, different UUIDs but *one* idempotency key: the
    // nested callback for grant B must never execute while A is in flight.
    $inputB = record(['grant_id' => '22222222-3333-4444-8555-666666666666']);
    $grantB = CapabilityGrant::issue($inputB);
    $calls = 0;
    $nested = null;
    $nestedAuth = null;
    $success = $guard->execute(
        $grant, scope($input), $now,
        function () use ($guard, $grantB, $inputB, $now, &$calls, &$nested, &$nestedAuth): bool {
            $calls++;
            $nestedAuth = $guard->authorize($grantB, scope($inputB), $now);
            $nested = $guard->execute($grantB, scope($inputB), $now, function () use (&$calls): bool {
                $calls += 100;
                return true;
            });
            return true;
        }
    );
    // A completed; B remains rejected by the settled receipt, without
    // overwriting it or invoking the fake callback.
    $post = $guard->execute($grantB, scope($inputB), $now, function () use (&$calls): bool {
        $calls += 100;
        return true;
    });
    // An independent key remains usable; locking is keyed, not global.
    $inputC = record([
        'grant_id' => '33333333-4444-4555-8666-777777777777',
        'idempotency_key' => 'idem:brvtal-681-03',
    ]);
    $separate = $guard->execute(CapabilityGrant::issue($inputC), scope($inputC), $now, function () use (&$calls): bool {
        $calls++;
        return true;
    });
    $out = [
        'a' => view($success), 'b_inflight' => view($nested),
        'b_authorize' => $nestedAuth, 'b_after' => view($post),
        'separate' => view($separate), 'calls' => $calls,
    ];
} elseif ($scenario === 'privacy') {
    $failed = $guard->execute($grant, scope($input), $now, function (): void {
        throw new RuntimeException('password=private cookie=hidden token=bad');
    });
    $out = ['failed' => view($failed), 'serialized_audit' => json_encode($failed['audit'], JSON_THROW_ON_ERROR)];
} elseif ($scenario === 'existing') {
    $read = $grant->authorize(scope($input), $now);
    $restricted = $grant->authorize(scope($input), strtotime('2026-09-28T08:31:00Z'));
    $guardRead = $guard->authorize($grant, scope($input), $now);
    $scopeWrong = $guard->authorize($grant, array_replace(scope($input), ['project' => 'condor']), $now);
    $out = [
        'read' => $read, 'restricted' => $restricted,
        'guard_read' => $guardRead, 'scope_wrong' => $scopeWrong,
        'original_revoked_at' => $grant->safeRecord()['revoked_at'],
    ];
} else {
    fwrite(STDERR, "unknown scenario\n");
    exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
