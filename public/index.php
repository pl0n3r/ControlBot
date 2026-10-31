<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Approvals.php';
require_once __DIR__ . '/../src/OwnerSession.php';
require_once __DIR__ . '/../src/SecretSource.php';
require_once __DIR__ . '/../src/ControlBotWebEntrypoint.php';
require_once __DIR__ . '/../src/OwnerLoginGate.php';

use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\SecretSource;
use ControlBot\Security\TokenVault;
use ControlBot\Web\ControlBotWebEntrypoint;

// Acceso con página de login (#1346): solo se activa cuando el servidor tiene el hash de la
// contraseña del dueño. Sin él, todo sigue cerrado por la ruta OAuth/TOTP de más abajo.
$passwordHash = getenv('CONTROLBOT_OWNER_PASSWORD_HASH');
if (is_string($passwordHash) && $passwordHash !== '') {
    $loginConfig = [
        'owner_login' => getenv('CONTROLBOT_OWNER_LOGIN') ?: null,
        'password_hash' => $passwordHash,
        'rate_limit_dir' => getenv('CONTROLBOT_OWNER_RATE_LIMIT_DIR') ?: null,
    ];
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    $localSession = [];
    $sessionId = $_COOKIE[session_name()] ?? null;
    $sessionOpen = false;
    // Nunca se crea una sesión anónima solo para mostrar una vista privada.
    if (is_string($sessionId) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) === 1) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Strict']);
        $sessionOpen = session_start();
        if ($sessionOpen) {
            $localSession = $_SESSION;
        }
    }
    $isBrowser = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
    $gate = OwnerLoginGate::requireOwner($_SERVER, $localSession, $loginConfig, time());
    if ($sessionOpen) {
        $_SESSION = $localSession; // conserva la marca de actividad
        session_write_close();
    }
    if ($gate !== null && $gate['status'] === 302 && !$isBrowser) {
        // API y clientes sin navegador: cerrado, sin redirección ni WWW-Authenticate.
        $gate = ['status' => 403, 'headers' => $gate['headers'], 'body' => "Forbidden.\n"];
        unset($gate['headers']['Location']);
    }
    if ($gate === null) {
        $gate = ControlBotWebEntrypoint::handleLocalOwner(
            $_SERVER,
            ['CONTROLBOT_OWNER_LOGIN' => $loginConfig['owner_login'],
             'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH' => getenv('CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH') ?: null],
            time(),
        );
    }
    http_response_code($gate['status']);
    foreach ($gate['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $gate['body'];
    exit;
}

// Compatibility contract: non-GitHub routes still reach FactoryOrchestratorWebEntrypoint::handle
// through the authenticated owner entrypoint; REMOTE_USER is never an authority.
$environment = [
    'CONTROLBOT_OWNER_LOGIN' => getenv('CONTROLBOT_OWNER_LOGIN') ?: null,
    'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH' => getenv('CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH') ?: null,
];

try {
    $owner = getenv('CONTROLBOT_OWNER_LOGIN');
    $vaultKey = SecretSource::read('CONTROLBOT_VAULT_KEY', dirname(__DIR__));
    if (!is_string($owner) || $owner === '' || !is_string($vaultKey) || $vaultKey === '') {
        throw new RuntimeException('Owner session configuration unavailable.');
    }

    $service = new OwnerSessionService($owner, new TokenVault($vaultKey));
    $session = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $session = $_SESSION ?? null;
    } elseif (session_status() === PHP_SESSION_NONE) {
        // Never create an anonymous session simply to render a private view.
        // Existing cookies are loaded with strict, HTTPS-only session settings.
        $sessionId = $_COOKIE[session_name()] ?? null;
        if (is_string($sessionId)
            && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) === 1
            && session_start([
                'read_and_close' => true,
                'use_strict_mode' => true,
                'use_only_cookies' => true,
                'cookie_httponly' => true,
                'cookie_secure' => true,
                'cookie_samesite' => 'Strict',
            ])) {
            $session = $_SESSION ?? null;
        }
    }

    $response = ControlBotWebEntrypoint::handleAuthenticated(
        $_SERVER,
        $environment,
        time(),
        is_array($session) ? $session : [],
        $service,
    );
} catch (Throwable) {
    // Missing server-side configuration is an unavailable service (503);
    // an invalid/absent owner session with valid configuration is forbidden (403).
    // Neither path exposes identities, secrets or snapshots.
    $configured = is_string($owner ?? null) && $owner !== ''
        && is_string($vaultKey ?? null) && $vaultKey !== '';
    $response = [
        'status' => $configured ? 403 : 503,
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ],
        'body' => $configured ? "Forbidden.\n" : "Service unavailable.\n",
    ];
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
