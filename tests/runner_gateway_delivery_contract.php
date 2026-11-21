<?php
declare(strict_types=1);

/**
 * Synthetic offline test only. Run with:
 * php tests/runner_gateway_delivery_contract.php start|resume|replay <temp-json>
 * Each invocation is a separate PHP process, simulating a restart between ACK
 * and the terminal event. No network, live token, PII or production operation.
 */
require __DIR__ . '/../src/RunnerGateway.php';
require __DIR__ . '/../src/RunnerHttpProtocol.php';
require __DIR__ . '/../src/SchedulerSelection.php';

use ControlBot\Runner\RunnerGateway;
use ControlBot\Scheduler\SchedulerSelection;

function cb1516Assert(bool $value, string $reason): void
{
    if (!$value) {
        throw new RuntimeException($reason);
    }
}

function cb1516Reject(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

$stage = $argv[1] ?? '';
$file = $argv[2] ?? '';
if (!in_array($stage, ['start', 'resume', 'replay'], true) || $file === '') {
    throw new RuntimeException('Synthetic stage and temp file required.');
}
$runnerId = '11111111-1111-7111-8111-111111111111';
$orderId = '22222222-2222-4222-8222-222222222222';
$attemptId = '33333333-3333-4333-8333-333333333333';
$leaseId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$sessionId = 'session_1516';
$order = [
    'version' => 1, 'order_id' => $orderId, 'attempt_id' => $attemptId,
    'generation' => 3, 'work_item_id' => 'pl0n3r/ControlBot#1516',
    'runner_id' => $runnerId, 'capability' => 'review-code', 'attempt' => 1,
    'scope' => 'controlbot', 'issued_at' => 1000, 'expires_at' => 1600,
    'instruction_ref' => 'controlbot:issue/1516',
];
$identity = [
    'version' => 1, 'runner_id' => $runnerId, 'protocol_version' => 1,
    'runtime' => 'php', 'runtime_version' => '1.0.0', 'platform' => 'linux',
    'placement' => 'synthetic-lab', 'capabilities' => ['review-code'],
    'max_parallel' => 2,
];
$heartbeat = [
    'version' => 1, 'runner_id' => $runnerId, 'sequence' => 1,
    'observed_at' => 1000, 'status' => 'ready',
    'capacity' => ['max' => 2, 'active' => 0], 'active_sessions' => [],
];
$rows = [[
    'version' => 1, 'policy_ref' => 'factory-dispatcher-v2',
    'key' => 'cb1516', 'source_ref' => 'pl0n3r/ControlBot#1516',
    'priority' => 'critical', 'generation' => 3,
    'required_capabilities' => ['review-code'], 'account_id' => 'account_1',
    'readiness' => ['ready' => true, 'reasons' => [],
                    'open_dependencies' => [], 'unknown_dependencies' => []],
]];
$selection = SchedulerSelection::request($rows);
$decision = [
    'version' => 1, 'policy_ref' => 'factory-dispatcher-v2',
    'request_fingerprint' => $selection['fingerprint'],
    'selected_key' => 'cb1516', 'selection_reason' => 'ready',
    'telemetry' => ['ready_not_selected' => [], 'excluded' => []],
];
SchedulerSelection::assertOfflineOrderSelected($selection, $decision, $rows, $order);

$packet = static fn(string $path, array $payload): array =>
    ['version' => 1, 'method' => 'POST', 'path' => '/v1/runner/' . $path, 'payload' => $payload];
$poll = $packet('poll', [
    'version' => 1, 'runner_id' => $runnerId, 'session_id' => $sessionId,
    'generation' => 3, 'requested_at' => 1011,
]);
$ack = $packet('ack', [
    'version' => 1, 'order_id' => $orderId, 'attempt_id' => $attemptId,
    'runner_id' => $runnerId, 'generation' => 3, 'acknowledged_at' => 1012,
]);
$event = static function (int $sequence, string $state, int $time) use (
    $packet, $runnerId, $orderId, $attemptId
): array {
    return $packet('event', [
        'version' => 1,
        'event_id' => sprintf('44444444-4444-4444-8444-%012d', $sequence),
        'order_id' => $orderId, 'attempt_id' => $attemptId,
        'runner_id' => $runnerId, 'generation' => 3,
        'sequence' => $sequence, 'state' => $state, 'occurred_at' => $time,
        'evidence' => ['code' => 'synthetic', 'summary' => 'offline fixture', 'ref' => null],
    ]);
};
$apply = static function (
    array $envelope, ?array $stored, int $now,
    ?array $identityOverride = null, ?array $beatOverride = null,
    ?array $orderOverride = null, ?string $leaseOverride = null,
    bool $credentialActive = true
) use ($order, $identity, $heartbeat, $leaseId, $sessionId): array {
    return RunnerGateway::offlineDelivery(
        $orderOverride ?? $order, $identityOverride ?? $identity,
        $beatOverride ?? $heartbeat, $leaseOverride ?? $leaseId,
        $sessionId, $credentialActive, $now, $envelope, $stored
    );
};

if ($stage === 'start') {
    $first = $apply($poll, null, 1011);
    cb1516Assert($apply($poll, $first, 1011) === $first, 'Poll retry must be idempotent');
    $acked = $apply($ack, $first, 1012);
    cb1516Assert($apply($ack, $acked, 1012) === $acked, 'ACK retry must be idempotent');
    cb1516Assert($acked['execution'] === false && $acked['provenance'] === 'synthetic-offline', 'Never attest production');
    cb1516Assert($acked['event'] === null && $acked['ack']['order_id'] === $orderId, 'Durable ACK without fabricated event');
    if (file_put_contents($file, json_encode($acked, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot persist synthetic receipt');
    }
    echo json_encode(['ok' => true, 'stage' => 'acked', 'order_id' => $orderId, 'lease_id' => $leaseId], JSON_THROW_ON_ERROR);
    exit;
}
$stored = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
cb1516Assert(is_array($stored) && $stored['ack'] !== null, 'ACK must survive process restart');
if ($stage === 'resume') {
    $foreign = $event(1, 'accepted', 1013);
    $foreign['payload']['runner_id'] = '99999999-9999-4999-8999-999999999999';
    cb1516Assert(cb1516Reject(static fn(): array => $apply($foreign, $stored, 1013)), 'Reject foreign runner');
    $current = $apply($event(1, 'accepted', 1013), $stored, 1013);
    $current = $apply($event(2, 'started', 1014), $current, 1014);
    $current = $apply($event(3, 'completed', 1015), $current, 1015);
    cb1516Assert($current['event']['state'] === 'completed'
        && $current['order']['order_id'] === $stored['order']['order_id']
        && $current['lease_id'] === $stored['lease_id'], 'Recovery identity or terminal state');
    if (file_put_contents($file, json_encode($current, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot persist terminal synthetic receipt');
    }
    echo json_encode(['ok' => true, 'stage' => 'terminal', 'order_id' => $orderId, 'recovered' => true], JSON_THROW_ON_ERROR);
    exit;
}

$terminal = $event(3, 'completed', 1015);
$conflict = $event(3, 'failed', 1015);
$old = $event(1, 'accepted', 1013);
$anotherOrder = array_replace($order, ['attempt_id' => '99999999-9999-4999-8999-999999999999']);
$negative = [
    'duplicate_terminal_idempotent' => $apply($terminal, $stored, 1020) === $stored,
    'replay_older_event' => cb1516Reject(static fn(): array => $apply($old, $stored, 1020)),
    'conflicting_duplicate' => cb1516Reject(static fn(): array => $apply($conflict, $stored, 1020)),
    'token_revoked' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1020, credentialActive: false)),
    'heartbeat_stale' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1200)),
    'foreign_lease' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1020, leaseOverride: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')),
    'conflicting_order' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1020, orderOverride: $anotherOrder)),
    'zero_capacity' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1020, beatOverride: array_replace($heartbeat, ['capacity' => ['max' => 2, 'active' => 2]]))),
    'foreign_identity' => cb1516Reject(static fn(): array => $apply($terminal, $stored, 1020, identityOverride: array_replace($identity, ['runner_id' => '99999999-9999-4999-8999-999999999999']))),
    'selection_drift' => cb1516Reject(static fn() => SchedulerSelection::assertOfflineOrderSelected($selection, $decision, [array_replace($rows[0], ['generation' => 4])], $order)),
    'selection_wrong_order' => cb1516Reject(static fn() => SchedulerSelection::assertOfflineOrderSelected($selection, $decision, $rows, array_replace($order, ['work_item_id' => 'pl0n3r/ControlBot#1517']))),
];
cb1516Assert(!in_array(false, $negative, true), 'Fail-closed offline contract negatives');
cb1516Assert(!str_contains(json_encode($stored, JSON_THROW_ON_ERROR), 'VALIDATED_IN_PRODUCTION'), 'No mock production attestation');
echo json_encode(['ok' => true, 'stage' => 'replay', 'checks' => $negative, 'provenance' => $stored['provenance']], JSON_THROW_ON_ERROR);
