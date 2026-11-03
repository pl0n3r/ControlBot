"""#1383: fail-closed MFA storage, owner throttling and revision CAS (offline)."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

ATOMIC = r'''
namespace ControlBot\Security {
    // Simulate a short write *after* writing one byte to a fresh temp file.
    function fwrite($handle, string $data): int|false {
        if (getenv('CB1383_SHORT_WRITE') === '1') {
            \fwrite($handle, substr($data, 0, 1));
            return 0;
        }
        return \fwrite($handle, $data);
    }
}
namespace {
    require_once 'src/OwnerLocalAuthStore.php';
    use ControlBot\Security\OwnerLocalAuthStore;
    use ControlBot\Security\Totp;
    function ok1383(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    }
    $now = 1800000000;
    $store = new OwnerLocalAuthStore(getenv('CB1383_DIR'), 'pl0n3r',
        base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $secret = $store->beginEnrollment($now);
    $store->confirmEnrollment(Totp::code($secret, $now + 1), $now + 1,
        $store->status()['version']);
    $version = $store->status()['version'];
    $files = glob(getenv('CB1383_DIR') . '/cb-auth-*.json');
    ok1383(count($files) === 1, 'state missing');
    $before = file_get_contents($files[0]);
    putenv('CB1383_SHORT_WRITE=1');
    $failed = false;
    try { $store->revokeAll($now + 2); } catch (Throwable $e) { $failed = true; }
    putenv('CB1383_SHORT_WRITE=0');
    ok1383($failed, 'short write must fail');
    ok1383(file_get_contents($files[0]) === $before, 'previous state lost');
    ok1383($store->validVersion($version), 'failed revoke mutated version');
    ok1383($store->status()['enabled'], 'MFA silently disabled');
    ok1383(!$store->verify('', $now + 3), 'password-only bypass after write failure');
    echo 'OK';
}
'''

THROTTLE = r'''
require_once 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
function ok1383(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$now = 1800000000;
$config = [
    'owner_login' => 'pl0n3r',
    'password_hash' => password_hash('fixture-only', PASSWORD_ARGON2ID,
        ['memory_cost'=>4096, 'time_cost'=>1, 'threads'=>1]),
    'rate_limit_dir' => getenv('CB1383_DIR'),
    'totp_key' => base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
];
$get = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.31'];
$post = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.31'];
$session = [];
OwnerLoginGate::handle($get, [], $session, $config, $now);
$csrf = $session['_cb_login_csrf'];
$r = OwnerLoginGate::handle($post,
    ['csrf'=>$csrf, 'username'=>'pl0n3r', 'password'=>'fixture-only'],
    $session, $config, $now);
ok1383($r['status'] === 303, 'fixture login failed');
for ($i = 0; $i < 3; $i++) {
    $r = OwnerLoginGate::handle($post,
        ['action'=>'revoke_all','csrf'=>$session['_cb_login_csrf'],
         'password'=>'bad-password'], $session, $config, $now + 1);
    ok1383($r['status'] === 403, 'wrong management password not counted');
}
$r = OwnerLoginGate::handle($post,
    ['action'=>'revoke_all','csrf'=>$session['_cb_login_csrf'],
     'password'=>'fixture-only'], $session, $config, $now + 1);
ok1383($r['status'] === 429, 'correct password bypassed management throttle');
$r = OwnerLoginGate::handle($post,
    ['action'=>'totp_begin','csrf'=>$session['_cb_login_csrf'],
     'password'=>'fixture-only'], $session, $config, $now + 1);
ok1383($r['status'] === 429, 'MFA setup bypassed management throttle');
ok1383(OwnerLoginGate::isAuthenticated($session, $now + 2), 'owner session mutated');
echo 'OK';
'''

REVISION = r'''
require_once 'src/OwnerLocalAuthStore.php';
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;
function ok1383(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$now = 1800000000;
$store = new OwnerLocalAuthStore(getenv('CB1383_DIR'), 'pl0n3r',
    base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$secret = $store->beginEnrollment($now);
$stale = $store->status()['version'];
$store->revokeAll($now + 1);
$failed = false;
try { $store->confirmEnrollment(Totp::code($secret, $now + 2), $now + 2, $stale); }
catch (RuntimeException $e) { $failed = true; }
ok1383($failed && !$store->validVersion($stale), 'revoked enrollment resurrected');
$enrolled = $store->confirmEnrollment(Totp::code($secret, $now + 2), $now + 2,
    $store->status()['version']);
$version = $enrolled['version'];
$store->revokeAll($now + 3);
$failed = false;
try { $store->setRequired(true, Totp::code($secret, $now + 32),
    $now + 32, $version); } catch (RuntimeException $e) { $failed = true; }
ok1383($failed, 'revoked policy write accepted');
$failed = false;
try { $store->disable(Totp::code($secret, $now + 62), $now + 62, $version); }
catch (RuntimeException $e) { $failed = true; }
ok1383($failed, 'revoked disable accepted');
ok1383($store->status()['enabled'], 'revoked disable altered MFA');
ok1383(!$store->status()['required'], 'revoked policy altered MFA');
echo 'OK';
'''


class OwnerSecurityHardeningTests(unittest.TestCase):
    def run_php(self, code):
        with tempfile.TemporaryDirectory(prefix='cb-1383-') as directory:
            env = dict(os.environ, CB1383_DIR=directory)
            p = subprocess.run(['php', '-r', code], cwd=ROOT, env=env,
                               capture_output=True, text=True, timeout=40)
            self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
            self.assertEqual(p.stdout, 'OK')

    def test_atomic_state_failure(self):
        self.run_php(ATOMIC)

    def test_management_password_throttle(self):
        self.run_php(THROTTLE)

    def test_stale_mfa_revision(self):
        self.run_php(REVISION)


if __name__ == '__main__':
    unittest.main()
