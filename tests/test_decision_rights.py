import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "decision_rights_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class DecisionRightsTests(unittest.TestCase):
    def test_exact_scope_and_authority_allow_only_granted_capability(self):
        data = scenario("allow-exact")
        self.assertEqual(data["allowed"], {"decision": "allow", "reasons": ["authorized"]})
        self.assertEqual(data["wrong_capability"]["decision"], "deny")
        self.assertEqual(data["wrong_capability"]["reasons"], ["capability_not_granted"])

    def test_unknown_expired_or_unknown_authority_denies(self):
        data = scenario("invalid")
        self.assertEqual(data["unknown_scope"], {"decision": "deny", "reasons": ["invalid_input"]})
        self.assertEqual(data["expired_grant"], {"decision": "deny", "reasons": ["invalid_grant"]})
        self.assertEqual(data["unknown_authority"], {"decision": "deny", "reasons": ["invalid_input"]})

    def test_venture_admin_cannot_cross_venture_without_explicit_grant(self):
        result = scenario("cross-venture")["result"]
        self.assertEqual(result["decision"], "deny")
        self.assertEqual(result["reasons"], ["scope_mismatch"])

    def test_policy_and_budget_can_only_restrict_authority(self):
        data = scenario("restrictions")
        self.assertEqual(data["policy"], {"decision": "deny", "reasons": ["policy_not_active"]})
        self.assertEqual(
            data["budget"],
            {"decision": "owner_decision_required", "reasons": ["budget_approval_required"]},
        )
        self.assertEqual(
            data["authority"],
            {"decision": "owner_decision_required", "reasons": ["authority_escalation_required"]},
        )
        self.assertNotEqual(data["budget"]["decision"], "allow")
        self.assertNotEqual(data["authority"]["decision"], "allow")

    def test_l4_action_requires_owner_decision(self):
        result = scenario("owner-decision")["result"]
        self.assertEqual(result["decision"], "owner_decision_required")
        self.assertEqual(result["reasons"], ["owner_authority_required"])

    def test_untrusted_identity_scope_or_secret_fields_are_rejected(self):
        data = scenario("untrusted")
        self.assertEqual(data["action_claims"], {"decision": "deny", "reasons": ["invalid_input"]})
        self.assertEqual(data["context_secret"], {"decision": "deny", "reasons": ["invalid_input"]})
        self.assertFalse(data["leaked"])

    def test_evaluation_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            data["first"]["reasons"],
            ["identity_mismatch", "scope_mismatch", "capability_not_granted", "policy_not_active"],
        )


if __name__ == "__main__":
    unittest.main()
