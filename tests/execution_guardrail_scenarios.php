<?php
declare(strict_types=1);
require __DIR__ . '/../src/ExecutionGuardrail.php';
use ControlBot\Guardrail\ExecutionGuardrail;
$name = $argv[1] ?? '';
$now = 2000;
function thresholds(array $overrides = []): array {
    return array_replace([
        'issue_stale_seconds' => 600,
        'reservation_stale_seconds' => 600,
        'heartbeat_stale_seconds' => 120,
        'rapid_retry_seconds' => 30,
        'state_timeouts' => ['working' => 300, 'reviewing' => 600],
    ], $overrides);
}
function base_snapshot(array $overrides = []): array {
    return array_replace([
        'issue_ref' => 'pl0n3r/ControlBot#109',
        'pr_ref' => 'pl0n3r/ControlBot#110',
        'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'last_result' => 'último resultado conocido',
        'next_approach' => 'probar enfoque distinto',
        'commits' => 2,
        'review_rounds' => 1,
        'progress_version' => 2,
        'previous_progress_version' => 1,
        'issue_updated_at' => 1900,
        'reservation_updated_at' => 1900,
        'heartbeat_at' => 1950,
        'state' => 'working',
        'state_started_at' => 1900,
        'attempts' => [],
        'retry_events' => [],
        'handoffs' => [],
        'non_preemptible' => false,
    ], $overrides);
}
if ($name === 'healthy') {
    $out = ExecutionGuardrail::analyze(base_snapshot(), thresholds(), $now);
} elseif ($name === 'all-patterns') {
    $out = ExecutionGuardrail::analyze(base_snapshot([
        'commits' => 11,
        'review_rounds' => 3,
        'progress_version' => 4,
        'previous_progress_version' => 4,
        'issue_updated_at' => 1000,
        'reservation_updated_at' => 1000,
        'heartbeat_at' => 1000,
        'state_started_at' => 1000,
        'attempts' => [
            ['approach'=>'retry-build','result'=>'failed','error'=>'same failure','evidence'=>'same evidence','at'=>1800],
            ['approach'=>'retry-build','result'=>'failed','error'=>'same failure','evidence'=>'same evidence','at'=>1850],
        ],
        'retry_events' => [['key'=>'order-1','at'=>1800],['key'=>'order-1','at'=>1810]],
        'handoffs' => [
            ['agent'=>'agent-a','state'=>'working','at'=>1700],
            ['agent'=>'agent-b','state'=>'working','at'=>1750],
            ['agent'=>'agent-a','state'=>'working','at'=>1800],
        ],
    ]), thresholds(), $now);
} elseif ($name === 'thresholds') {
    $snapshot = base_snapshot(['heartbeat_at'=>1800,'state_started_at'=>1700]);
    $out = [
        'strict' => ExecutionGuardrail::analyze($snapshot, thresholds(), $now),
        'relaxed' => ExecutionGuardrail::analyze($snapshot, thresholds([
            'heartbeat_stale_seconds'=>300,
            'state_timeouts'=>['working'=>400,'reviewing'=>600],
        ]), $now),
    ];
} elseif ($name === 'fingerprint') {
    $snapshot = base_snapshot(['commits'=>11]);
    $out = [
        ExecutionGuardrail::analyze($snapshot, thresholds(), $now),
        ExecutionGuardrail::analyze($snapshot, thresholds(), $now),
    ];
} elseif ($name === 'non-preemptible') {
    $out = ExecutionGuardrail::analyze(base_snapshot([
        'heartbeat_at'=>1000,
        'non_preemptible'=>true,
    ]), thresholds(), $now);
} elseif ($name === 'secret-handoff') {
    try {
        ExecutionGuardrail::analyze(base_snapshot(['last_result'=>'ghp_123456789012345678901234567890']), thresholds(), $now);
        $out = ['blocked'=>false];
    } catch (InvalidArgumentException $e) {
        $out = ['blocked'=>true];
    }
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
