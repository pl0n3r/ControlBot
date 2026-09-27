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

fwrite(STDERR, "Unknown runner scenario\n");
exit(2);
