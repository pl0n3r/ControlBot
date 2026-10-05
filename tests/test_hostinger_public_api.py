import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/hostinger_public_api_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True,check=True,timeout=20)
 return json.loads(r.stdout)

class HostingerPublicApiTests(unittest.TestCase):
 def test_transport_allowlist_is_https_hostinger_only_and_secret_free(self):
  d=scenario("guards");self.assertTrue(all(d["failed"]))

 def test_hostinger_read_lists_websites_with_source_and_freshness(self):
  d=scenario("canonical");self.assertEqual("hostinger-public-api",d["web"]["source"])
  self.assertEqual("current",d["web"]["freshness"]);self.assertEqual("control.example.test",d["web"]["data"]["websites"][0]["domain"])

 def test_cron_snapshot_preserves_uid_schedule_and_command_without_inventing_state(self):
  row=scenario("canonical")["cron"]["data"]["cron_jobs"][0]
  self.assertEqual({"uid":"cron_1","time":"*/5 * * * *","command":"php collector.php"},row)
  self.assertNotIn("status",row)

 def test_http_rate_limit_invalid_json_and_payload_overflow_fail_closed(self):
  self.assertTrue(all(scenario("failures")["failed"]))

 def test_cron_write_requires_exact_grant_and_is_dry_run_only(self):
  d=scenario("canonical");self.assertTrue(scenario("failures")["denied"])
  self.assertFalse(d["plan"]["execution"]);self.assertTrue(d["plan"]["dry_run"])
  self.assertEqual("POST",d["plan"]["method"]);self.assertEqual(d["before"],len(d["calls"]))

 def test_fake_transport_has_no_network_and_evidence_contains_no_secrets(self):
  d=scenario("canonical");blob=json.dumps(d)
  self.assertNotIn("sentinel-hostinger-token",blob)
  self.assertTrue(all(x["method"]=="GET" and x["url"].startswith("https://developers.hostinger.com/api/") for x in d["calls"]))

if __name__=="__main__":unittest.main()
