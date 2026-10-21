<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Approvals.php';
require_once dirname(__DIR__) . '/src/OwnerSession.php';
require_once dirname(__DIR__) . '/src/ControlBotWebEntrypoint.php';
require_once dirname(__DIR__) . '/src/RecoveryCenterWebEntrypoint.php';

use ControlBot\Web\ControlBotWebEntrypoint;
use ControlBot\Web\RecoveryCenterWebEntrypoint;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

function request(string $path, string $method = 'GET', bool $verified = true): array
{
    $now = 1000;
    $service = new OwnerSessionService('owner', new TokenVault(base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
    $session = [];
    if ($verified) {
        $service->establishTrustedOAuthSession($session, 'owner', 'gho_offline_recovery_fixture');
        $secret = 'JBSWY3DPEHPK3PXP';
        $service->reauthenticateTotp($session, Totp::code($secret, $now), $secret, $now);
    }
    return ControlBotWebEntrypoint::handleAuthenticated(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_USER' => 'owner'],
        ['CONTROLBOT_OWNER_LOGIN' => 'owner'],
        $now,
        $session,
        $service,
        null,
        sys_get_temp_dir() . '/cb-recovery-missing-orchestrator.json',
        sys_get_temp_dir() . '/cb-recovery-cache.json',
    );
}

$scenario = $argv[1] ?? '';
if ($scenario === 'owner') {
    $value = request('/security/resilience');
} elseif ($scenario === 'guards') {
    $value = [
        'anonymous' => request('/security/resilience', verified: false),
        'post' => request('/security/resilience', method: 'POST'),
        'forged_user' => ControlBotWebEntrypoint::handle(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/security/resilience', 'REMOTE_USER' => 'owner'],
            ['CONTROLBOT_OWNER_LOGIN' => 'owner'], 1000,
            null,
            sys_get_temp_dir() . '/cb-recovery-missing-orchestrator.json',
            sys_get_temp_dir() . '/cb-recovery-cache.json',
        ),
    ];
} elseif ($scenario === 'invalid-profile') {
    $path = tempnam(sys_get_temp_dir(), 'cb-recovery-invalid-');
    if (!is_string($path)) throw new RuntimeException('temporary fixture unavailable');
    try {
        file_put_contents($path, '{"version":1,"secret":"<script>bad</script>"}');
        $value = [
            'invalid' => RecoveryCenterWebEntrypoint::handle(['REQUEST_METHOD'=>'GET'], $path),
            'missing' => RecoveryCenterWebEntrypoint::handle(['REQUEST_METHOD'=>'GET'], $path . '-missing'),
        ];
    } finally {
        @unlink($path);
    }
} elseif ($scenario === 'delegated') {
    $value = request('/api/orchestrator-live');
} else {
    fwrite(STDERR, "unknown recovery scenario\n");
    exit(2);
}
echo json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES), PHP_EOL;
