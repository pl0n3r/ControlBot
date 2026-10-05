import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
FIXTURE="fixture-credential-ALPHA-938271"

def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/hostinger_public_api_runtime_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True,check=True,timeout=20)
 return json.loads(r.stdout)

class HostingerPublicApiRuntimeTests(unittest.TestCase):
 def test_runtime_resolves_hostinger_api_token_only_inside_secrets_broker(self):
  d=scenario("canonical")
  self.assertTrue(d["web"]["ok"])
  self.assertEqual("hostinger-public-api",d["web"]["result"]["source"])
  self.assertEqual("control.example.test",d["web"]["result"]["data"]["websites"][0]["domain"])
  self.assertNotIn(FIXTURE,json.dumps(d))

 def test_scope_provider_kind_revocation_and_generation_fail_closed_before_transport(self):
  d=scenario("scope")
  self.assertEqual(0,d["transport_calls"])
  self.assertEqual("secret_scope_mismatch",d["cases"]["capability"]["reason"])
  self.assertEqual("secret_scope_mismatch",d["cases"]["project"]["reason"])
  self.assertEqual("secret_scope_mismatch",d["cases"]["environment"]["reason"])
  self.assertEqual("secret_scope_mismatch",d["cases"]["generation"]["reason"])
  self.assertEqual("secret_reference_incompatible",d["cases"]["provider"]["reason"])
  self.assertEqual("secret_reference_incompatible",d["cases"]["secret_kind"]["reason"])
  self.assertEqual("secret_reference_revoked",d["cases"]["revoked"]["reason"])

 def test_cron_reads_use_injected_transport_without_network_fallback(self):
  d=scenario("canonical")
  self.assertTrue(d["cron"]["ok"]);self.assertTrue(d["output"]["ok"])
  self.assertEqual(3,len(d["calls"]))
  self.assertTrue(all(row["method"]=="GET" and row["authorization_present"] for row in d["calls"]))
  self.assertTrue(all(row["url"].startswith("https://developers.hostinger.com/api/") for row in d["calls"]))

 def test_cron_write_remains_exact_grant_dry_run_without_secret_resolution(self):
  d=scenario("write")
  self.assertEqual(0,d["transport_calls"])
  self.assertFalse(d["plan"]["execution"]);self.assertTrue(d["plan"]["dry_run"])
  self.assertEqual("POST",d["plan"]["method"])
  self.assertEqual("cron.write",d["plan"]["capability"])

 def test_broker_sanitizes_transport_secret_echo_on_result_and_error(self):
  d=scenario("redaction")
  self.assertFalse(d["contains_fixture"])
  self.assertEqual("[REDACTED]",d["result"]["result"]["data"]["websites"][0]["domain"])
  self.assertFalse(d["error"]["ok"]);self.assertEqual("executor_failed",d["error"]["reason"])
  self.assertIn("[REDACTED]",d["error"]["error"])
  self.assertNotIn(FIXTURE,json.dumps(d))

if __name__=="__main__":unittest.main()
