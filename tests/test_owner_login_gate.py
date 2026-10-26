"""Regresiones offline de la puerta owner #916, sin credenciales ni red real."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src" / "OwnerLoginGate.php"
ENTRY = ROOT / "public" / "login.php"

BASE = r"""
require 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
$now = 1800000000;
$owner = 'pl0n3r';
$hash = password_hash('synthetic-fixture-only', PASSWORD_ARGON2ID, [
  'memory_cost' => 4096, 'time_cost' => 1, 'threads' => 1,
]);
$cfg = [
  'owner_login' => $owner,
  'password_hash' => $hash,
  'rate_limit_dir' => getenv('CONTROLBOT_TEST_LIMIT_DIR'),
];
$ip = '198.51.100.11';
$get = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login','REMOTE_ADDR'=>$ip];
$post = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login','REMOTE_ADDR'=>$ip];
$regen = 0;
$regenerate = static function () use (&$regen): bool { $regen++; return true; };
"""


def php_run(scenario: str) -> dict:
    with tempfile.TemporaryDirectory(prefix="controlbot-916-") as private_dir:
        env = os.environ.copy()
        env["CONTROLBOT_TEST_LIMIT_DIR"] = private_dir
        script = BASE + "\n" + scenario
        completed = subprocess.run(
            ["php", "-r", script], cwd=ROOT, text=True,
            capture_output=True, env=env, timeout=30,
            check=False,
        )
        if completed.returncode != 0:
            raise AssertionError(completed.stdout + completed.stderr)
        return json.loads(completed.stdout)


class OwnerLoginGateTests(unittest.TestCase):
    def test_login_form_and_authentication(self):
        data = php_run(r"""
$s = [];
$form = OwnerLoginGate::handle($get, [], $s, $cfg, $now);
$csrf = $s['_cb_login_csrf'];
$bad = OwnerLoginGate::handle($post, [
 'username'=>'intruder', 'password'=>'synthetic-fixture-only', 'csrf'=>$csrf,
], $s, $cfg, $now, $regenerate);
$good = OwnerLoginGate::handle($post, [
 'username'=>$owner, 'password'=>'synthetic-fixture-only', 'csrf'=>$csrf,
], $s, $cfg, $now, $regenerate);
$auth = OwnerLoginGate::isAuthenticated($s,$now);
$logoutForm = OwnerLoginGate::handle($get,[],$s,$cfg,$now);
echo json_encode([
 'form_status'=>$form['status'], 'html'=>$form['body'],
 'bad_status'=>$bad['status'], 'bad_body'=>$bad['body'],
 'success_status'=>$good['status'], 'redirect'=>$good['headers']['Location']??null,
 'regenerations'=>$regen, 'authenticated'=>$auth,
 'form_after_auth'=>$logoutForm['body'],
 'has_oauth_key'=>array_key_exists('_github_token_sealed',$s),
],JSON_THROW_ON_ERROR);
""")
        self.assertEqual(data["form_status"], 200)
        for phrase in ('lang="es"', 'name="username"', 'name="password"', 'Entrar', 'type="hidden"', 'viewport'):
            self.assertIn(phrase, data["html"])
        self.assertEqual(data["bad_status"], 401)
        self.assertIn("Usuario o contraseña incorrectos", data["bad_body"])
        self.assertEqual(data["success_status"], 303)
        self.assertEqual(data["redirect"], "/overview")
        self.assertEqual(data["regenerations"], 1)
        self.assertTrue(data["authenticated"])
        self.assertIn("Salir", data["form_after_auth"])
        self.assertFalse(data["has_oauth_key"], "Password login no debe crear token OAuth ficticio")

    def test_route_policy_and_missing_configuration(self):
        data = php_run(r"""
$s=[];
$private = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview','REMOTE_USER'=>$owner,
],$s,$cfg,$now);
$health = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/health',
],$s,$cfg,$now);
$asset = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/assets/main.css',
],$s,$cfg,$now);
$api = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/secrets','REMOTE_USER'=>$owner,
],$s,$cfg,$now);
$traversal = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/%2e%2e/overview',
],$s,$cfg,$now);
$without = $cfg; unset($without['password_hash']);
$failClosed = OwnerLoginGate::handle($get,[],$s,$without,$now);
$privateWithout = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview',
],$s,$without,$now);
$publicWithout = OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/health',
],$s,$without,$now);
echo json_encode([
 'private'=>$private,'health'=>$health,'asset'=>$asset,'api'=>$api,
 'traversal'=>$traversal,'fail_closed'=>$failClosed,'private_no_config'=>$privateWithout,
 'public_no_config'=>$publicWithout,
],JSON_THROW_ON_ERROR);
""")
        self.assertEqual(data["private"]["status"], 302)
        self.assertEqual(data["private"]["headers"]["Location"], "/login")
        self.assertEqual(data["api"]["status"], 302)
        self.assertIsNone(data["health"])
        self.assertIsNone(data["asset"])
        self.assertEqual(data["traversal"]["status"], 403)
        self.assertEqual(data["fail_closed"]["status"], 503)
        self.assertEqual(data["private_no_config"]["status"], 503)
        self.assertIsNone(data["public_no_config"])
        self.assertEqual(data["private"]["headers"]["Cache-Control"], "no-store")

    def test_rate_limit_csrf_and_expiration(self):
        data = php_run(r"""
$s=[];
OwnerLoginGate::handle($get, [], $s, $cfg, $now);
$csrf=$s['_cb_login_csrf'];
$csrfBad=OwnerLoginGate::handle($post,[
 'csrf'=>'invalid','username'=>$owner,'password'=>'synthetic-fixture-only',
],$s,$cfg,$now,$regenerate);
$invalid=[];
for($i=0;$i<4;$i++) {
 $invalid[] = OwnerLoginGate::handle($post,[
  'csrf'=>$csrf,'username'=>$owner,'password'=>'not-correct',
 ],$s,$cfg,$now,$regenerate)['status'];
}
$blockedValid=OwnerLoginGate::handle($post,[
 'csrf'=>$csrf,'username'=>$owner,'password'=>'synthetic-fixture-only',
],$s,$cfg,$now,$regenerate);
$later=OwnerLoginGate::handle($post,[
 'csrf'=>$csrf,'username'=>$owner,'password'=>'synthetic-fixture-only',
],$s,$cfg,$now+901,$regenerate);
$valid=$later['status'];
$csrfNew=$s['_cb_login_csrf'];
$expiry=OwnerLoginGate::requireOwner([
 'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview',
],$s,$cfg,$now+901+900);
$afterExpiry=OwnerLoginGate::isAuthenticated($s,$now+901+900);
// Authenticate again with a clean window, then test explicit logout CSRF.
OwnerLoginGate::handle($get, [], $s, $cfg, $now+1900);
$again=OwnerLoginGate::handle($post,[
 'csrf'=>$s['_cb_login_csrf'],'username'=>$owner,'password'=>'synthetic-fixture-only',
],$s,$cfg,$now+1900,$regenerate);
$logoutBad=OwnerLoginGate::handle($post,[
 'csrf'=>'invalid','action'=>'logout',
],$s,$cfg,$now+1901,$regenerate);
$logoutGood=OwnerLoginGate::handle($post,[
 'csrf'=>$s['_cb_login_csrf'],'action'=>'logout',
],$s,$cfg,$now+1901,$regenerate);
echo json_encode([
 'csrf_bad'=>$csrfBad['status'], 'attempts'=>$invalid,
 'blocked_good'=>$blockedValid['status'], 'later_login'=>$valid,
 'expiry'=>$expiry['status'], 'after_expiry'=>$afterExpiry,
 'again'=>$again['status'], 'logout_bad'=>$logoutBad['status'],
 'logout_good'=>$logoutGood['status'], 'authenticated_after_logout'=>OwnerLoginGate::isAuthenticated($s,$now+1901),
 'regen_count'=>$regen,
],JSON_THROW_ON_ERROR);
""")
        self.assertEqual(data["csrf_bad"], 403)
        self.assertEqual(data["attempts"], [401, 401, 401, 429])
        self.assertEqual(data["blocked_good"], 429)
        self.assertEqual(data["later_login"], 303)
        self.assertEqual(data["expiry"], 302)
        self.assertFalse(data["after_expiry"])
        self.assertEqual(data["again"], 303)
        self.assertEqual(data["logout_bad"], 403)
        self.assertEqual(data["logout_good"], 303)
        self.assertFalse(data["authenticated_after_logout"])
        self.assertEqual(data["regen_count"], 3)

    def test_php_lint_and_offline_integration_contract(self):
        for path in (SOURCE, ENTRY):
            with self.subTest(path=path.name):
                completed = subprocess.run(
                    ["php", "-l", str(path)], cwd=ROOT, text=True,
                    capture_output=True, timeout=15,
                )
                self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        entry = ENTRY.read_text(encoding="utf-8")
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("main{box-sizing:border-box;width:min(92vw,390px)", source)
        self.assertIn("session_regenerate_id(true)", entry)
        self.assertIn("session.use_strict_mode", entry)
        self.assertIn("'samesite' => 'Strict'", entry)
        self.assertIn("'secure' => true", entry)
        self.assertIn("'httponly' => true", entry)
        self.assertIn("CONTROLBOT_OWNER_PASSWORD_HASH", entry)
        self.assertIn("CONTROLBOT_OWNER_RATE_LIMIT_DIR", entry)
        self.assertIn("flock($handle, LOCK_EX)", source)
        self.assertIn("password_verify($password, $hash)", source)
        self.assertIn("hash_equals($config['owner_login'], $login)", source)
        self.assertNotIn("_github_token_sealed", entry)
        self.assertNotIn("_owner_login", entry)


if __name__ == "__main__":
    unittest.main()
