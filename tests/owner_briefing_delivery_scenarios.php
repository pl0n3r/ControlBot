<?php
declare(strict_types=1);

require __DIR__ . '/../src/OwnerBriefing.php';
require __DIR__ . '/../src/OwnerBriefingDelivery.php';

use ControlBot\Briefing\OwnerBriefingDelivery;
use InvalidArgumentException;

$snapshot = [
    'delivered' => [
        ['summary' => 'Observability quedó integrado.', 'evidence' => 'controlbot:issue/56'],
    ],
    'today' => [
        ['summary' => 'Cerrar entrega diaria del briefing.', 'evidence' => 'controlbot:issue/108'],
    ],
    'broken' => [],
    'decisions' => [],
    'costs' => [],
];

$tick = [
    'version' => 1,
    'source' => 'scheduler',
    'schedule_ref' => 'controlbot:schedule/owner-briefing-daily',
    'day' => '2026-09-30',
    'freshness' => 'fresh',
];

$policy = [
    'version' => 1,
    'policy_ref' => 'controlbot:policy/owner-briefing-v1',
    'enabled' => true,
    'channel' => 'email',
    'channel_available' => true,
];

$first = OwnerBriefingDelivery::plan($snapshot, $tick, $policy);
$receipt = [
    'version' => 1,
    'delivery_ref' => $first['delivery_ref'],
    'day' => $first['day'],
    'briefing_fingerprint' => $first['briefing_fingerprint'],
    'status' => 'delivered',
];

$retryPolicy = $policy;
$retryPolicy['channel'] = 'push';
$retry = OwnerBriefingDelivery::plan($snapshot, $tick, $retryPolicy, $receipt);

$changedSnapshot = $snapshot;
$changedSnapshot['today'][] = [
    'summary' => 'Revisar integración de guardrails.',
    'evidence' => 'controlbot:issue/111',
];
$changed = OwnerBriefingDelivery::plan($changedSnapshot, $tick, $policy, $receipt);

$unknownSnapshot = [
    'delivered' => [],
    'broken' => [],
    'decisions' => [],
];
$unknown = OwnerBriefingDelivery::plan($unknownSnapshot, $tick, $policy);

$staleTick = $tick;
$staleTick['freshness'] = 'stale';
$stale = OwnerBriefingDelivery::plan($snapshot, $staleTick, $policy);

$blockedPolicy = $policy;
$blockedPolicy['enabled'] = false;
$blocked = OwnerBriefingDelivery::plan($snapshot, $tick, $blockedPolicy);

$unavailablePolicy = $policy;
$unavailablePolicy['channel_available'] = false;
$unavailable = OwnerBriefingDelivery::plan($snapshot, $tick, $unavailablePolicy);

$invalidSnapshotRejected = false;
try {
    OwnerBriefingDelivery::plan(['today' => 'invented'], $tick, $policy);
} catch (InvalidArgumentException) {
    $invalidSnapshotRejected = true;
}

echo json_encode([
    'first' => $first,
    'retry' => $retry,
    'changed' => $changed,
    'unknown' => $unknown,
    'stale' => $stale,
    'blocked' => $blocked,
    'unavailable' => $unavailable,
    'invalid_snapshot_rejected' => $invalidSnapshotRejected,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
