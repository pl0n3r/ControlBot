<?php
declare(strict_types=1);

// Owner-only HTTP bootstrap for /inbox and /decisiones/{id}.
// This entrypoint never consumes REMOTE_USER, request credentials or local password-only sessions.
require_once __DIR__ . '/../src/Approvals.php';
require_once __DIR__ . '/../src/OwnerSession.php';
require_once __DIR__ . '/../src/SecretSource.php';
require_once __DIR__ . '/../src/Routing/Routes/InboxRoutes.php';

use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\SecretSource;
use ControlBot\Security\TokenVault;
use ControlBot\Routing\Routes\InboxRoutes;

try {
    $root = dirname(__DIR__);
    $login = SecretSource::ownerLogin($root);
    $secret = SecretSource::read('CONTROLBOT_VAULT_KEY', $root);
    if (!is_string($login) || $login === '' || !is_string($secret) || $secret === '')
        throw new RuntimeException('Owner bootstrap unavailable.');
    $auth = new OwnerSessionService($login, new TokenVault($secret));
    $session = [];
    // Accept only an existing HTTPS session. Never start an anonymous owner session.
    $cookie = $_COOKIE[session_name()] ?? null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $session = is_array($_SESSION ?? null) ? $_SESSION : [];
    } elseif (is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $cookie) === 1
        && session_start(['read_and_close' => true, 'use_strict_mode' => true,
            'use_only_cookies' => true, 'cookie_secure' => true,
            'cookie_httponly' => true, 'cookie_samesite' => 'Strict'])) {
        $session = is_array($_SESSION ?? null) ? $_SESSION : [];
    }
    $response = InboxRoutes::page($auth, $session, $_SERVER, time(), null,
        is_array($_POST ?? null) ? $_POST : []);
    if ($response === null) throw new RuntimeException('Page unavailable.');
} catch (Throwable) {
    $response = ['status' => 503, 'headers' => [
        'Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
    ], 'body' => "Service unavailable.\n"];
}
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
echo $response['body'];
