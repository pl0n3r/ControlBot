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

$identity = [
    'version' => 1,
    'runner_id' => 'runner_001',
    'runtime' => 'php_cli',
    'runtime_version' => '1.0.0',
    'platform' => 'macos_local',
    'capabilities' => ['test_php', 'review_code'],
];
$signal = [
    'version' => 1,
    'runner_id' => 'runner_001',
    'seen_at' => 1_000,
    'mode' => 'busy',
    'capacity_total' => 3,
    'capacity_used' => 1,
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
        'duplicate' => array_replace($identity, ['capabilities' => ['test_php', 'test_php']]),
        'invalid' => array_replace($identity, ['runner_id' => '../runner']),
        'version' => array_replace($identity, ['runtime_version' => 'v1']),
        'empty' => array_replace($identity, ['capabilities' => []]),
        'secret' => array_replace($identity, ['capabilities' => ['test_php'], 'secret' => 'value']),
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
        'stale' => [1_061, $signal],
        'offline' => [1_181, $signal],
        'future' => [999, $signal],
        'missing' => [1_000, null],
        'paused' => [1_000, array_replace($signal, ['mode' => 'paused'])],
        'full' => [1_000, array_replace($signal, ['capacity_used' => 3])],
    ] as $name => [$now, $beat]) {
        $readings[$name] = RunnerGateway::health($identity, $beat, $now, 60, ['work_001']);
    }
    $bad = [
        'unknown_runner' => array_replace($signal, ['runner_id' => 'runner_002']),
        'extra' => $signal + ['token' => 'bad'],
        'negative' => array_replace($signal, ['capacity_used' => -1]),
        'over_capacity' => array_replace($signal, ['capacity_used' => 4]),
        'duplicate_sessions' => array_replace($signal, ['active_sessions' => ['session_001', 'session_001']]),
        'session_overflow' => array_replace($signal, ['capacity_used' => 0]),
    ];
    echo json_encode([
        'readings' => $readings,
        'rejects' => array_map(
            static fn(array $row): bool => rejected(
                static fn(): array => RunnerGateway::health($identity, $row, 1_000, 60)
            ), $bad
        ),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($scenario === 'portable') {
    $a = RunnerGateway::health($identity, $signal, 1_020, 60, ['work_001']);
    $b = RunnerGateway::health(
        array_replace($identity, ['platform' => 'hostinger_shared']),
        $signal, 1_020, 60, ['work_001']
    );
    echo json_encode(['same' => $a === $b, 'health' => $a], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
fwrite(STDERR, "Unknown runner scenario\n");
exit(2);
