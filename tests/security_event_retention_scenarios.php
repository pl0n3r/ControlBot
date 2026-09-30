<?php
declare(strict_types=1);

require __DIR__.'/../src/SecurityEvent.php';
require __DIR__.'/../src/SecurityEventInbox.php';
require __DIR__.'/../src/SecurityEventRetention.php';

use ControlBot\Security\SecurityEvent;
use ControlBot\Security\SecurityEventInbox;
use ControlBot\Security\SecurityEventRetention;

function evt(string $id, string $type, string $severity, int $at, ?array $device = null, ?array $actor = null): array {
    return SecurityEvent::normalize([
        'version' => 1,
        'provider' => 'github',
        'event_id' => $id,
        'observed_at' => $at,
        'occurred_at' => $at,
        'event_type' => $type,
        'severity' => $severity,
        'account_scope' => 'controlbot:account/owner',
        'confidence' => 'confirmed',
        'device' => $device,
        'actor' => $actor,
    ]);
}

$device = [
    'type' => 'mobile',
    'platform' => 'iOS',
    'browser' => 'Safari',
    'location' => ['country' => 'CO', 'region' => 'Risaralda', 'city' => 'Pereira'],
];
$original = evt('evt-retention', 'new_device', 'warning', 100, $device);
$ack = SecurityEventInbox::acknowledge($original, 120, 'controlbot:actor/owner', 'controlbot:context/security-inbox');
$other = evt('evt-other', 'new_login', 'warning', 100);
$wrongAck = SecurityEventInbox::acknowledge($other, 120, 'controlbot:actor/owner', 'controlbot:context/security-inbox');
$actorRef = 'controlbot:actor/owner';
$contextRef = 'controlbot:context/security-inbox';
$preEventAckId = 'ack-'.substr(hash('sha256', $original['event_identity'].'|'.$actorRef.'|'.$contextRef), 0, 40);
$preEventAck = SecurityEvent::normalize([
    'version' => 1,
    'provider' => 'controlbot',
    'event_id' => $preEventAckId,
    'observed_at' => 90,
    'occurred_at' => 90,
    'event_type' => 'security_alert_acknowledged',
    'severity' => 'warning',
    'account_scope' => 'controlbot:account/owner',
    'confidence' => 'confirmed',
    'device' => null,
    'actor' => ['actor_ref' => $actorRef, 'context_ref' => $contextRef],
]);
$policy1 = ['version' => 1, 'retention_seconds' => ['info' => 50, 'warning' => 50, 'critical' => 100], 'event_type_retention_seconds' => ['new_device' => ['info' => 40, 'warning' => 50, 'critical' => 90], 'new_login' => ['info' => 80, 'warning' => 150, 'critical' => 200]]];
$policy1Changed = ['version' => 1, 'retention_seconds' => ['info' => 50, 'warning' => 60, 'critical' => 100], 'event_type_retention_seconds' => $policy1['event_type_retention_seconds']];
$policy2 = ['version' => 2, 'retention_seconds' => ['info' => 50, 'warning' => 75, 'critical' => 100], 'event_type_retention_seconds' => $policy1['event_type_retention_seconds']];
$artifacts = [
    ['kind' => 'reference', 'value' => 'controlbot:evidence/security-evt-retention'],
    ['kind' => 'raw_payload', 'value' => '{"token":"github_pat_NOT_RETAINED"}'],
    ['kind' => 'secret', 'value' => 'password=never-retain'],
    ['kind' => 'ip', 'value' => '203.0.113.4'],
];

$expired = SecurityEventRetention::plan($original, $policy1, 200, false, $ack, $artifacts);
$replay = SecurityEventRetention::plan($original, $policy1, 200, false, $ack, $artifacts);
$held = SecurityEventRetention::plan($original, $policy1, 200, true, $ack, $artifacts);
$wrongAckRejected = false; try { SecurityEventRetention::plan($original, $policy1, 200, false, $wrongAck); } catch (InvalidArgumentException) { $wrongAckRejected = true; }
$preEventAckRejected = false; try { SecurityEventRetention::plan($original, $policy1, 200, false, $preEventAck); } catch (InvalidArgumentException) { $preEventAckRejected = true; }
$login = evt('evt-login', 'new_login', 'warning', 100);
$loginPlan = SecurityEventRetention::plan($login, $policy1, 200);
$sameVersionChanged = SecurityEventRetention::plan($original, $policy1Changed, 200, false, $ack);
$changed = SecurityEventRetention::plan($original, $policy2, 200, false, $ack);

echo json_encode([
    'expired' => $expired,
    'replay' => $replay,
    'held' => $held,
    'same_version_changed' => $sameVersionChanged,
    'changed' => $changed,
    'wrong_ack_rejected' => $wrongAckRejected,
    'pre_event_ack_rejected' => $preEventAckRejected,
    'login_plan' => $loginPlan,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
