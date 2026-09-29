import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"external_monitor_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ExternalMonitorCoreTests(unittest.TestCase):
    def test_fresh_probe_can_be_healthy_and_outage_is_not(self):
        d=scenario("probe")
        self.assertEqual(d["healthy"]["application_state"],"healthy")
        self.assertEqual(d["timeout"]["application_state"],"down")
        self.assertEqual(d["server"]["application_state"],"down")
        self.assertTrue(d["invalid"])

    def test_missing_stale_or_unknown_probe_never_becomes_healthy(self):
        d=scenario("freshness")
        self.assertEqual(d["missing"]["application_state"],"unknown")
        self.assertEqual(d["stale"]["application_state"],"degraded")
        self.assertEqual(d["unknown"]["application_state"],"unknown")
        self.assertEqual(d["expired"]["application_state"],"degraded")
        self.assertNotIn("healthy",[row["application_state"] for row in d.values()])

    def test_startup_failure_without_runner_or_steps_is_not_a_step_failure(self):
        d=scenario("workflow")
        self.assertEqual(d["success"]["private_workflow_state"],"healthy")
        self.assertEqual(d["failure"]["private_workflow_state"],"degraded")
        self.assertIn("private_workflow_step_failure",d["failure"]["reasons"])
        self.assertEqual(d["startup"]["private_workflow_state"],"blocked")
        self.assertIn("private_startup_failure_without_runner",d["startup"]["reasons"])
        self.assertNotIn("private_workflow_step_failure",d["startup"]["reasons"])
        self.assertTrue(d["invalid"])

    def test_incident_78_preserves_application_unknown_and_capacity_evidence(self):
        d=scenario("incident78")
        self.assertEqual(d["application_state"],"unknown")
        self.assertEqual(d["private_workflow_state"],"blocked")
        self.assertEqual(d["owner_capacity_state"],"exhausted")
        self.assertEqual(d["billing_mechanism_state"],"unknown")
        self.assertIn("capacity_evidence_converges",d["reasons"])
        self.assertNotIn("yaml",json.dumps(d).lower())

    def test_alert_intent_requires_external_channel_and_is_secret_free(self):
        d=scenario("alert")
        for key in ("capacity","outage"):
            alert=d[key]["alert_intent"]
            self.assertTrue(alert["required"])
            self.assertTrue(alert["external_channel_required"])
            self.assertEqual(alert["severity"],"critical")
        self.assertTrue(d["secret_rejected"])
        self.assertNotIn("secret",json.dumps(d["capacity"]).lower())

    def test_external_monitor_core_has_no_external_io(self):
        source=(ROOT/"src"/"ExternalMonitorCore.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","file_get_contents(","fopen(","file_put_contents(","new pdo","mysqli","shell_exec(","exec(","proc_open(","passthru(","cron"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
