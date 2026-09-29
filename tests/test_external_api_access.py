import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
THREAT=ROOT/"docs"/"external-api-threat-model-v1.md"


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_access_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class ExternalApiAccessTests(unittest.TestCase):
    def test_authorization_requires_nominal_verified_access_context(self):
        data=scenario("nominal")
        self.assertTrue(data["constructor_private"])
        self.assertTrue(data["raw_rejected"])
        self.assertEqual(data["valid"]["decision"],"allow")

    def test_owner_read_is_allowed_only_through_decision_rights(self):
        data=scenario("owner_read")
        self.assertEqual(data["owner"]["decision"],"allow")
        self.assertEqual(data["owner"]["capability"],"owner.cockpit.read")
        self.assertEqual(data["owner"]["scope"],"venture:alpha")
        self.assertEqual(data["lower"]["decision"],"deny")
        self.assertIn("owner_authority_required",data["lower"]["reasons"])

    def test_mismatched_capability_scope_policy_or_identity_fails_closed(self):
        data=scenario("mismatch")
        self.assertEqual(data["capability"]["decision"],"deny")
        self.assertIn("capability_not_granted",data["capability"]["reasons"])
        self.assertEqual(data["scope"]["decision"],"deny")
        self.assertEqual(data["scope"]["reasons"],["resource_scope_mismatch"])
        self.assertTrue(data["policy_mint_rejected"])
        self.assertTrue(data["identity_mint_rejected"])

    def test_owner_decision_mutation_requires_future_verified_step_up(self):
        data=scenario("mutation")
        self.assertEqual(data["decision"],"step_up_required")
        self.assertEqual(data["reasons"],["verified_step_up_required"])
        self.assertTrue(data["mutation"])
        self.assertEqual(data["capability"],"owner.decision.write")

    def test_access_core_has_no_second_rbac_tokens_sessions_or_execution(self):
        data=scenario("surface")
        self.assertEqual(data["public"],["authorize"])
        source=data["source"]
        for forbidden in (
            "TokenVault","OwnerSessionService","FactoryRunner","curl_",
            "shell.arbitrary","dispatchWorkflow","githubToken",
        ):
            self.assertNotIn(forbidden,source)

    def test_threat_model_covers_horizontal_vertical_and_client_authority_bypass(self):
        text=THREAT.read_text(encoding="utf-8").lower()
        for required in (
            "horizontal scope escalation",
            "vertical privilege escalation",
            "client-supplied authority",
            "confused deputy",
            "replay",
            "stolen session",
            "stale snapshot",
            "direct-execution bypass",
        ):
            self.assertIn(required,text)


if __name__=="__main__":
    unittest.main()
