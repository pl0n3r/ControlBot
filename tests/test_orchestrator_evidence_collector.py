import json, os, subprocess, tempfile, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/factory_orchestrator_evidence_collector_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)
class OrchestratorEvidenceCollectorTests(unittest.TestCase):
 def test_collector_is_cli_only_disabled_by_default_and_fails_closed_without_valid_token_file(self):
  script=ROOT/"scripts/orchestrator-evidence-collector.php"
  r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True);self.assertEqual(0,r.returncode);self.assertEqual("disabled",json.loads(r.stdout)["state"])
  env=os.environ|{"CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE":"/missing","CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":"/tmp/cb-evidence.json"}
  r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True,env=env);self.assertNotEqual(0,r.returncode);self.assertNotIn("token",r.stdout.lower())
 def test_evidence_is_canonical_for_snapshot_source_with_injected_github_responses(self):
  d=scenario("canonical");self.assertTrue(d["snapshot"]["read_only"]);self.assertEqual(7,len(d["evidence"]["work_inventory"]["projects"]));self.assertEqual("ACTIVE",d["snapshot"]["central"]["activity_state"])
 def test_only_get_requests_are_made_within_request_and_byte_budgets(self):
  d=scenario("canonical");self.assertLessEqual(d["result"]["requests"],40);self.assertLessEqual(d["result"]["bytes"],2_000_000);self.assertTrue(all(x["method"]=="GET" for x in d["calls"]))
 def test_token_never_appears_in_output_logs_evidence_or_snapshot(self):
  d=scenario("canonical");blob=json.dumps(d);self.assertNotIn("sentinel-read-token",blob);self.assertTrue(all(x["authorized"] for x in d["calls"]))
 def test_error_or_rate_limit_keeps_previous_evidence_and_never_leaves_partial_file(self):
  d=scenario("failure");self.assertTrue(d["failed"]);self.assertEqual('{"old":true}',d["previous"])
if __name__=="__main__":unittest.main()
