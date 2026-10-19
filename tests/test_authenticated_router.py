"""Contrato OAuth+TOTP del router privado ControlBot #809 (preparacion)."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENTRY = ROOT / "src" / "ControlBotWebEntrypoint.php"
GUARD = ROOT / "src" / "Routing" / "OwnerAuthenticatedRouter.php"

PHP_SCENARIO = r"""
require 'src/Approvals.php';
require 'src/OwnerSession.php';
require 'src/ControlBotWebEntrypoint.php';
use ControlBot\\Security\\OwnerSessionService;
use ControlBot\\Security\\TokenVault;
use ControlBot\\Security\\Totp;
use ControlBot\\Web\\ControlBotWebEntrypoint;
$now = 1800000000;
$owner = 'pl0n3r';
$key = base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$service = new OwnerSessionService($owner, new TokenVault($key));
$session = [];
$service->establishTrustedOAuthSession($session, $owner, 'gho_fixture_only');
$secret = 'JBSWY3DPEHPK3PXP';
$server = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/github','REMOTE_USER'=>$owner];
$env = ['CONTROLBOT_OWNER_LOGIN'=>$owner];
$missing = ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,[],$service);
$notOtp = ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,$session,$service);
$service->reauthenticateTotp($session,Totp::code($secret,$now),$secret,$now);
$valid = ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,$session,$service);
$expired = ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now+301,$session,$service);
$wrongServer=$server;
$wrongServer['REMOTE_USER']='intruder';
$untrustedHeader=ControlBotWebEntrypoint::handleAuthenticated($wrongServer,$env,$now,$session,$service);
$fake=$session;
$fake['_github_token_sealed']='not-a-vault-token';
$forged=ControlBotWebEntrypoint::handleAuthenticated($server,$env,$now,$fake,$service);
echo json_encode([
  'missing'=>$missing,'not_otp'=>$notOtp,'valid'=>$valid,'expired'=>$expired,
  'untrusted_header'=>$untrustedHeader,'forged'=>$forged,
],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""

class RouterAutenticadoTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(ENTRY.is_file())
        self.assertTrue(GUARD.is_file())
        for path in (ENTRY, GUARD):
            checked = subprocess.run(["php", "-l", str(path)], cwd=ROOT, text=True, capture_output=True)
            self.assertEqual(checked.returncode, 0, checked.stdout + checked.stderr)
        completed = subprocess.run(
            ["php", "-r", PHP_SCENARIO], cwd=ROOT, text=True,
            capture_output=True, timeout=30
        )
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        payload = json.loads(completed.stdout)
        for key in ("missing", "not_otp", "expired", "forged"):
            with self.subTest(key=key):
                response = payload[key]
                self.assertIn(response["status"], (401, 403))
                self.assertEqual(response["headers"]["Cache-Control"], "no-store")
                self.assertNotIn("gho_fixture_only", response["body"])
        self.assertEqual(payload["valid"]["status"], 200)
        self.assertEqual(payload["untrusted_header"]["status"], 200)
        self.assertNotIn("gho_fixture_only", payload["valid"]["body"])

if __name__ == "__main__":
    unittest.main()
