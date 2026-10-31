"""#1380: contrato offline de OwnerLoginGate tras fusionar #1379."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
require_once 'src/Approvals.php';
require_once 'src/OwnerSession.php';
require_once 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;
$now=1800000000;
$directory=getenv('CONTROLBOT_TEST_LIMIT_DIR');
$key=base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$config=['owner_login'=>'pl0n3r','password_hash'=>password_hash('fixture-only', PASSWORD_ARGON2ID, ['memory_cost'=>4096,'time_cost'=>1,'threads'=>1]),'rate_limit_dir'=>$directory,'totp_key'=>$key];
$get=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.24'];
$post=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.24'];
$private=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview'];
$session=[];
function check1380(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
check1380(OwnerLoginGate::requireOwner($private,$session,$config,$now)['status']===302,'anonymous allowed');
check1380(OwnerLoginGate::requireOwner(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/health'],$session,$config,$now)===null,'health blocked');
check1380(OwnerLoginGate::handle($get,[],$session,$config,$now)['status']===200,'login page');
check1380(OwnerLoginGate::handle($post,['csrf'=>'wrong','username'=>'pl0n3r','password'=>'fixture-only'],$session,$config,$now)['status']===403,'csrf');
$csrf=$session['_cb_login_csrf'];
$rotations=0;
$regen=static function () use (&$rotations): bool { $rotations++; return true; };
check1380(OwnerLoginGate::handle($post,['csrf'=>$csrf,'username'=>'pl0n3r','password'=>'fixture-only'],$session,$config,$now,$regen)['status']===303,'credentials');
check1380($rotations===1 && OwnerLoginGate::requireOwner($private,$session,$config,$now+1)===null,'owner denied');
$store=new OwnerLocalAuthStore($directory,'pl0n3r',$key);
$store->revokeAll($now+2);
check1380(OwnerLoginGate::requireOwner($private,$session,$config,$now+3)['status']===302,'revocation ignored');
check1380(OwnerLoginGate::handle($get,[],$session,$config,$now+4)['status']===200,'after revoke login page');
check1380(OwnerLoginGate::handle($post,['csrf'=>$session['_cb_login_csrf'],'username'=>'pl0n3r','password'=>'fixture-only'],$session,$config,$now+4,$regen)['status']===303,'relogin');
$expiredSession=$session;
check1380(OwnerLoginGate::requireOwner($private,$expiredSession,$config,$now+905)['status']===302,'idle timeout');
$csrf=$session['_cb_login_csrf'];
check1380(OwnerLoginGate::handle($post,['action'=>'totp_begin','csrf'=>$csrf],$session,$config,$now+8,$regen)['status']===403,'begin without password');
$begin=OwnerLoginGate::handle($post,['action'=>'totp_begin','csrf'=>$csrf,'password'=>'fixture-only'],$session,$config,$now+9,$regen);
check1380($begin['status']===200 && preg_match('/[A-Z2-7]{32}/',$begin['body'],$m)===1,'TOTP begin');
$secret=$m[0];
check1380(OwnerLoginGate::handle($post,['action'=>'totp_confirm','csrf'=>$csrf,'totp'=>'0000'],$session,$config,$now+10,$regen)['status']===403,'invalid TOTP confirm');
$confirm=OwnerLoginGate::handle($post,['action'=>'totp_confirm','csrf'=>$csrf,'totp'=>Totp::code($secret,$now+11)],$session,$config,$now+11,$regen);
check1380($confirm['status']===200,'TOTP confirm');
preg_match_all('/\b[A-F0-9]{24}\b/',$confirm['body'],$codes);
check1380(count($codes[0])===8,'recovery count');
$recovery=$codes[0][0];
check1380(OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>$csrf],$session,$config,$now+12,$regen)['status']===303,'logout');
OwnerLoginGate::handle($get,[],$session,$config,$now+13);
$pass=['csrf'=>$session['_cb_login_csrf'],'username'=>'pl0n3r','password'=>'fixture-only'];
check1380(OwnerLoginGate::handle($post,$pass,$session,$config,$now+13,$regen)['status']===401,'password without TOTP');
$pass['totp']=$recovery;
check1380(OwnerLoginGate::handle($post,$pass,$session,$config,$now+14,$regen)['status']===303,'recovery login');
check1380(OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>$session['_cb_login_csrf']],$session,$config,$now+15,$regen)['status']===303,'recovery logout');
OwnerLoginGate::handle($get,[],$session,$config,$now+16);
$pass['csrf']=$session['_cb_login_csrf'];
check1380(OwnerLoginGate::handle($post,$pass,$session,$config,$now+16,$regen)['status']===401,'recovery replay');
$pass['totp']=Totp::code($secret,$now+41);
check1380(OwnerLoginGate::handle($post,$pass,$session,$config,$now+41,$regen)['status']===303,'TOTP login');
check1380(OwnerLoginGate::handle($post,['action'=>'totp_required','csrf'=>$session['_cb_login_csrf'],'totp'=>Totp::code($secret,$now+71)],$session,$config,$now+71,$regen)['status']===303,'required policy');
check1380(OwnerLoginGate::handle($post,['action'=>'totp_disable','csrf'=>$session['_cb_login_csrf'],'totp'=>Totp::code($secret,$now+101)],$session,$config,$now+101,$regen)['status']===403,'required disable');
check1380(OwnerLoginGate::handle($post,['action'=>'totp_optional','csrf'=>$session['_cb_login_csrf'],'totp'=>Totp::code($secret,$now+131)],$session,$config,$now+131,$regen)['status']===303,'optional policy');
check1380(OwnerLoginGate::handle($post,['action'=>'totp_disable','csrf'=>$session['_cb_login_csrf'],'totp'=>Totp::code($secret,$now+161)],$session,$config,$now+161,$regen)['status']===303,'disable TOTP');
check1380(!$store->status()['enabled'] && !$store->status()['required'],'MFA disable state');
check1380(OwnerLoginGate::handle($post,['action'=>'revoke_all','csrf'=>$session['_cb_login_csrf']],$session,$config,$now+162,$regen)['status']===403,'revoke needs password');
check1380(OwnerLoginGate::handle($post,['action'=>'revoke_all','csrf'=>$session['_cb_login_csrf'],'password'=>'fixture-only'],$session,$config,$now+163,$regen)['status']===303,'revoke via panel');
check1380(!OwnerLoginGate::isAuthenticated($session,$now+164),'revoke session');
echo 'OK';
'''

class OwnerGateSliceTests(unittest.TestCase):
    def test_owner_csrf_and_revocation(self):
        with tempfile.TemporaryDirectory(prefix='cb-1380-') as path:
            env=dict(os.environ, CONTROLBOT_TEST_LIMIT_DIR=path)
            p=subprocess.run(['php','-r',PHP], cwd=ROOT, env=env, capture_output=True, text=True, timeout=35)
            self.assertEqual(p.returncode,0,p.stdout+p.stderr)
            self.assertEqual(p.stdout,'OK')

    def test_scope_and_budget(self):
        source=(ROOT/'src/OwnerLoginGate.php').read_text()
        self.assertIn('validVersion',source)
        self.assertIn("['/login', '/login.php']",source)
        self.assertLessEqual(len(Path(__file__).read_text().splitlines()),100)

if __name__ == '__main__':
    unittest.main()
