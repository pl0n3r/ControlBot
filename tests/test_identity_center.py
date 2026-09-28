import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "identity_center_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)

class IdentityCenterTests(unittest.TestCase):
    def test_supported_commands_are_closed_and_idempotent(self):
        rows = scenario("commands")
        self.assertEqual(len(rows), 11)
        self.assertEqual(len({row["operation"] for row in rows}), 11)
        self.assertTrue(all(row["idempotency_key"].startswith("idem_") for row in rows))

    def test_secret_or_credential_payloads_are_rejected(self):
        data = scenario("secrets")
        self.assertTrue(all(data["blocked"].values()))
        self.assertFalse(data["leaked"])

    def test_scoped_revocation_preserves_identity_history_and_other_scopes(self):
        data = scenario("revoke")
        self.assertEqual(data["status"], "applied")
        self.assertEqual(data["state"]["identity"]["identity_id"], "identity-user")
        self.assertEqual([g["grant_id"] for g in data["state"]["grants"]], ["grant-other"])
        self.assertEqual(len(data["state"]["audit"]), 1)
        self.assertEqual(data["audit_event"]["scope"], "venture:grindflow")

    def test_role_changes_never_bypass_decision_rights(self):
        data = scenario("role-change")
        self.assertEqual(data["denied"]["status"], "owner_decision_required")
        self.assertEqual(data["denied"]["state"]["grants"][0]["role"], "operator")
        self.assertEqual(data["allowed"]["status"], "applied")
        self.assertEqual(data["allowed"]["state"]["grants"][0]["role"], "viewer")
        self.assertEqual(data["allowed"]["state"]["grants"][0]["authority_level"], "L1_OPERATOR")

    def test_reset_and_reauth_are_requests_not_credentials(self):
        data = scenario("requests")
        self.assertEqual(data["reset"]["state"]["requests"][0]["kind"], "reset")
        self.assertEqual(data["reauth"]["state"]["requests"][0]["kind"], "reauth")
        lowered = data["serialized"].lower()
        for forbidden in ("password", "cookie", "otp", "secret", "credential", "reset_token"):
            self.assertNotIn(forbidden, lowered)

    def test_mfa_is_metadata_only(self):
        data = scenario("mfa")
        self.assertEqual(data["status"], "applied")
        self.assertEqual(data["state"]["mfa"], {"required": True, "status": "required"})
        self.assertNotIn("secret", json.dumps(data).lower())

    def test_audit_evidence_is_deterministic_and_sanitized(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"]["audit_event"], data["second"]["audit_event"])
        self.assertEqual(data["replay"]["status"], "already_applied")
        self.assertEqual(data["replay"]["audit_event"], data["first"]["audit_event"])
        event = data["first"]["audit_event"]
        self.assertEqual(event["actor_identity_id"], "identity-admin")
        self.assertEqual(event["reason_code"], "owner_request")
        self.assertEqual(event["outcome"], "applied")
        for forbidden in ("password", "token", "cookie", "otp", "secret", "credential"):
            self.assertNotIn(forbidden, data["serialized"].lower())

if __name__ == "__main__":
    unittest.main()
