import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCENARIOS=ROOT/"tests/factory_orchestrator_web_entrypoint_scenarios.php"
ENTRYPOINT=ROOT/"src/FactoryOrchestratorWebEntrypoint.php"
PUBLIC_INDEX=ROOT/"public/index.php"
RUNBOOK=ROOT/"docs/public-orchestrator-entrypoint.md"

def scenario(name):
    run=subprocess.run(["php",str(SCENARIOS),name],cwd=ROOT,text=True,capture_output=True,timeout=30)
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout or f"scenario {name} failed")
    return json.loads(run.stdout)

class FactoryOrchestratorWebEntrypointTests(unittest.TestCase):
    def test_entrypoint_serves_owner_html_and_json_from_local_snapshot_only(self):
        html=scenario("owner")
        self.assertEqual(html["status"],200)
        self.assertIn("Orquestador en vivo",html["body"])
        self.assertIn("reserved",html["body"])
        response=scenario("json");payload=json.loads(response["body"])
        self.assertEqual(response["status"],200)
        self.assertTrue(payload["read_only"])
        self.assertEqual(payload["snapshot"]["fronts"][0]["status"],"reserved")
        self.assertEqual(payload["snapshot"]["fronts"][0]["repository_ref"],"pl0n3r/ControlBot")
        source=ENTRYPOINT.read_text(encoding="utf-8").lower()
        for forbidden in ("api.github.com","github.com/","curl_","workflow_dispatch","shell_exec","proc_open"):
            self.assertNotIn(forbidden,source)
        self.assertIn("file_get_contents($path)",source)

    def test_missing_auth_wrong_owner_or_missing_config_fails_closed_without_data(self):
        result=scenario("auth")
        self.assertEqual(result["missing"]["status"],503)
        self.assertEqual(result["wrong"]["status"],403)
        self.assertEqual(result["config"]["status"],503)
        for row in result.values():
            body=row["body"].lower()
            for leak in ("reserved","fingerprint","github:"): self.assertNotIn(leak,body)

    def test_missing_or_invalid_local_snapshot_is_explicit_unknown_without_github_io(self):
        for name,row in scenario("unknown").items():
            with self.subTest(name=name):
                self.assertEqual(row["status"],200)
                payload=json.loads(row["body"])
                self.assertTrue(payload["read_only"])
                self.assertEqual(payload["snapshot"]["central"]["activity_state"],"UNKNOWN")
                self.assertEqual(payload["snapshot"]["fronts"],[])
                self.assertEqual(payload["snapshot"]["owner_decisions"],[])
        source=ENTRYPOINT.read_text(encoding="utf-8")
        self.assertIn("MAX_BYTES = 2_000_000",source)
        self.assertIn("MAX_AGE = 300",source)
        self.assertIn("private static function unknown",source)

    def test_public_bootstrap_and_runbook_preserve_d059_and_merge_order(self):
        bootstrap=PUBLIC_INDEX.read_text(encoding="utf-8");guide=RUNBOOK.read_text(encoding="utf-8")
        for value in ("FactoryOrchestratorWebEntrypoint::handle","CONTROLBOT_OWNER_LOGIN","$_SERVER"):
            self.assertIn(value,bootstrap)
        for value in ("DEPLOY_ENABLED","DOMAIN"): self.assertNotIn(value,bootstrap)
        for value in ("D-059","Git como respaldo","SHA exacto de main","SHA del último despliegue sano observado",
                      "revert explícito del merge","PR #630","#630 → este PR","var/orchestrator-live.json",
                      "300 segundos","/src/","/config/","403/404"):
            self.assertIn(value,guide)
        self.assertIn("no se fusiona",guide.lower())
        self.assertIn("no se exige un backup de hostinger/hpanel",guide.lower())
        for leak in ("password=","authorization:"): self.assertNotIn(leak,guide.lower())

if __name__=="__main__":
    unittest.main()
