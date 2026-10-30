"""Evidencia offline de seguridad ya existente para ControlBot#1366.

El baseline no debe confundirse con la entrega de revocación centralizada, MFA
en el formulario local ni códigos de recuperación: requieren rutas de runtime
no reclamadas por la reserva V3 de este issue.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP_CASE = r"""
require_once 'src/Approvals.php';
require_once 'src/OwnerSession.php';
require_once 'src/OwnerLoginGate.php';

use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

function check1366(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$now = 1800000000;
$hash = password_hash('synthetic-password-only', PASSWORD_ARGON2ID,
    ['memory_cost' => 4096, 'time_cost' => 1, 'threads' => 1]);
$config = [
    'owner_login' => 'pl0n3r',
    'password_hash' => $hash,
    'rate_limit_dir' => getenv('CONTROLBOT_TEST_LIMIT_DIR'),
];
$get = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login',
        'REMOTE_ADDR' => '198.51.100.23'];
$post = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login',
         'REMOTE_ADDR' => '198.51.100.23'];
$privateRoute = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/overview',
                 'REMOTE_USER' => 'pl0n3r'];
$session = [];
$rotations = 0;
$regenerate = static function () use (&$rotations): bool {
    $rotations++;
    return true;
};
$unauthenticated = OwnerLoginGate::requireOwner(
    $privateRoute, $session, $config, $now);
check1366($unauthenticated['status'] === 302, 'Basic/REMOTE_USER must not bypass login');
$form = OwnerLoginGate::handle($get, [], $session, $config, $now);
check1366($form['status'] === 200, 'GET login must render');
$csrf = $session['_cb_login_csrf'];
$invalidCsrf = OwnerLoginGate::handle(
    $post, ['csrf' => 'invalid', 'username' => 'pl0n3r',
            'password' => 'synthetic-password-only'],
    $session, $config, $now, $regenerate);
check1366($invalidCsrf['status'] === 403, 'CSRF must fail closed');
$login = OwnerLoginGate::handle(
    $post, ['csrf' => $csrf, 'username' => 'pl0n3r',
            'password' => 'synthetic-password-only'],
    $session, $config, $now, $regenerate);
check1366($login['status'] === 303 && $rotations === 1, 'Login must rotate session');
check1366(OwnerLoginGate::isAuthenticated($session, $now), 'Login context missing');
check1366(OwnerLoginGate::requireOwner(
    $privateRoute, $session, $config, $now + 1) === null, 'Private route denied');
check1366(OwnerLoginGate::requireOwner(
    $privateRoute, $session, $config, $now + 901)['status'] === 302, 'Idle timeout missing');
check1366(!OwnerLoginGate::isAuthenticated($session, $now + 901), 'Expired session usable');

$loginForm = OwnerLoginGate::handle($get, [], $session, $config, $now + 902);
$login = OwnerLoginGate::handle(
    $post, ['csrf' => $session['_cb_login_csrf'], 'username' => 'pl0n3r',
            'password' => 'synthetic-password-only'],
    $session, $config, $now + 902, $regenerate);
check1366($login['status'] === 303, 'Relogin after expiry failed');
$logout = OwnerLoginGate::handle(
    $post, ['action' => 'logout', 'csrf' => $session['_cb_login_csrf']],
    $session, $config, $now + 903, $regenerate);
check1366($logout['status'] === 303 && $rotations === 3,
    'Logout must rotate session');
check1366(!OwnerLoginGate::isAuthenticated($session, $now + 903),
    'Logout did not revoke local state');

$fixtureSecret = 'JBSWY3DPEHPK3PXP';
$validCode = Totp::code($fixtureSecret, $now);
check1366(Totp::verify($fixtureSecret, $validCode, $now),
    'Valid TOTP rejected');
check1366(!Totp::verify($fixtureSecret, $validCode, $now + 90),
    'Expired TOTP accepted');
check1366(!Totp::verify($fixtureSecret, 'not-a-code', $now),
    'Malformed TOTP accepted');

$key = base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$service = new OwnerSessionService('pl0n3r', new TokenVault($key));
$oauthSession = [];
$service->establishTrustedOAuthSession(
    $oauthSession, 'pl0n3r', 'synthetic-github-token');
$service->reauthenticateTotp($oauthSession, $validCode, $fixtureSecret, $now);
check1366($oauthSession['_reauthenticated_at'] === $now,
    'Trusted OAuth TOTP reauth did not record timestamp');
$badOauth = [];
$service->establishTrustedOAuthSession(
    $badOauth, 'pl0n3r', 'synthetic-github-token');
$invalidCode = $validCode === '000000' ? '000001' : '000000';
try {
    $service->reauthenticateTotp(
        $badOauth, $invalidCode, $fixtureSecret, $now);
    throw new RuntimeException('Bad TOTP code was accepted');
} catch (RuntimeException $e) {
    check1366($e->getMessage() === 'Código TOTP inválido.',
        'Unexpected MFA error: ' . $e->getMessage());
}
check1366(!isset($badOauth['_reauthenticated_at']),
    'Rejected TOTP elevated OAuth session');
echo json_encode(['baseline' => true, 'synthetic' => true],
    JSON_THROW_ON_ERROR);
"""


class OwnerLoginSessionTests(unittest.TestCase):
    def test_owner_login_session_contract(self):
        """Caducidad, logout, CSRF, acceso limitado y OTP con tiempo sintético."""
        with tempfile.TemporaryDirectory(prefix="controlbot-owner1366-") as private:
            env = {**os.environ, "CONTROLBOT_TEST_LIMIT_DIR": private}
            run = subprocess.run(
                ["php", "-r", PHP_CASE],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=30,
                check=False,
            )
            self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
            self.assertEqual(
                json.loads(run.stdout),
                {"baseline": True, "synthetic": True},
            )

    def test_unimplemented_controls_are_not_misrepresented(self):
        doc = (ROOT / "docs/owner-login.md").read_text(encoding="utf-8")
        for expected in (
            "Estado: preparación", "No acreditado",
            "revocación centralizada", "códigos de recuperación",
            "reserva V3", "D-059", "construccion",
        ):
            self.assertIn(expected, doc)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", doc)


if __name__ == "__main__":
    unittest.main()
