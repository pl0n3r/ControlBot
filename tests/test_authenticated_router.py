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
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;
use ControlBot\Web\ControlBotWebEntrypoint;
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
$projection=tempnam(sys_get_temp_dir(),'cb-auth-project-');
$projectSnapshot=[
  'version'=>1,'project_id'=>'alpha-project','observed_at'=>$now-1,
  'repositories'=>[[
    'repository_id'=>'alpha-repo','repository'=>'pl0n3r/Alpha',
    'source_ref'=>'https://github.com/pl0n3r/Alpha','observed_at'=>$now-1,
    'main_sha'=>str_repeat('a',40),
    'checks'=>['items'=>[],'truncated'=>false],
    'pull_requests'=>['items'=>[],'truncated'=>false],
    'issues'=>['items'=>[],'truncated'=>false],
    'latest_release'=>null,'latest_workflow'=>null,
  ]],
];
file_put_contents($projection,json_encode(
    ['version'=>1,'projects'=>[$projectSnapshot]],JSON_THROW_ON_ERROR
));
$routes=[];
foreach(['/','/api/orchestrator-live','/github','/projects/alpha-project/github'] as $path){
  $request=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$path,'REMOTE_USER'=>'pl0n3r'];
  $denied=ControlBotWebEntrypoint::handleAuthenticated(
      $request,$env,$now,[],$service,$projection,
      sys_get_temp_dir().'/cb-auth-missing-snapshot.json',
      sys_get_temp_dir().'/cb-auth-snapshot-cache.json'
  );
  $allowed=ControlBotWebEntrypoint::handleAuthenticated(
      $request,$env,$now,$session,$service,$projection,
      sys_get_temp_dir().'/cb-auth-missing-snapshot.json',
      sys_get_temp_dir().'/cb-auth-snapshot-cache.json'
  );
  $old=ControlBotWebEntrypoint::handleAuthenticated(
      $request,$env,$now+301,$session,$service,$projection
  );
  $routes[$path]=[
      'denied'=>$denied['status'],
      'denied_body'=>$denied['body'],
      'denied_cache'=>$denied['headers']['Cache-Control']??null,
      'allowed'=>$allowed['status'],
      'expired'=>$old['status'],
  ];
}
@unlink($projection);
@unlink(sys_get_temp_dir().'/cb-auth-snapshot-cache.json');
echo json_encode([
  'missing'=>$missing,'not_otp'=>$notOtp,'valid'=>$valid,'expired'=>$expired,
  'untrusted_header'=>$untrustedHeader,'forged'=>$forged,
  'routes'=>$routes,
],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""

class RouterAutenticadoTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(ENTRY.is_file())
        self.assertTrue(GUARD.is_file())
        for path in (ENTRY, GUARD, ROOT / "public" / "index.php", ROOT / "tests" / "github_shell_e2e_scenarios.php"):
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
        for path, response in payload["routes"].items():
            with self.subTest(route=path):
                self.assertIn(response["denied"], (401, 403))
                self.assertEqual(response["denied_cache"], "no-store")
                self.assertEqual(response["denied_body"], "Forbidden.\n")
                self.assertEqual(response["allowed"], 200)
                self.assertIn(response["expired"], (401, 403))
        index = (ROOT / "public" / "index.php").read_text(encoding="utf-8")
        self.assertIn("handleAuthenticated(", index)
        self.assertIn("session_start(", index)
        self.assertNotIn("ControlBotWebEntrypoint::handle(", index)
        self.assertEqual(payload["valid"]["status"], 200)
        self.assertEqual(payload["untrusted_header"]["status"], 200)
        self.assertNotIn("gho_fixture_only", payload["valid"]["body"])


    def test_public_bootstrap_rejects_spoofed_remote_user(self):
        """El front controller no acepta REMOTE_USER como sesión OAuth."""
        php = r"""
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/github';
$_SERVER['REMOTE_USER'] = 'pl0n3r';
putenv('CONTROLBOT_OWNER_LOGIN=pl0n3r');
putenv('CONTROLBOT_VAULT_KEY='.base64_encode(
    str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
));
require 'public/index.php';
"""
        result = subprocess.run(
            ["php", "-r", php],
            cwd=ROOT, text=True, capture_output=True, timeout=30
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stdout, "Forbidden.\n")
        self.assertEqual(result.stderr, "")

if __name__ == "__main__":
    unittest.main()
