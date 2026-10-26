"""Regresiones sintéticas de la puerta owner #916, sin credenciales reales."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BASE = r"""
require 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Assertion failed'); }
$now = 1800000000;
$hash = password_hash('fixture-only', PASSWORD_ARGON2ID,
    ['memory_cost'=>4096, 'time_cost'=>1, 'threads'=>1]);
$cfg = ['owner_login'=>'pl0n3r', 'password_hash'=>$hash,
    'rate_limit_dir'=>getenv('CONTROLBOT_TEST_LIMIT_DIR')];
$get = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.11'];
$post = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.11'];
$s = []; $regen = 0;
$regenerate = static function () use (&$regen): bool { $regen++; return true; };
"""
def php_run(script):
    with tempfile.TemporaryDirectory(prefix="controlbot-916-") as private_dir:
        env = {**os.environ, "CONTROLBOT_TEST_LIMIT_DIR": private_dir}
        case = Path(private_dir) / "coverage-case.php"
        case.write_text("<?php\n" + BASE + script, encoding="utf-8")
        p = subprocess.run(["php", str(case)], cwd=ROOT, env=env,
                           text=True, capture_output=True, timeout=25)
        if p.returncode:
            raise AssertionError(p.stdout + p.stderr)

class OwnerLoginGateTests(unittest.TestCase):
    def test_login_form_and_authentication(self):
        php_run(r"""
$form = OwnerLoginGate::handle($get, [], $s, $cfg, $now);
check($form['status']===200 && str_contains($form['body'],'lang="es"'));
foreach (['Entrar', 'name="username"', 'name="password"', 'viewport'] as $word)
    check(str_contains($form['body'], $word));
$csrf = $s['_cb_login_csrf'];
$bad = OwnerLoginGate::handle($post,['csrf'=>$csrf,'username'=>'intruder','password'=>'fixture-only'],$s,$cfg,$now,$regenerate);
check($bad['status']===401 && str_contains($bad['body'],'Usuario o contraseña incorrectos'));
$good = OwnerLoginGate::handle($post,['csrf'=>$csrf,'username'=>'pl0n3r','password'=>'fixture-only'],$s,$cfg,$now,$regenerate);
check($good['status']===303 && $good['headers']['Location']==='/overview');
check($regen===1 && OwnerLoginGate::isAuthenticated($s,$now));
check(!array_key_exists('_github_token_sealed',$s));
check(str_contains(OwnerLoginGate::handle($get,[],$s,$cfg,$now)['body'],'Salir'));
""")
    def test_route_policy_and_missing_configuration(self):
        php_run(r"""
$private = OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview','REMOTE_USER'=>'pl0n3r'],$s,$cfg,$now);
check($private['status']===302 && $private['headers']['Location']==='/login');
check($private['headers']['Cache-Control']==='no-store');
foreach (['/health','/health.php','/assets/main.css'] as $path)
    check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$path],$s,$cfg,$now)===null);
check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/private'],$s,$cfg,$now)['status']===302);
check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/%2e%2e/overview'],$s,$cfg,$now)['status']===403);
$missing=$cfg; unset($missing['password_hash']);
check(OwnerLoginGate::handle($get,[],$s,$missing,$now)['status']===503);
check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview'],$s,$missing,$now)['status']===503);
check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/health'],$s,$missing,$now)===null);
""")
    def test_rate_limit_csrf_and_expiration(self):
        php_run(r"""
OwnerLoginGate::handle($get, [], $s, $cfg, $now); $csrf=$s['_cb_login_csrf'];
$credentials=['csrf'=>$csrf,'username'=>'pl0n3r','password'=>'fixture-only'];
check(OwnerLoginGate::handle($post,['csrf'=>'bad']+$credentials,$s,$cfg,$now,$regenerate)['status']===403);
for($i=0;$i<4;$i++) {
    $bad=OwnerLoginGate::handle($post,['password'=>'wrong']+$credentials,$s,$cfg,$now,$regenerate);
    check($bad['status']===($i===3?429:401));
}
check(OwnerLoginGate::handle($post,$credentials,$s,$cfg,$now,$regenerate)['status']===429);
check(OwnerLoginGate::handle($post,$credentials,$s,$cfg,$now+901,$regenerate)['status']===303);
check(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview'],$s,$cfg,$now+1801)['status']===302);
check(!OwnerLoginGate::isAuthenticated($s,$now+1801));
OwnerLoginGate::handle($get,[],$s,$cfg,$now+1900);
$credentials['csrf']=$s['_cb_login_csrf'];
check(OwnerLoginGate::handle($post,$credentials,$s,$cfg,$now+1900,$regenerate)['status']===303);
check(OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>'bad'],$s,$cfg,$now+1901,$regenerate)['status']===403);
check(OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>$s['_cb_login_csrf']],$s,$cfg,$now+1901,$regenerate)['status']===303);
check(!OwnerLoginGate::isAuthenticated($s,$now+1901) && $regen===3);
""")
    def test_php_lint_and_offline_integration_contract(self):
        for path in (ROOT / "src/OwnerLoginGate.php", ROOT / "public/login.php"):
            p = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
            self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
        entry = (ROOT / "public/login.php").read_text(encoding="utf-8")
        source = (ROOT / "src/OwnerLoginGate.php").read_text(encoding="utf-8")
        for token in ("session_regenerate_id(true)", "session.use_strict_mode", "'samesite' => 'Strict'",
                      "'secure' => true", "'httponly' => true", "CONTROLBOT_OWNER_PASSWORD_HASH",
                      "CONTROLBOT_OWNER_RATE_LIMIT_DIR"):
            self.assertIn(token, entry)
        for token in ("flock($handle, LOCK_EX)", "password_verify($password, $hash)",
                      "hash_equals($config['owner_login'], $login)", "main{box-sizing:border-box"):
            self.assertIn(token, source)
        self.assertNotIn("_github_token_sealed", entry)

if __name__ == "__main__":
    unittest.main()
