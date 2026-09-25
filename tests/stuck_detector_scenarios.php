<?php
declare(strict_types=1);

require __DIR__ . '/../src/StuckDetector.php';

use ControlBot\Runtime\StuckDetector;

$scenario = $argv[1] ?? '';
$base = [
    'session_id' => 'session-1',
    'work_item_id' => 'work-1',
    'now' => 1000,
    'state' => 'working',
    'state_started_at' => 900,
    'state_timeouts' => ['working' => 300, 'reviewing' => 120],
    'last_heartbeat_at' => 980,
    'heartbeat_timeout' => 60,
];

$cases = [
    'repeated-error' => ['same_error_count' => 2, 'same_error_fingerprint' => 'err-a'],
    'same-approach' => ['same_approach_failures' => 2, 'approach_fingerprint' => 'approach-a'],
    'commit-overflow' => ['commit_count' => 11, 'pr_closed' => false],
    'review-loop' => ['review_rounds' => 3, 'review_progress' => false],
    'stale-reservation' => ['reservation_active' => true, 'last_progress_at' => 700, 'reservation_timeout' => 120],
    'heartbeat' => ['last_heartbeat_at' => 800, 'heartbeat_timeout' => 60],
    'state-timeout' => ['state_started_at' => 500, 'state_timeouts' => ['working' => 300]],
    'rapid-retry' => ['rapid_retry_count' => 2, 'rapid_retry_window' => 30],
    'handoff-bounce' => ['handoff_bounce_count' => 3, 'handoff_state_changed' => false],
];

try {
    if ($scenario === 'all') {
        $out = [];
        foreach ($cases as $name => $extra) {
            $out[$name] = (new StuckDetector())->detect($base + $extra);
        }
        echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if ($scenario === 'same-approach-different') {
        $a = (new StuckDetector())->detect($base + $cases['same-approach']);
        $b = (new StuckDetector())->detect($base + ['same_approach_failures' => 2, 'approach_fingerprint' => 'approach-b']);
        echo json_encode(['a' => $a, 'b' => $b], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    if ($scenario === 'timeout-config') {
        $short = (new StuckDetector())->detect($base + ['state_started_at' => 700, 'state_timeouts' => ['working' => 200]]);
        $long = (new StuckDetector())->detect($base + ['state_started_at' => 700, 'state_timeouts' => ['working' => 400]]);
        echo json_encode(['short' => $short, 'long' => $long], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    if ($scenario === 'invalid') {
        (new StuckDetector())->detect($base + ['now' => '1000']);
        exit(0);
    }
    if (!isset($cases[$scenario])) {
        fwrite(STDERR, "scenario inválido\n");
        exit(2);
    }
    echo json_encode((new StuckDetector())->detect($base + $cases[$scenario]), JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
