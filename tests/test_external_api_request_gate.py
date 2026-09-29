import json,subprocess,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"external_api_request_gate_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ExternalApiRequestGateTests(unittest.TestCase):
    def test_read_requires_active_session_and_preserves_access_decision(self):
        d=scenario("read")
        self.assertEqual(d["valid"]["decision"],"allow")
        self.assertEqual(d["valid"]["capability"],"owner.cockpit.read")
        self.assertFalse(d["valid"]["mutation"])
        self.assertIsNone(d["valid"]["step_up_ref"])
        self.assertTrue(d["revoked_device"])

    def test_access_deny_cannot_be_overridden_by_step_up(self):
        d=scenario("deny")
        self.assertEqual(d["decision"],"deny")
        self.assertIn("capability_not_granted",d["reasons"])
        self.assertIsNone(d["step_up_ref"])

    def test_mutation_requires_valid_step_up_bound_to_same_context(self):
        d=scenario("mutation")
        self.assertEqual(d["without"]["decision"],"step_up_required")
        self.assertIsNone(d["without"]["step_up_ref"])
        self.assertEqual(d["with"]["decision"],"allow")
        self.assertEqual(d["with"]["reasons"],["authorized_with_step_up"])
        self.assertRegex(d["with"]["step_up_ref"],r"^stepup:[a-f0-9]{32}$")

    def test_revoked_expired_or_mismatched_authentication_fails_closed(self):
        d=scenario("auth_fail")
        self.assertTrue(all(d.values()))

    def test_client_cannot_supply_authorization_fields_or_secrets(self):
        d=scenario("client")
        self.assertTrue(all(d.values()))

    def test_gate_has_no_persistence_tokens_providers_workitems_or_execution(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["authorize"])
        source=d["source"].lower()
        for forbidden in ("pdo(","mysqli","curl_","factoryrunner","workitem","scheduler","dispatch","oauth","jwt","access_token","refresh_token"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
