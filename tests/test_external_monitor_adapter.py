import json, os, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def run(name):
    p=subprocess.run(["php",str(ROOT/"tests"/"external_monitor_adapter_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True,check=True,timeout=20)
    return json.loads(p.stdout)
class ExternalMonitorAdapterTests(unittest.TestCase):
    def test_http_transport_produces_core_compatible_probe_with_limits(self):
        p=run("probe"); self.assertEqual(p["http_status"],200); self.assertEqual(p["reported_version"],"1.2.3"); self.assertEqual(len(p["reported_sha"]),40)
    def test_probe_failures_are_bounded_and_secret_free(self):
        x=run("failures"); self.assertEqual(x["timeout"]["outcome"],"timeout"); self.assertEqual(x["server"]["http_status"],500); self.assertNotIn("never-output",json.dumps(x)); self.assertTrue(x["oversized_rejected"])
    def test_required_alert_delivers_external_receipt_and_none_is_suppressed(self):
        x=run("deliver"); self.assertEqual(x["calls"],1); self.assertTrue(x["sent"]["attempted"]); self.assertEqual(x["sent"]["receipt"]["status"],"delivered"); self.assertFalse(x["quiet"]["attempted"])
    def test_external_channel_failure_preserves_diagnosis_and_fails_closed(self):
        x=run("channel_fail"); self.assertEqual(x["application_state"],"down"); self.assertEqual(x["delivery"]["receipt"]["status"],"failed"); self.assertNotIn("assessment",x["delivery"])
    def test_cli_runner_is_actions_independent_and_missing_config_fails_closed(self):
        env={k:v for k,v in os.environ.items() if not k.startswith("CONTROLBOT_MONITOR_")}; p=subprocess.run(["php",str(ROOT/"scripts"/"external-monitor.php")],cwd=ROOT,text=True,capture_output=True,env=env,timeout=20)
        self.assertNotEqual(p.returncode,0); self.assertIn("missing or invalid configuration",p.stderr); self.assertNotIn("github",p.stderr.lower())
    def test_dedupe_is_deterministic_and_payload_allowlisted(self):
        x=run("dedupe"); self.assertTrue(x["same"]); self.assertEqual(set(x["keys"]),{"delivery_id","channel","status","code","observed_at","evidence_ref"})
    def test_adapter_has_no_db_filesystem_write_shell_or_embedded_credentials(self):
        x=run("pure"); self.assertEqual(x["hits"],[]); self.assertFalse(x["actions"]); self.assertFalse(x["embedded"])
if __name__=="__main__": unittest.main()
