"""Evidencia offline de seguridad ya existente para ControlBot#1366.

Pruebas sintéticas del backend local: revocación entre sesiones, MFA opcional,
política obligatoria y recuperación de un uso. El cableado de public/ y
.htaccess sigue en un contrato V3 ajeno y no se presenta como entregado.
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


MFA_CASE = r"""
require_once 'src/Approvals.php';
require_once 'src/OwnerSession.php';
require_once 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;

function chk(bool $ok, string $msg): void {
    if (!$ok) throw new RuntimeException($msg);
}
$now = 1800000000;
$hash = password_hash('synthetic-secret', PASSWORD_ARGON2ID,
    ['memory_cost' => 4096, 'time_cost' => 1, 'threads' => 1]);
$key = base64_encode(str_repeat('Z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$cfg = [
    'owner_login'=>'pl0n3r','password_hash'=>$hash,
    'rate_limit_dir'=>getenv('CONTROLBOT_TEST_LIMIT_DIR'), 'totp_key'=>$key,
];
$get=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.18'];
$post=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/login','REMOTE_ADDR'=>'198.51.100.18'];
$private=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview','REMOTE_USER'=>'pl0n3r'];
$s=[]; $rotations=0;
$regen=static function () use (&$rotations): bool { $rotations++; return true; };
$state = new OwnerLocalAuthStore($cfg['rate_limit_dir'], 'pl0n3r', $key);
$stateBefore=$state->status();
chk(!$stateBefore['enabled'] && !$stateBefore['required'], 'MFA default must be off');
OwnerLoginGate::handle($get, [], $s, $cfg, $now);
$pass=['csrf'=>$s['_cb_login_csrf'], 'username'=>'pl0n3r', 'password'=>'synthetic-secret'];
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now,$regen)['status']===303, 'initial login');
$secondSession=$s;
chk(OwnerLoginGate::requireOwner($private,$secondSession,$cfg,$now+1)===null, 'second session');
$bad=OwnerLoginGate::handle($post,['action'=>'revoke_all','csrf'=>'bad'],$s,$cfg,$now+2,$regen);
chk($bad['status']===403,'CSRF revoke');
$revoked=OwnerLoginGate::handle($post,['action'=>'revoke_all','csrf'=>$s['_cb_login_csrf']],$s,$cfg,$now+3,$regen);
chk($revoked['status']===303,'revoke response');
chk(OwnerLoginGate::requireOwner($private,$secondSession,$cfg,$now+4)['status']===302,'revoke must affect other sessions');
OwnerLoginGate::handle($get,[],$s,$cfg,$now+5);
$pass['csrf']=$s['_cb_login_csrf'];
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now+5,$regen)['status']===303,'login after revoke');
$begin=OwnerLoginGate::handle($post,['action'=>'totp_begin','csrf'=>$s['_cb_login_csrf']],$s,$cfg,$now+6,$regen);
chk($begin['status']===200 && preg_match('/[A-Z2-7]{32}/',$begin['body'],$m)===1,'TOTP enrollment secret');
$secret=$m[0];
$wrong=OwnerLoginGate::handle($post,['action'=>'totp_confirm','csrf'=>$s['_cb_login_csrf'],'totp'=>'0000'],$s,$cfg,$now+7,$regen);
chk($wrong['status']===403,'invalid enrollment must be rejected');
$first=Totp::code($secret,$now+8);
$confirmed=OwnerLoginGate::handle($post,['action'=>'totp_confirm','csrf'=>$s['_cb_login_csrf'],'totp'=>$first],$s,$cfg,$now+8,$regen);
chk($confirmed['status']===200,'enrollment confirmation');
preg_match_all('/\b[A-F0-9]{24}\b/',$confirmed['body'],$codes);
chk(count($codes[0])===8,'recovery codes shown once');
$recovery=$codes[0][0];
chk($state->status()['enabled'], 'TOTP is enabled');
$logout=OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>$s['_cb_login_csrf']],$s,$cfg,$now+9,$regen);
chk($logout['status']===303,'logout after MFA');
OwnerLoginGate::handle($get,[],$s,$cfg,$now+10);
$pass['csrf']=$s['_cb_login_csrf'];
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now+10,$regen)['status']===401,'password alone after TOTP');
$pass['totp']=$recovery;
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now+20,$regen)['status']===303,
    'recovery code must authenticate once');
OwnerLoginGate::handle($post,['action'=>'logout','csrf'=>$s['_cb_login_csrf']],$s,$cfg,$now+21,$regen);
OwnerLoginGate::handle($get,[],$s,$cfg,$now+22);
$pass['csrf']=$s['_cb_login_csrf'];
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now+23,$regen)['status']===401,
    'recovery reuse must fail');
$pass['totp']=Totp::code($secret,$now+40);
chk(OwnerLoginGate::handle($post,$pass,$s,$cfg,$now+40,$regen)['status']===303,'correct TOTP login');
$policy=OwnerLoginGate::handle($post,[
    'action'=>'totp_required','csrf'=>$s['_cb_login_csrf'],
    'totp'=>Totp::code($secret,$now+70)],$s,$cfg,$now+70,$regen);
chk($policy['status']===303 && $state->status()['required'],'require policy');
$denyDisable=OwnerLoginGate::handle($post,[
    'action'=>'totp_disable','csrf'=>$s['_cb_login_csrf'],
    'totp'=>Totp::code($secret,$now+100)],$s,$cfg,$now+100,$regen);
chk($denyDisable['status']===403 && $state->status()['enabled'],'required policy blocks disable');
$optional=OwnerLoginGate::handle($post,[
    'action'=>'totp_optional','csrf'=>$s['_cb_login_csrf'],
    'totp'=>Totp::code($secret,$now+130)],$s,$cfg,$now+130,$regen);
chk($optional['status']===303 && !$state->status()['required'],'optional policy');
$off=OwnerLoginGate::handle($post,[
    'action'=>'totp_disable','csrf'=>$s['_cb_login_csrf'],
    'totp'=>Totp::code($secret,$now+160)],$s,$cfg,$now+160,$regen);
chk($off['status']===303 && !$state->status()['enabled'],'disable TOTP');
$names=array_column($state->auditTrail(),'event');
foreach (['login','logout','revoke','totp_setup_started','totp_enabled',
          'totp_policy','totp_disabled','totp_used','recovery_used'] as $event) {
    chk(in_array($event,$names,true),'missing audit: '.$event);
}
$raw=file_get_contents(glob($cfg['rate_limit_dir'].'/cb-auth-*.json')[0]);
chk(!str_contains($raw,$secret) && !str_contains($raw,$recovery)
    && !str_contains($raw,'synthetic-secret'), 'secrets stored in cleartext');
echo json_encode(['revocation'=>true,'totp'=>true,'audit'=>true],JSON_THROW_ON_ERROR);
"""

MFA_NEGATIVE_CASE = r"""
require_once 'src/Approvals.php';
require_once 'src/OwnerSession.php';
require_once 'src/OwnerLocalAuthStore.php';
use ControlBot\Security\OwnerLocalAuthStore;
use ControlBot\Security\Totp;
function chk2(bool $ok): void { if (!$ok) throw new RuntimeException('Negative contract failed'); }
$dir=getenv('CONTROLBOT_TEST_LIMIT_DIR');
$key=base64_encode(str_repeat('X',SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$now=1800000000;
$s=new OwnerLocalAuthStore($dir,'pl0n3r',$key);
$secret=$s->beginEnrollment($now);
chk2(!$s->verify('123456',$now));
try { $s->confirmEnrollment(Totp::code($secret,$now+400),$now+400); throw new RuntimeException('expired allowed'); }
catch (RuntimeException $e) { chk2($e->getMessage()==='TOTP setup invalid.'); }
$secret=$s->beginEnrollment($now+401);
$setupCode=Totp::code($secret,$now+401);
$s->confirmEnrollment($setupCode,$now+401);
chk2(!$s->verify($setupCode,$now+401));
$good=Totp::code($secret,$now+460);
chk2($s->verify($good,$now+460));
chk2(!$s->verify($good,$now+460));
chk2(!$s->verify($good,$now+550));
$badKey=new OwnerLocalAuthStore($dir,'pl0n3r',null);
try { $badKey->verify($good,$now+600); throw new RuntimeException('missing key allowed'); }
catch (RuntimeException $e) { chk2($e->getMessage()==='TOTP key unavailable.'); }
$events=$s->auditTrail();
chk2(!str_contains(json_encode($events),$good));
$raw=glob($dir.'/cb-auth-*.json')[0];
file_put_contents($raw,'{"version":false}');
try { $s->status(); throw new RuntimeException('corrupt state allowed'); }
catch (RuntimeException $e) { chk2($e->getMessage()==='Owner state corrupt.'); }
echo json_encode(['fail_closed'=>true,'one_time'=>true],JSON_THROW_ON_ERROR);
"""


class OwnerLoginSessionTests(unittest.TestCase):
    def _run_php_case(self, program):
        with tempfile.TemporaryDirectory(prefix="controlbot-owner1366-mfa-") as private:
            env = {**os.environ, "CONTROLBOT_TEST_LIMIT_DIR": private}
            result = subprocess.run(
                ["php", "-r", program], cwd=ROOT, env=env,
                text=True, capture_output=True, timeout=30,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            return json.loads(result.stdout)

    def test_revocation_and_totp_workflow(self):
        self.assertEqual(
            self._run_php_case(MFA_CASE),
            {"revocation": True, "totp": True, "audit": True},
        )

    def test_totp_fail_closed_and_audit(self):
        self.assertEqual(
            self._run_php_case(MFA_NEGATIVE_CASE),
            {"fail_closed": True, "one_time": True},
        )

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



if __name__ == "__main__":
    unittest.main()
