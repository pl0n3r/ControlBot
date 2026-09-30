<?php
declare(strict_types=1);

require __DIR__.'/../src/SecurityEvent.php';
require __DIR__.'/../src/SecurityEventInbox.php';

use ControlBot\Security\SecurityEvent;
use ControlBot\Security\SecurityEventInbox;

function securityEvent(
    string $id,
    string $type,
    string $severity,
    string $confidence,
    int $at,
    ?array $device = null
): array {
    return SecurityEvent::normalize([
        'version' => 1,
        'provider' => 'github',
        'event_id' => $id,
        'observed_at' => $at,
        'occurred_at' => $at,
        'event_type' => $type,
        'severity' => $severity,
        'account_scope' => 'controlbot:account/owner',
        'confidence' => $confidence,
        'device' => $device,
        'actor' => null,
    ]);
}

$critical = securityEvent(
    'evt-critical',
    'new_device',
    'critical',
    'provider_reported',
    100,
    [
        'type' => 'mobile',
        'platform' => 'iOS',
        'browser' => 'Safari',
        'location' => ['country' => 'CO', 'region' => 'Risaralda', 'city' => 'Pereira'],
    ]
);
$warningOld = securityEvent('evt-warning-old', 'new_login', 'warning', 'confirmed', 80);
$warningNew = securityEvent('evt-warning-new', 'new_login', 'warning', 'correlated', 120);
$info = securityEvent('evt-info', 'passkey_added', 'info', 'unknown', 60);

$ack = SecurityEventInbox::acknowledge(
    $critical,
    130,
    'controlbot:actor/owner',
    'controlbot:context/security-inbox'
);
$ackReplay = SecurityEventInbox::acknowledge(
    $critical,
    130,
    'controlbot:actor/owner',
    'controlbot:context/security-inbox'
);

$inbox = SecurityEventInbox::project(
    [$info, $warningNew, $critical, $warningOld],
    [$critical['event_identity']],
    [$info['event_identity']]
);
$unread = SecurityEventInbox::project([$critical]);

echo json_encode([
    'critical' => $critical,
    'ack' => $ack,
    'ack_replay' => $ackReplay,
    'inbox' => $inbox,
    'unread' => $unread,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
