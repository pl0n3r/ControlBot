<?php
declare(strict_types=1);

require __DIR__ . '/../src/RunnerGateway.php';

use ControlBot\Runner\RunnerGateway;

function rejected(callable $work): bool
{
    try {
        $work();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

$runnerId = '11111111-1111-7111-8111-111111111111';
$runnerB = '99999999-9999-7999-8999-999999999999';
$orderId = '22222222-2222-4222-8222-222222222222';
$attemptId = '44444444-4444-7444-8444-444444444444';
$attemptB = '55555555-5555-7555-8555-555555555555';
$identity = [
    'version' => 1,
    'runner_id' => $runnerId,
    'protocol_version' => 1,
    'runtime' => 'php',
    'runtime_version' => '1.0.0',
    'platform' => 'linux-arm64',
    'placement' => 'hostinger-shared',
    'capabilities' => ['test-php', 'review-code'],
    'max_parallel' => 3,
];
$signal = [
    'version' => 1,
    'runner_id' => $runnerId,
    'sequence' => 7,
    'observed_at' => 1_000,
    'status' => 'busy',
    'capacity' => ['max' => 3, 'active' => 1],
    'active_sessions' => ['session_001'],
];

$order = [
    'version' => 1,
    'order_id' => $orderId,
    'attempt_id' => $attemptId,
    'generation' => 1,
    'work_item_id' => 'github:pl0n3r/ControlBot#88',
    'runner_id' => $runnerId,
    'capability' => 'review-code',
    'attempt' => 1,
    'scope' => 'repo:pl0n3r/ControlBot',
    'issued_at' => 1_000,
    'expires_at' => 1_300,
    'instruction_ref' => 'controlbot:work-items/88/instruction',
];

function eventRecord(
    string $state,
    int $sequence,
    string $orderId,
    string $attemptId,
    string $runnerId,
    int $generation = 1,
    ?array $evidence = null,
): array {
    return [
        'version' => 1,
        'event_id' => sprintf('33333333-3333-4333-8333-%012d', $sequence),
        'order_id' => $orderId,
        'attempt_id' => $attemptId,
        'runner_id' => $runnerId,
        'generation' => $generation,
        'sequence' => $sequence,
        'state' => $state,
        'occurred_at' => 1_000 + $sequence,
        'evidence' => $evidence ?? ['code' => 'ok', 'summary' => 'evidencia saneada', 'ref' => null],
    ];
}

$scenario = $argv[1] ?? '';
if ($scenario === 'identity') {
    $canonical = RunnerGateway::identity(array_reverse($identity, true));
    $roundtrip = RunnerGateway::identity(json_decode(
        json_encode($canonical, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR
    ));
    $bad = [
        'extra' => $identity + ['secret' => 'not-allowed'],
        'duplicate' => array_replace($identity, ['capabilities' => ['test-php', 'test-php']]),
        'invalid' => array_replace($identity, ['runner_id' => '../runner']),
        'version' => array_replace($identity, ['runtime_version' => 'v1']),
        'empty' => array_replace($identity, ['capabilities' => []]),
        'protocol' => array_replace($identity, ['protocol_version' => 2]),
        'capacity' => array_replace($identity, ['max_parallel' => 0]),
    ];
    echo json_encode([
        'stable' => $canonical === $roundtrip,
        'identity' => $roundtrip,
        'rejects' => array_map(
            static fn(array $row): bool => rejected(
                static fn(): array => RunnerGateway::identity($row)
            ), $bad
        ),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'heartbeat') {
    $readings = [];
    foreach ([
        'fresh' => [1_060, $signal],
        'stale' => [1_241, $signal],
        'offline' => [1_301, $signal],
        'future' => [999, $signal],
        'missing' => [1_000, null],
        'draining' => [1_000, array_replace($signal, ['status' => 'draining'])],
        'full' => [1_000, array_replace($signal, ['capacity' => ['max' => 3, 'active' => 3]])],
    ] as $name => [$now, $beat]) {
        $readings[$name] = RunnerGateway::health($identity, $beat, $now, 60, ['work_001']);
    }

    $ttlRejects = [
        'equal' => rejected(static fn(): array => RunnerGateway::health($identity, $signal, 1_000, 60, [], 60)),
        'lower' => rejected(static fn(): array => RunnerGateway::health($identity, $signal, 1_000, 60, [], 30)),
    ];

    $bad = [
        'unknown_runner' => array_replace($signal, ['runner_id' => '22222222-2222-7222-8222-222222222222']),
        'extra' => $signal + ['token' => 'bad'],
        'negative' => array_replace($signal, ['capacity' => ['max' => 3, 'active' => -1]]),
        'over_capacity' => array_replace($signal, ['capacity' => ['max' => 3, 'active' => 4]]),
        'duplicate_sessions' => array_replace($signal, ['active_sessions' => ['session_001', 'session_001']]),
        'session_overflow' => array_replace($signal, ['capacity' => ['max' => 3, 'active' => 0]]),
        'identity_capacity_mismatch' => array_replace($signal, ['capacity' => ['max' => 2, 'active' => 1]]),
    ];
    echo json_encode([
        'readings' => $readings,
        'rejects' => array_map(
            static fn(array $row): bool => rejected(
                static fn(): array => RunnerGateway::health($identity, $row, 1_000, 60)
            ), $bad
        ),
        'ttl_rejects' => $ttlRejects,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'portable') {
    $a = RunnerGateway::health($identity, $signal, 1_020, 60, ['work_001']);
    $b = RunnerGateway::health(
        array_replace($identity, ['placement' => 'macos-local']),
        $signal, 1_020, 60, ['work_001']
    );
    echo json_encode(['same' => $a === $b, 'health' => $a], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'order') {
    $canonical = RunnerGateway::order(array_reverse($order, true));
    $same = RunnerGateway::order($order);
    $nextGeneration = array_replace($order, [
        'attempt_id' => $attemptB,
        'generation' => 2,
        'runner_id' => $runnerB,
        'attempt' => 2,
    ]);
    $bad = [
        'extra_payload' => $order + ['payload' => ['prompt' => 'free-form']],
        'extra_token' => $order + ['token' => 'secret'],
        'missing_capability' => array_replace($order, ['capability' => '']),
        'secret_work_item' => array_replace($order, ['work_item_id' => 'github:ghp_abcdefghijklmnopqrstuvwxyz123456']),
        'secret_instruction' => array_replace($order, ['instruction_ref' => 'controlbot:token=supersecret']),
        'foreign_instruction' => array_replace($order, ['instruction_ref' => 'github:instruction']),
        'bad_generation' => array_replace($order, ['generation' => 0]),
        'bad_attempt_id' => array_replace($order, ['attempt_id' => 'attempt-1']),
        'ttl' => array_replace($order, ['expires_at' => 100_000]),
    ];
    $idempotent = true;
    try {
        RunnerGateway::assertIdempotentOrder($canonical, $same);
    } catch (InvalidArgumentException) {
        $idempotent = false;
    }
    $generationConflict = rejected(static function () use ($canonical, $nextGeneration): void {
        RunnerGateway::assertIdempotentOrder($canonical, $nextGeneration);
    });
    echo json_encode([
        'order' => $canonical,
        'stable' => $canonical === $same,
        'fingerprint_same' => RunnerGateway::orderFingerprint($canonical) === RunnerGateway::orderFingerprint($same),
        'idempotent' => $idempotent,
        'generation_conflict' => $generationConflict,
        'rejects' => array_map(
            static fn(array $row): bool => rejected(
                static fn(): array => RunnerGateway::order($row)
            ), $bad
        ),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'event') {
    $accepted = eventRecord('accepted', 1, $orderId, $attemptId, $runnerId);
    $started = eventRecord('started', 2, $orderId, $attemptId, $runnerId);
    $progress = eventRecord('progress', 3, $orderId, $attemptId, $runnerId);
    $completed = eventRecord('completed', 4, $orderId, $attemptId, $runnerId);
    RunnerGateway::assertEventTransition($accepted, $started, $order);
    RunnerGateway::assertEventTransition($started, $progress, $order);
    RunnerGateway::assertEventTransition($progress, $completed, $order);

    $nextOrder = array_replace($order, [
        'attempt_id' => $attemptB,
        'generation' => 2,
        'runner_id' => $runnerB,
        'attempt' => 2,
        'issued_at' => 1_400,
        'expires_at' => 1_700,
    ]);
    $nextAccepted = eventRecord('accepted', 1, $orderId, $attemptB, $runnerB, 2);

    $rejects = [
        'terminal_reopen' => rejected(static function () use ($completed, $orderId, $attemptId, $runnerId, $order): void {
            RunnerGateway::assertEventTransition(
                $completed,
                eventRecord('started', 5, $orderId, $attemptId, $runnerId),
                $order
            );
        }),
        'sequence_gap' => rejected(static function () use ($started, $orderId, $attemptId, $runnerId, $order): void {
            RunnerGateway::assertEventTransition(
                $started,
                eventRecord('progress', 4, $orderId, $attemptId, $runnerId),
                $order
            );
        }),
        'stale_after_handoff' => rejected(static function () use ($progress, $nextOrder): void {
            RunnerGateway::assertEventOwnedByOrder($progress, $nextOrder);
        }),
        'new_owner_accepts' => rejected(static function () use ($nextAccepted, $nextOrder): void {
            RunnerGateway::assertEventOwnedByOrder($nextAccepted, $nextOrder);
        }),
        'secret_summary' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'oops', 'summary' => 'token=supersecret', 'ref' => null,
            ])
        )),
        'evil_ref' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'ok', 'summary' => 'ok', 'ref' => 'https://evil.example/x',
            ])
        )),
        'query_ref' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'ok', 'summary' => 'ok',
                'ref' => 'https://github.com/pl0n3r/ControlBot/issues/88?token=private',
            ])
        )),
        'encoded_secret_ref' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'ok', 'summary' => 'ok', 'ref' => 'https://github.com/pl0n3r/%74oken/88',
            ])
        )),
        'backslash_ref' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'ok', 'summary' => 'ok', 'ref' => 'https://github.com/acme\\../safe',
            ])
        )),
        'dot_segment_ref' => rejected(static fn(): array => RunnerGateway::event(
            eventRecord('progress', 3, $orderId, $attemptId, $runnerId, 1, [
                'code' => 'ok', 'summary' => 'ok', 'ref' => 'https://github.com/acme/%2e%2e/safe',
            ])
        )),
    ];

    echo json_encode([
        'completed' => RunnerGateway::event($completed),
        'next_owner' => RunnerGateway::event($nextAccepted),
        'rejects' => $rejects,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'portable-execution') {
    $hostinger = RunnerGateway::identity($identity);
    $mac = RunnerGateway::identity(array_replace($identity, ['placement' => 'macos-local']));
    $parsedOrder = RunnerGateway::order($order);
    $parsedEvent = RunnerGateway::event(eventRecord('accepted', 1, $orderId, $attemptId, $runnerId));
    echo json_encode([
        'same_order' => $parsedOrder === RunnerGateway::order($order),
        'same_event' => $parsedEvent === RunnerGateway::event(eventRecord('accepted', 1, $orderId, $attemptId, $runnerId)),
        'identity_only_differs_in_placement' => array_diff_assoc($hostinger, $mac) === ['placement' => 'hostinger-shared'],
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

fwrite(STDERR, "Unknown runner scenario\n");
exit(2);
