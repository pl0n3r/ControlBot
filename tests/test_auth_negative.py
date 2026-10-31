#!/usr/bin/env python3
"""ControlBot #1364 — negative owner-auth tests using disposable synthetic state.

The PHP application functions run locally; this suite never contacts Hostinger,
GitHub, external accounts or production endpoints.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP_BASE = r"""
require 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;
$now = 1800000000;
$owner = 'fixture-owner';
$password = 'fixture-password-only';
$key = base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$cfg = [
    'owner_login' => $owner,
    'password_hash' => password_hash($password, PASSWORD_ARGON2ID,
        ['memory_cost'=>4096, 'time_cost'=>1, 'threads'=>1]),
    'rate_limit_dir' => getenv('CONTROLBOT_NEGATIVE_TEST_DIR'),
    'totp_key' => $key,
];
$get = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login',
    'REMOTE_ADDR'=>'198.51.100.8'];
$post = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login',
    'REMOTE_ADDR'=>'198.51.100.8'];
$protected = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview',
    'REMOTE_ADDR'=>'198.51.100.8'];
$regen = 0;
$regenerate = static function () use (&$regen): bool { $regen++; return true; };
"""

LOGIN_SCENARIO = r"""
$s = [];
$anonymous = OwnerLoginGate::requireOwner($protected, $s, $cfg, $now);
$form = OwnerLoginGate::handle($get, [], $s, $cfg, $now);
$csrf = $s['_cb_login_csrf'];
$missing = OwnerLoginGate::handle($post,
    ['username'=>$owner,'password'=>$password], $s,$cfg,$now,$regenerate);
$forged = OwnerLoginGate::handle($post,
    ['csrf'=>'attacker','username'=>$owner,'password'=>$password],
    $s,$cfg,$now,$regenerate);
$wrong = OwnerLoginGate::handle($post,
    ['csrf'=>$csrf,'username'=>$owner,'password'=>'invalid-fixture'],
    $s,$cfg,$now,$regenerate);
$authenticatedAfterDenials = OwnerLoginGate::isAuthenticated($s, $now);
$valid = OwnerLoginGate::handle($post,
    ['csrf'=>$csrf,'username'=>$owner,'password'=>$password],
    $s,$cfg,$now,$regenerate);
$allowed = OwnerLoginGate::requireOwner($protected,$s,$cfg,$now+1);
$encoded = OwnerLoginGate::requireOwner(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/%2e%2e/private'], $s,$cfg,$now+1);
$traversal = OwnerLoginGate::requireOwner(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/../private'], $s,$cfg,$now+1);
$asset_trick = OwnerLoginGate::requireOwner(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/assets//secret.js'], $s,$cfg,$now+1);
$expiredSession = $s;
$expired = OwnerLoginGate::requireOwner($protected,$expiredSession,$cfg,$now+901);
$wrongLogout = OwnerLoginGate::handle($post,
    ['action'=>'logout','csrf'=>'invalid'], $s,$cfg,$now+2,$regenerate);
$beforeRevoke = $s;
$revoke = OwnerLoginGate::handle($post,
    ['action'=>'revoke_all','csrf'=>$s['_cb_login_csrf'],'password'=>$password],
    $s,$cfg,$now+3,$regenerate);
$stale = OwnerLoginGate::requireOwner($protected,$beforeRevoke,$cfg,$now+4);
$store = new OwnerLocalAuthStore($cfg['rate_limit_dir'],$owner,$key);
echo json_encode([
 'anonymous'=>$anonymous, 'form'=>$form['status'],
 'missing'=>$missing, 'forged'=>$forged,'wrong'=>$wrong,
 'denied_session'=>$authenticatedAfterDenials,
 'valid'=>$valid,'allowed'=>$allowed,
 'encoded'=>$encoded,'traversal'=>$traversal,'asset_trick'=>$asset_trick,
 'expired'=>$expired,'expired_cleared'=>!isset($expiredSession['_cb_owner_web']),
 'wrong_logout'=>$wrongLogout,'revoke'=>$revoke,'stale'=>$stale,
 'stale_cleared'=>!isset($beforeRevoke['_cb_owner_web']),
 'revoked_authenticated'=>OwnerLoginGate::isAuthenticated($s,$now+4),
 'audit_events'=>array_column($store->auditTrail(),'event'),
 'regenerations'=>$regen,
], JSON_THROW_ON_ERROR);
"""

TOTP_SCENARIO = r"""
$store = new OwnerLocalAuthStore($cfg['rate_limit_dir'], $owner, $key);
$secret = $store->beginEnrollment($now);
$enrollment = $store->confirmEnrollment(Totp::code($secret,$now),$now,0);
$store->setRequired(true,Totp::code($secret,$now+30),$now+30,
    $enrollment['version']);
$s = [];
OwnerLoginGate::handle($get, [], $s,$cfg,$now+60);
$csrf = $s['_cb_login_csrf'];
$base = ['csrf'=>$csrf, 'username'=>$owner, 'password'=>$password];
$badPost = $post;
$badPost['REMOTE_ADDR'] = '198.51.100.9';
$missing = OwnerLoginGate::handle($badPost,$base,$s,$cfg,$now+60,$regenerate);
$incorrect = OwnerLoginGate::handle($badPost,
    ['totp'=>'not-a-code'] + $base,$s,$cfg,$now+60,$regenerate);
$expired = OwnerLoginGate::handle($badPost,
    ['totp'=>Totp::code($secret,$now-120)] + $base,
    $s,$cfg,$now+60,$regenerate);
$before = OwnerLoginGate::isAuthenticated($s,$now+60);
$valid = OwnerLoginGate::handle($post,
    ['totp'=>Totp::code($secret,$now+60)] + $base,
    $s,$cfg,$now+60,$regenerate);
$replaySession = [];
OwnerLoginGate::handle($get, [], $replaySession,$cfg,$now+60);
$replay = OwnerLoginGate::handle($post,
    ['totp'=>Totp::code($secret,$now+60),
     'csrf'=>$replaySession['_cb_login_csrf'],
     'username'=>$owner,'password'=>$password],
    $replaySession,$cfg,$now+60,$regenerate);
echo json_encode([
 'missing'=>$missing,'incorrect'=>$incorrect,'expired'=>$expired,
 'authenticated_before'=>$before,'valid'=>$valid,'replay'=>$replay,
 'replay_authenticated'=>OwnerLoginGate::isAuthenticated($replaySession,$now+60),
 'audit_events'=>array_column($store->auditTrail(),'event'),
], JSON_THROW_ON_ERROR);
"""


def php_case(script: str) -> dict:
    """Run the actual PHP auth gate with an isolated, ownerless fixture directory."""
    with tempfile.TemporaryDirectory(prefix="cb1364-negative-") as sandbox:
        env = os.environ.copy()
        env["CONTROLBOT_NEGATIVE_TEST_DIR"] = sandbox
        result = subprocess.run(
            ["php", "-r", PHP_BASE + script],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30,
            check=False,
        )
        if result.returncode:
            raise AssertionError(
                f"PHP fixture exit={result.returncode}: {result.stderr[:1200]}"
            )
        return json.loads(result.stdout)


class AuthNegativeTests(unittest.TestCase):
    """Fail-closed assertions; no green result is inferred from static source text."""

    def test_criterio_principal(self):
        login = php_case(LOGIN_SCENARIO)
        for name, status in (
            ("anonymous", 302), ("missing", 403), ("forged", 403),
            ("wrong", 401), ("encoded", 403), ("traversal", 403),
            ("asset_trick", 403), ("expired", 302),
            ("wrong_logout", 403), ("stale", 302),
        ):
            with self.subTest(denial=name):
                response = login[name]
                self.assertEqual(response["status"], status)
                self.assertEqual(response["headers"]["Cache-Control"], "no-store")
        self.assertEqual(login["form"], 200)
        self.assertFalse(login["denied_session"])
        self.assertEqual(login["valid"]["status"], 303)
        self.assertEqual(login["valid"]["headers"]["Location"], "/overview")
        self.assertIsNone(login["allowed"])
        self.assertEqual(login["revoke"]["status"], 303)
        self.assertTrue(login["expired_cleared"])
        self.assertTrue(login["stale_cleared"])
        self.assertFalse(login["revoked_authenticated"])
        self.assertGreaterEqual(login["regenerations"], 2)
        self.assertIn("login", login["audit_events"])
        self.assertIn("revoke", login["audit_events"])
        totp = php_case(TOTP_SCENARIO)
        for case in ("missing", "incorrect", "expired", "replay"):
            with self.subTest(totp=case):
                self.assertEqual(totp[case]["status"], 401)
                self.assertEqual(totp[case]["headers"]["Cache-Control"], "no-store")
        self.assertFalse(totp["authenticated_before"])
        self.assertEqual(totp["valid"]["status"], 303)
        self.assertFalse(totp["replay_authenticated"])
        self.assertIn("totp_enabled", totp["audit_events"])
        self.assertIn("totp_used", totp["audit_events"])

    def test_aislamiento_y_reproducibilidad(self):
        """Replaying isolated synthetic scenarios must never share session state."""
        first = php_case(LOGIN_SCENARIO)
        second = php_case(LOGIN_SCENARIO)
        self.assertEqual(first["anonymous"]["status"], second["anonymous"]["status"])
        self.assertEqual(first["stale"]["status"], second["stale"]["status"])
        self.assertNotIn("fixture-password-only", json.dumps(first))
        self.assertNotIn("totp_key", json.dumps(second).lower())

    def test_documentacion_y_sin_credenciales_reales(self):
        doc = (ROOT / "docs/auth-negative-tests.md").read_text(encoding="utf-8")
        for term in ("Auth-negative-testsTests::test_criterio_principal",
                     "SPEC", "CSRF", "TOTP", "revocación", "CI Factory v1",
                     "Hostinger", "no-store"):
            self.assertIn(term, doc)
        for path in ("src/OwnerLoginGate.php", "src/OwnerLocalAuthStore.php",
                     "src/OwnerSession.php", "public/login.php"):
            self.assertTrue((ROOT / path).is_file())
        self.assertNotIn("https://", PHP_BASE + LOGIN_SCENARIO + TOTP_SCENARIO)
        self.assertNotIn("curl_", PHP_BASE + LOGIN_SCENARIO + TOTP_SCENARIO)


if __name__ == "__main__":
    unittest.main()
