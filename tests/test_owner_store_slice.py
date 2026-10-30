"""#1379: regresión sintética independiente del almacén owner offline."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
require_once 'src/OwnerLocalAuthStore.php';
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;
$dir = getenv('CB_OWNER_TEST_DIR');
$key = base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$store = new OwnerLocalAuthStore($dir, 'pl0n3r', $key);
$now = 1800000000;
function ok1379(bool $condition, string $why): void {
    if (!$condition) throw new RuntimeException($why);
}
ok1379($store->status()['version'] === 0, 'initial state');
$secret = $store->beginEnrollment($now);
$enrollment = $store->confirmEnrollment(Totp::code($secret, $now + 1), $now + 1);
ok1379(count($enrollment['codes']) === 8, 'recovery count');
$version = $enrollment['version'];
ok1379($store->validVersion($version), 'current version');
$store->revokeAll($now + 2);
ok1379(!$store->validVersion($version), 'revoked version accepted');
try { $store->login($now + 3, $version); throw new RuntimeException('stale login'); }
catch (RuntimeException $e) { ok1379($e->getMessage() !== 'stale login', 'stale login accepted'); }
ok1379($store->verify($enrollment['codes'][0], $now + 4), 'recovery rejected');
ok1379(!$store->verify($enrollment['codes'][0], $now + 4), 'recovery replay');
$files = glob($dir . '/cb-auth-*.json');
ok1379(count($files) === 1, 'private state absent');
file_put_contents($files[0], '{corrupt-json');
try { $store->status(); throw new RuntimeException('corrupt state accepted'); }
catch (Throwable $e) { ok1379($e->getMessage() !== 'corrupt state accepted', 'fail closed'); }
echo 'OK';
'''


class OwnerStoreSliceTests(unittest.TestCase):
    def test_state_revocation_and_fail_closed(self):
        with tempfile.TemporaryDirectory(prefix="cb-owner-1379-") as directory:
            env = dict(os.environ, CB_OWNER_TEST_DIR=directory)
            run = subprocess.run(["php", "-r", PHP], cwd=ROOT, env=env,
                                 capture_output=True, text=True, timeout=30)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual(run.stdout, "OK")

    def test_scope_and_budget(self):
        source = (ROOT / "src/OwnerLocalAuthStore.php").read_text()
        self.assertIn("flock($handle, LOCK_EX)", source)
        self.assertIn("sodium_crypto_secretbox", source)
        self.assertLessEqual(len(source.splitlines()) + len(Path(__file__).read_text().splitlines()), 400)


if __name__ == "__main__":
    unittest.main()
