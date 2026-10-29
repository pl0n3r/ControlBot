"""Regresiones reales del snapshot del orquestador; fixtures offline (#1245)."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest
from unittest import mock
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/factory_orchestrator_evidence_collector_scenarios.php"
COLLECTOR = ROOT / "scripts/orchestrator-evidence-collector.php"
WRAPPER = ROOT / "scripts/orchestrator-snapshot-cron.php"
NOW = 1_800_000_000


def _scenario(name):
    result = subprocess.run(["php", str(SCENARIOS), name], cwd=ROOT,
                            text=True, capture_output=True, timeout=30, check=True)
    return json.loads(result.stdout)


def _php(script, *args):
    result = subprocess.run(["php", "-r", script, *map(str, args)], cwd=ROOT,
                            text=True, capture_output=True, timeout=30, check=False)
    if result.returncode:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


def _web_snapshot(snapshot_path, cache_path):
    code = r'''
require $argv[1].'/src/FactoryOrchestratorWebEntrypoint.php';
$r=\ControlBot\Business\FactoryOrchestratorWebEntrypoint::handle(
 ['REQUEST_URI'=>'/api/orchestrator-live','REQUEST_METHOD'=>'GET',
  'REMOTE_USER'=>'owner','DOCUMENT_ROOT'=>$argv[1]],
 ['CONTROLBOT_OWNER_LOGIN'=>'owner',
  'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH'=>$argv[2]],
 1800000000,null,$argv[3]);
echo json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
'''
    result = _php(code, ROOT, snapshot_path, cache_path)
    assert result["status"] == 200
    return json.loads(result["body"])["snapshot"]


class CoberturaControlbot62Tests(unittest.TestCase):
    """AC-01: contratos CB-1018..1034 que tienen ejecución offline real."""

    def test_contrato_principal(self):
        canonical = _scenario("canonical")
        recent = _scenario("recent-pulls")
        negative = _scenario("transport-negative-branches")
        crowded = _scenario("over-400")

        with self.subTest(row="CB-1019"):
            self.assertEqual(7, len(negative["failed"]))
            self.assertTrue(all(negative["failed"]))
            cli = COLLECTOR.read_text(encoding="utf-8")
            self.assertIn("CURLOPT_PROTOCOLS => CURLPROTO_HTTPS", cli)
            self.assertIn("FactoryOrchestratorEvidenceCollector::validateLiveRequest", cli)
            self.assertTrue(all(x["method"] == "GET" for x in canonical["calls"]))
            self.assertTrue(all(urlparse(x["url"]).scheme == "https" and
                                urlparse(x["url"]).hostname == "api.github.com"
                                for x in canonical["calls"]))

        with self.subTest(row="CB-1020"):
            evidence = canonical["evidence"]
            issue = next(x for x in evidence["work"] if x["id"] == "work:factory-10")
            self.assertEqual("pending", issue["state"])
            self.assertIn("estado: disponible", issue["data"]["labels"])
            self.assertNotIn("status", issue["data"])
            self.assertEqual("in_review", next(x for x in evidence["work"]
                if x["id"] == "work:factory-pr-13")["data"]["status"])
            self.assertEqual("merged", next(x for x in evidence["work"]
                if x["id"] == "work:factory-pr-9")["data"]["status"])
            self.assertEqual([], evidence["releases"])
            self.assertNotIn("work_inventory", evidence)
            self.assertTrue(any(x["id"] == "blocker:factory-11" for x in evidence["blockers"]))

        contract = _php(r'''
require 'src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as C;
$ok=C::normalizeLiveResponse(200,[], '{"answer":true}');
$retry=C::normalizeLiveResponse(502,[], '<html>not json</html>');
$bad=false;$oversized=false;
try{C::normalizeLiveResponse(200,[], '{not json');}catch(Throwable){$bad=true;}
try{C::normalizeLiveResponse(200,[],str_repeat('a',2000001));}catch(Throwable){$oversized=true;}
echo json_encode(['ok'=>$ok,'retry'=>$retry,'bad'=>$bad,'oversized'=>$oversized]);
''')
        with self.subTest(row="CB-1021"):
            self.assertTrue(contract["oversized"])
            self.assertLessEqual(canonical["result"]["download_bytes"], 8_000_000)
            self.assertLessEqual(canonical["result"]["evidence_bytes"], 2_000_000)
        with self.subTest(row="CB-1022"):
            self.assertEqual(len('{"answer":true}'.encode()), contract["ok"]["bytes"])
            self.assertEqual(len('<html>not json</html>'.encode()), contract["retry"]["bytes"])
        with self.subTest(row="CB-1023"):
            self.assertEqual(502, contract["retry"]["status"])
            self.assertNotIn("json", contract["retry"])
            self.assertTrue(contract["bad"])

        kill = _php(r'''
require 'src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as C;
$m=new ReflectionMethod(C::class,'killSwitchRunning');
$good=['number'=>767,'state'=>'closed','user'=>['login'=>'pl0n3r'],
'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];
$cases=[$good,$good,$good,$good,$good,$good];
$cases[1]['number']=768;$cases[2]['user']['login']='other';
$cases[3]['body'].=$good['body'];
$cases[4]['body']=str_replace('RUNNING','PAUSED',$good['body']);
$cases[5]['body']='<!-- factory-unattended-kill-switch {bad} -->';
echo json_encode(array_map(static fn($row)=>$m->invoke(null,$row),$cases));
''')
        with self.subTest(row="CB-1024"):
            self.assertEqual([True, False, False, False, False, False], kill)
            self.assertFalse(any(x["id"] == "blocker:factory-767"
                                 for x in canonical["evidence"]["blockers"]))

        with self.subTest(row="CB-1025"):
            pull_urls = [x["url"] for x in recent["calls"]
                         if urlparse(x["url"]).path.endswith("/pulls")]
            self.assertEqual(7, len(pull_urls))
            for url in pull_urls:
                self.assertEqual(["10"], parse_qs(urlparse(url).query)["per_page"])
                self.assertEqual(["1"], parse_qs(urlparse(url).query)["page"])
            self.assertFalse(any("page=2" in url for url in pull_urls))

        diagnostics = _php(r'''
require 'src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as C;
$reasons=['github http status 403 path /repos/pl0n3r/Factory/issues',
          'github retry deferred.','download byte budget exceeded.',
          'secret Bearer 123 /private/path'];
echo json_encode(array_map(static fn($m)=>C::diagnosticFor(new RuntimeException($m)),$reasons));
''')
        with self.subTest(row="CB-1026"):
            self.assertEqual({"code":"http_status_403","path":"/repos/pl0n3r/Factory/issues",
                              "status":403}, diagnostics[0])
            self.assertNotIn("secret", json.dumps(diagnostics))
            self.assertNotIn("Bearer", json.dumps(diagnostics))
        with self.subTest(row="CB-1027"):
            self.assertEqual(["rate_limited", "download_budget_exceeded", "internal_error"],
                             [x["code"] for x in diagnostics[1:]])

        with tempfile.TemporaryDirectory(prefix="cb1245-") as tmp:
            base = Path(tmp)
            evidence_path = base / "bad-evidence.json"
            evidence_path.write_text("{bad json", encoding="utf-8")
            target = base / "snapshot.json"
            env = os.environ.copy()
            env.update({"CONTROLBOT_ORCHESTRATOR_CRON_ENABLED":"1",
                        "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":str(evidence_path),
                        "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH":str(target),
                        "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS":"1"})
            wrapper = subprocess.run(["php",str(WRAPPER)],cwd=ROOT,env=env,
                                     text=True,capture_output=True,timeout=30)
            with self.subTest(row="CB-1028"):
                self.assertEqual(70, wrapper.returncode)
                self.assertRegex(wrapper.stderr, r"^orchestrator-snapshot-cron: stale_evidence_invalid dir_exists=1 dir_writable=1 dir_owner_match=(?:0|1|unknown) dir_mode=[0-7]{4}\n$")
                self.assertNotIn(tmp, wrapper.stderr)
                self.assertEqual("stale", json.loads(wrapper.stdout)["state"])

            sections = {name:[] for name in ("batches","owner_decisions","releases",
                      "blockers","production","quality","work","learning")}
            snapshot = {"version":1,"observed_at":NOW,"sections":sections}
            snapshot["fingerprint"] = hashlib.sha256(json.dumps(snapshot,
                separators=(",", ":"),ensure_ascii=False).encode()).hexdigest()
            target.write_text(json.dumps(snapshot,separators=(",", ":")))
            current = _web_snapshot(str(target), base / "cache-current.json")
            with self.subTest(row="CB-1030"):
                self.assertEqual(snapshot["fingerprint"], current["source_snapshot"])
                unknown = _web_snapshot("relative/orchestrator-live.json",
                                        base / "cache-invalid.json")
                self.assertEqual(hashlib.sha256(b"UNKNOWN").hexdigest(),
                                 unknown["source_snapshot"])
            with self.subTest(row="CB-1031"):
                self.assertTrue(target.is_file())
                self.assertFalse(str(target).startswith(str(ROOT)))
                self.assertEqual(snapshot["fingerprint"], current["source_snapshot"])

        with self.subTest(row="CB-1032"):
            self.assertLessEqual(canonical["result"]["requests"], 40)
            self.assertEqual(len(canonical["calls"]), canonical["result"]["requests"])
            self.assertEqual(50, crowded["blockers"])
            self.assertEqual(24, crowded["work"])
            self.assertTrue(crowded["summary"]["truncated"])
            self.assertEqual("bounded_signal_budget", crowded["summary"]["reason"])

        with self.subTest(row="CB-1018"):
            self.assertEqual((True,"written"),
                             (canonical["result"]["executed"], canonical["result"]["state"]))
            self.assertTrue(canonical["snapshot"]["read_only"])
            self.assertEqual([], canonical["evidence"]["releases"])

    def test_falla_cerrado(self):
        """AC-02: transporte inválido, límites, permisos y entradas ambiguas."""
        bad = _scenario("transport-negative-branches")
        self.assertTrue(all(bad["failed"]))
        self.assertNotIn("json", bad["retry"])
        self.assertEqual({"code":"http_status_403",
                          "path":"/repos/pl0n3r/Factory/issues", "status":403},
                         bad["http_diagnostic"])
        failed = _scenario("failure")
        self.assertTrue(failed["failed"])
        self.assertEqual('{"old":true}',failed["previous"])
        budget = _php(r'''
require 'src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector as C;
$d=sys_get_temp_dir().'/cb1245-budget-'.bin2hex(random_bytes(4));mkdir($d);
file_put_contents($d.'/token','synthetic');chmod($d.'/token',0600);
$env=['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$d.'/token',
'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$d.'/evidence'];
$call=0;$fake=static function()use(&$call){$call++;return ['status'=>200,'headers'=>[],
'bytes'=>1000001,'json'=>[]];};
try{C::run($env,$fake,200);$result='unexpected_success';}
catch(Throwable $e){$result=C::diagnosticFor($e)['code'];}
@unlink($d.'/token');@unlink($d.'/evidence');@rmdir($d);
echo json_encode(['code'=>$result,'calls'=>$call]);
''')
        self.assertEqual("download_budget_exceeded",budget["code"])
        self.assertGreater(budget["calls"], 8)
        env = os.environ.copy()
        env.update({"CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1",
                    "CONTROLBOT_GITHUB_READ_TOKEN_FILE":"/missing/private/token",
                    "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":"/tmp/cb1245-evidence.json"})
        cli = subprocess.run(["php",str(COLLECTOR)],cwd=ROOT,env=env,
                             text=True,capture_output=True,timeout=20)
        self.assertEqual(70, cli.returncode)
        self.assertIn("token_file_invalid",cli.stderr)
        self.assertNotIn("/missing/private/token",cli.stderr)

    def test_sin_datos_reales(self):
        """AC-03: sin credenciales, I/O de GitHub ni datos de usuarios reales."""
        env = os.environ.copy()
        for key in ("CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED",
                    "CONTROLBOT_ORCHESTRATOR_CRON_ENABLED",
                    "CONTROLBOT_GITHUB_READ_TOKEN_FILE"):
            env.pop(key,None)
        with mock.patch.object(socket,"create_connection",
                               side_effect=AssertionError("network forbidden")):
            canonical = _scenario("canonical")  # transporte PHP sintético inyectado
            collector = subprocess.run(["php",str(COLLECTOR)],cwd=ROOT,env=env,
                                       text=True,capture_output=True,timeout=20)
            wrapper = subprocess.run(["php",str(WRAPPER)],cwd=ROOT,env=env,
                                     text=True,capture_output=True,timeout=20)
        self.assertEqual(0,collector.returncode)
        self.assertEqual({"executed":False,"state":"disabled"},json.loads(collector.stdout))
        self.assertEqual({"executed":False,"state":"disabled"},json.loads(wrapper.stdout))
        self.assertEqual(0,wrapper.returncode)
        self.assertEqual([],canonical["evidence"]["releases"])
        self.assertTrue(all(x["authorized"] for x in canonical["calls"]))
        self.assertNotIn("Bearer",collector.stderr)
        self.assertNotIn("Bearer",wrapper.stderr)


if __name__ == "__main__":
    unittest.main()
