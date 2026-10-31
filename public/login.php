<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/OwnerLoginGate.php';
require_once __DIR__ . '/../src/SecretSource.php';

use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\SecretSource;

// Hoja offline #916: no habilita el rewrite /login ni desactiva Basic Auth.
// La activación del endpoint requiere revisión D-059 y cableado posterior.
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'Service unavailable.';
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true,
    'httponly' => true, 'samesite' => 'Strict',
]);
if (!session_start()) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'Service unavailable.';
    exit;
}

$config = [
    'owner_login' => getenv('CONTROLBOT_OWNER_LOGIN') ?: null,
    'password_hash' => SecretSource::read('CONTROLBOT_OWNER_PASSWORD_HASH', dirname(__DIR__)),
    'rate_limit_dir' => getenv('CONTROLBOT_OWNER_RATE_LIMIT_DIR') ?: null,
];
$response = OwnerLoginGate::handle(
    $_SERVER,
    $_POST,
    $_SESSION,
    $config,
    time(),
    static fn (): bool => session_regenerate_id(true),
);
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
