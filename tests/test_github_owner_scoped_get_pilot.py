"""PHP-backed tests: owner-scoped GitHub GET-only pilot, never a live account (#1501)."""
import base64
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubOwnerScopedGetPilot; require_once 'src/GitHubOwnerScopedGetPilot.php';
try {
 $d=json_decode(base64_decode($argv[1]??'',true),true,16,JSON_THROW_ON_ERROR);$calls=[];$sc=0;$tc=0;
 $source=static function($r,$k,$now)use($d,&$sc){++$sc;if(($d['mode']??'')==='source_error')throw new RuntimeException('private-source');return $d['witness']??null;};
 $token=static function($id)use($d,&$tc){++$tc;if(($d['mode']??'')==='token_error')throw new RuntimeException('private-token');return $d['token']??'fixture-token-never-live';};
 $fake=static function($url,$token,$guards)use($d,&$calls){$calls[]=['url'=>$url,'guards'=>$guards];if(($d['mode']??'')==='sender_error')throw new RuntimeException('private-sender');return $d['replies'][count($calls)-1]??['status'=>429];};
 $p=new GitHubOwnerScopedGetPilot($source,$token,$fake,$d['enabled']??false);
 echo json_encode(['out'=>$p->read($d['request'],$d['now']??1050),'calls'=>$calls,'source_calls'=>$sc,'token_calls'=>$tc,'audit'=>$p->audit()],JSON_THROW_ON_ERROR);
} catch(Throwable) {echo '{"error":"fixture_invalid"}';}
'''
PHP_NATIVE = r'''
namespace {if(!function_exists('curl_init')){function curl_init($u){return false;}}
 foreach(explode(' ','CURLOPT_PROTOCOLS CURLPROTO_HTTPS CURLOPT_HTTPGET CURLOPT_FOLLOWLOCATION CURLOPT_MAXREDIRS CURLOPT_SSL_VERIFYPEER CURLOPT_SSL_VERIFYHOST CURLOPT_CONNECTTIMEOUT CURLOPT_TIMEOUT CURLOPT_USERAGENT CURLOPT_HTTPHEADER CURLOPT_WRITEFUNCTION CURLINFO_HTTP_CODE CURLINFO_CONTENT_TYPE CURLINFO_REDIRECT_COUNT CURLINFO_SSL_VERIFYRESULT') as $i=>$n){if(!defined($n))define($n,1000+$i);}}
namespace ControlBot\GitHub {
 function curl_init($url){$GLOBALS['fake_url']=$url;return ($GLOBALS['fake_mode']??'')==='init_fail'?false:new \stdClass();}
 function curl_setopt_array($h,$opts){$GLOBALS['fake_opts']=$opts;return true;}
 function curl_exec($h){if($GLOBALS['fake_mode']==='exec_fail')return false;$writer=$GLOBALS['fake_opts'][CURLOPT_WRITEFUNCTION];$writer($h,'[{"number":7,"state":"open"}]');return true;}
 function curl_getinfo($h,$opt){return match($opt){CURLINFO_HTTP_CODE => ($GLOBALS['fake_mode']==='forbidden'?403:200),CURLINFO_CONTENT_TYPE=>'application/json',CURLINFO_REDIRECT_COUNT=>0,CURLINFO_SSL_VERIFYRESULT=>0,default=>null};}
 function curl_close($h){}
 require_once 'src/GitHubOwnerScopedGetPilot.php';
 $d=json_decode(base64_decode($argv[1],true),true,16,JSON_THROW_ON_ERROR);$GLOBALS['fake_mode']=$d['mode'];
 $source=static fn($r,$k,$now)=>$d['witness'];$token=static fn($id)=>'only-a-fake-token';
 $pilot=new GitHubOwnerScopedGetPilot($source,$token,null,true);
 echo json_encode(['out'=>$pilot->read($d['request'],1050),'url'=>$GLOBALS['fake_url']??null],JSON_THROW_ON_ERROR);
}
'''


def native_run(mode="ok"):
    d = dict(witness=witness(), request=REQ, mode=mode)
    p = subprocess.run(["php", "-r", PHP_NATIVE, base64.b64encode(json.dumps(d).encode()).decode()],
                       cwd=ROOT, capture_output=True, text=True, timeout=25, check=False)
    if p.returncode:
        raise AssertionError(p.stderr)
    return json.loads(p.stdout)


REQ = {"repository": "pl0n3r/ControlBot", "resource": "issues", "page_size": 2}


def witness():
    return dict(origin="trusted_installation_source", installation_id=42, owner="pl0n3r",
                repository=REQ["repository"], permissions={"metadata": "read", "issues": "read"},
                grant=dict(origin="owner_approved_grant", installation_id=42, owner="pl0n3r",
                           repository=REQ["repository"], resource="issues", approved=True,
                           revoked=False, issued_at=1000, expires_at=1150),
                observed_at=1000, expires_at=1200, verified=True, revoked=False)


def reply(items=None, **changes):
    r = dict(status=200, content_type="application/json", redirected=False, tls_verified=True,
             body=json.dumps([{"number": 1, "state": "open"}] if items is None else items))
    return {**r, **changes}


def run(req=None, w=None, replies=None, enabled=True, mode="", token=None):
    d = dict(request=REQ if req is None else req, witness=witness() if w is None else w,
             replies=[reply()] if replies is None else replies, enabled=enabled, mode=mode)
    if token is not None:
        d["token"] = token
    p = subprocess.run(["php", "-r", PHP, base64.b64encode(json.dumps(d).encode()).decode()],
                       cwd=ROOT, capture_output=True, text=True, timeout=25, check=False)
    if p.returncode:
        raise AssertionError(p.stderr)
    return json.loads(p.stdout)


class GitHubOwnerScopedGetPilotTests(unittest.TestCase):
    def safe(self, result):
        self.assertIs(result["out"]["runtime_authorized"], False)
        self.assertIs(result["out"]["production_verified"], False)
        self.assertIs(result["out"]["execute_actions"], False)
        self.assertEqual(result["out"]["authority"], "unchanged")
        self.assertNotIn("private-", json.dumps(result))
        self.assertNotIn("fixture-token", json.dumps(result))

    def test_requires_server_verified_owner_installation_and_exact_repo_scope(self):
        ok = run()
        self.safe(ok)
        self.assertEqual((ok["out"]["status"], ok["out"]["projection"]["items"]),
                         ("OFFLINE_VALIDATED", [{"number": 1, "state": "open"}]))
        for change in ({"origin": "caller"}, {"owner": "other"}, {"installation_id": 0},
                       {"verified": False}, {"repository": "other/repo"},
                       {"permissions": {"metadata": "read", "issues": "write"}},
                       {"permissions": {"metadata": "read", "issues": "read", "contents": "write"}},
                       {"grant": {**witness()["grant"], "approved": False}}):
            with self.subTest(change=change):
                bad = run(w={**witness(), **change})
                self.safe(bad)
                self.assertEqual((bad["out"]["status"], bad["token_calls"], bad["calls"]),
                                 ("UNKNOWN", 0, []))
        for key in ({"token": "browser"}, {"installation_id": 42},
                    {"url": "https://evil.example"}):
            bad = run(req={**REQ, **key})
            self.assertEqual((bad["source_calls"], bad["calls"]), (0, []))

    def test_revocation_expiry_and_token_failure_fail_closed(self):
        for change in ({"revoked": True}, {"expires_at": 1050}, {"observed_at": 1051},
                       {"observed_at": 500}, {"expires_at": 1400},
                       {"grant": {**witness()["grant"], "revoked": True}},
                       {"grant": {**witness()["grant"], "expires_at": 1300}}):
            with self.subTest(change=change):
                bad = run(w={**witness(), **change})
                self.safe(bad)
                self.assertEqual((bad["out"]["status"], bad["token_calls"], bad["calls"]),
                                 ("UNKNOWN", 0, []))
        for bad in (run(mode="source_error"), run(mode="token_error"),
                    run(token="short"), run(token="bad\nheader"), run(w={})):
            self.safe(bad)
            self.assertEqual((bad["out"]["status"], bad["calls"]), ("UNKNOWN", []))

    def test_https_get_bounded_pages_and_ambiguous_responses(self):
        ok = run(replies=[reply([{"number": 1, "state": "open"}, {"number": 2, "state": "closed"}]),
                          reply([{"number": 3, "state": "open"}])])
        self.safe(ok)
        self.assertEqual([r["number"] for r in ok["out"]["projection"]["items"]], [1, 2, 3])
        for index, call in enumerate(ok["calls"], 1):
            self.assertEqual(call["url"], f"https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page={index}")
            self.assertEqual(call["guards"]["method"], "GET")
            self.assertIs(call["guards"]["tls_verify_peer"], True)
            self.assertIs(call["guards"]["follow_redirects"], False)
            self.assertLessEqual(call["guards"]["timeout_seconds"], 5)
        for responses in ([reply(status=403)], [reply(status=429)], [reply(redirected=True)],
                          [reply(tls_verified=False)], [reply(content_type="text/html")],
                          [reply([{"number": 0, "state": "open"}])],
                          [reply([{"number": 1, "state": "open"}] * 2)] * 3):
            bad = run(replies=responses)
            self.safe(bad)
            self.assertEqual((bad["out"]["status"], bad["out"]["projection"]), ("UNKNOWN", None))
        self.assertEqual(run(mode="sender_error")["out"]["status"], "UNKNOWN")

    def test_native_adapter_uses_curl_only_under_fully_fake_namespace(self):
        native = native_run()
        self.assertIs(native["out"]["runtime_authorized"], False)
        self.assertEqual(native["out"]["status"], "SOURCE_READ")
        self.assertEqual(native["out"]["projection"]["items"], [{"number": 7, "state": "open"}])
        self.assertEqual(native["url"], "https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page=1")
        for mode in ("forbidden", "exec_fail", "init_fail"):
            self.assertEqual(native_run(mode)["out"]["status"], "UNKNOWN")

    def test_disabled_default_no_web_wiring_or_writes(self):
        off = run(enabled=False)
        self.safe(off)
        self.assertEqual((off["out"]["status"], off["source_calls"], off["calls"]), ("UNKNOWN", 0, []))
        for change in ({"method": "POST"}, {"resource": "hooks"}, {"repository": "../admin"},
                       {"repository": "a/%2f"}, {"page_size": 100}):
            bad = run(req={**REQ, **change})
            self.assertEqual((bad["source_calls"], bad["calls"]), (0, []))
        src = (ROOT / "src/GitHubOwnerScopedGetPilot.php").read_text()
        for value in ("private readonly bool $enabled = false", "CURLOPT_HTTPGET => true",
                      "CURLOPT_SSL_VERIFYPEER => true", "CURLOPT_FOLLOWLOCATION => false"):
            self.assertIn(value, src)
        self.assertNotIn("CURLOPT_CUSTOMREQUEST", src)
        self.assertNotIn("GitHubOwnerScopedGetPilot", (ROOT / "src/ControlBotWebEntrypoint.php").read_text())
        doc = (ROOT / "docs/github-owner-scoped-get-pilot.md").read_text()
        for v in ("#55", "#1493", "#1501", "OFF", "GET", "D-043",
                  "runtime_authorized=false", "production_verified=false", "execute_actions=false"):
            self.assertIn(v, doc)
