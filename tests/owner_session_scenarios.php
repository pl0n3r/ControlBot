<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/OwnerSession.php';

use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

$scenario = $argv[1] ?? '';
$now = 1_800_000_000;
$key = base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$vault = new TokenVault($key);
$service = new OwnerSessionService('pl0n3r', $vault);
$secret = 'JBSWY3DPEHPK3PXP';
$token = 'gho_controlbot_super_secret';
$out = [];

if ($scenario === 'totp') {
    $session = [];
    $service->establishTrustedOAuthSession($session, 'pl0n3r', $token);
    $valid = Totp::code($secret, $now);
    $service->reauthenticateTotp($session, $valid, $secret, $now);
    $context = $service->contextFromRequest($session, ['_csrf' => $service->csrfToken($session)], $now + 10);

    $badSession = [];
    $service->establishTrustedOAuthSession($badSession, 'pl0n3r', $token);
    $invalid = $valid === '000000' ? '000001' : '000000';
    try {
        $service->reauthenticateTotp($badSession, $invalid, $secret, $now);
    } catch (Throwable $e) {
        $out['invalid_error'] = $e->getMessage();
    }

    $out += [
        'login' => $context->login,
        'reauthenticated_at' => $context->reauthenticatedAt,
        'invalid_has_reauth' => array_key_exists('_reauthenticated_at', $badSession),
    ];
} elseif ($scenario === 'token') {
    $session = [];
    $service->establishTrustedOAuthSession($session, 'pl0n3r', $token);
    $sealed = $session['_github_token_sealed'] ?? '';
    $out = [
        'sealed' => $sealed,
        'sealed_is_plaintext' => hash_equals($token, (string) $sealed),
        'sealed_contains_plaintext' => str_contains((string) $sealed, $token),
        'roundtrip_ok' => hash_equals($token, $service->githubToken($session)),
    ];
} elseif ($scenario === 'csrf') {
    $session = [];
    $service->establishTrustedOAuthSession($session, 'pl0n3r', $token);
    $service->reauthenticateTotp($session, Totp::code($secret, $now), $secret, $now);
    try {
        $service->contextFromRequest($session, ['_csrf' => 'attacker', 'owner' => true], $now);
    } catch (Throwable $e) {
        $out = ['error' => $e->getMessage()];
    }
} elseif ($scenario === 'elevate') {
    $session = ['_csrf' => str_repeat('x', 43)];
    $request = [
        '_csrf' => str_repeat('x', 43),
        'owner' => true,
        'login' => 'pl0n3r',
        'reauthenticated_at' => $now,
        'github_token' => $token,
    ];
    try {
        $service->contextFromRequest($session, $request, $now);
    } catch (Throwable $e) {
        $out = ['error' => $e->getMessage()];
    }
} elseif ($scenario === 'wrong-owner') {
    $session = [];
    try {
        $service->establishTrustedOAuthSession($session, 'otro-usuario', $token);
    } catch (Throwable $e) {
        $out = ['error' => $e->getMessage(), 'session_empty' => $session === []];
    }
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
