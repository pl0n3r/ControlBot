<?php
declare(strict_types=1);
require __DIR__.'/../src/SecurityEvent.php';
require __DIR__.'/../src/SecurityAlertChannel.php';

use ControlBot\Security\SecurityAlertChannel;
use ControlBot\Security\SecurityEvent;

function securityAlertEvent(string $severity='critical',string $confidence='provider_reported',int $observedAt=2000): array{
    return SecurityEvent::normalize([
        'version'=>1,'provider'=>'github','event_id'=>'evt-001','observed_at'=>$observedAt,'occurred_at'=>1900,
        'event_type'=>'new_device','severity'=>$severity,'account_scope'=>'controlbot:account/owner-primary',
        'confidence'=>$confidence,
        'device'=>['type'=>'desktop','platform'=>'macOS','browser'=>'Chrome','location'=>['country'=>'CO','region'=>'Risaralda','city'=>'Pereira']],
        'actor'=>null,
    ]);
}
function securityAlertChannels(string $primary,string $independent): array{
    return ['primary_state'=>$primary,'independent_state'=>$independent];
}
function securityAlertPolicy(bool $noncritical=false,int $version=1): array{
    return ['version'=>$version,'escalate_noncritical'=>$noncritical];
}

$criticalIndependent=SecurityAlertChannel::project(securityAlertEvent(),securityAlertChannels('degraded','healthy'),securityAlertPolicy());
$warningPrimary=SecurityAlertChannel::project(securityAlertEvent('warning'),securityAlertChannels('healthy','unknown'),securityAlertPolicy());
$warningNoAlert=SecurityAlertChannel::project(securityAlertEvent('warning'),securityAlertChannels('degraded','healthy'),securityAlertPolicy());
$warningEscalated=SecurityAlertChannel::project(securityAlertEvent('warning'),securityAlertChannels('degraded','healthy'),securityAlertPolicy(true));
$unknownIndependent=SecurityAlertChannel::project(securityAlertEvent(),securityAlertChannels('unavailable','unknown'),securityAlertPolicy());
$unknownPrimary=SecurityAlertChannel::project(securityAlertEvent(),securityAlertChannels('unknown','healthy'),securityAlertPolicy());
$idempotentA=SecurityAlertChannel::project(securityAlertEvent(),securityAlertChannels('down','healthy'),securityAlertPolicy(false,3));
$idempotentB=SecurityAlertChannel::project(securityAlertEvent('critical','provider_reported',2100),securityAlertChannels('down','healthy'),securityAlertPolicy(false,3));
$unknownConfidence=SecurityAlertChannel::project(securityAlertEvent('critical','unknown'),securityAlertChannels('stale','healthy'),securityAlertPolicy());
$primaryHealthy=SecurityAlertChannel::project(securityAlertEvent(),securityAlertChannels('healthy','unknown'),securityAlertPolicy());

echo json_encode([
    'critical_independent'=>$criticalIndependent,
    'warning_primary'=>$warningPrimary,
    'warning_no_alert'=>$warningNoAlert,
    'warning_escalated'=>$warningEscalated,
    'unknown_independent'=>$unknownIndependent,
    'unknown_primary'=>$unknownPrimary,
    'idempotent_a'=>$idempotentA,
    'idempotent_b'=>$idempotentB,
    'unknown_confidence'=>$unknownConfidence,
    'primary_healthy'=>$primaryHealthy,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
