import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"identity_center_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)
class IdentityCenterTests(unittest.TestCase):
    def test_supported_commands_are_closed_and_idempotent(self):
        rows=scenario("commands"); self.assertEqual(len(rows),11); self.assertEqual(len({r["operation"] for r in rows}),11); self.assertTrue(all(r["idempotency_key"].startswith("idem_") for r in rows))
    def test_secret_or_credential_payloads_are_rejected(self):
        self.assertTrue(all(scenario("secrets")["blocked"].values()))
    def test_scoped_revocation_preserves_identity_history_and_other_scopes(self):
        d=scenario("revoke"); self.assertEqual(d["status"],"applied"); self.assertEqual(d["state"]["identity"]["identity_id"],"identity-user"); self.assertEqual([g["grant_id"] for g in d["state"]["grants"]],["grant-other"]); self.assertEqual(len(d["state"]["audit"]),1)
    def test_role_changes_never_bypass_decision_rights(self):
        d=scenario("role-change"); self.assertEqual(d["denied"]["status"],"owner_decision_required"); self.assertEqual(d["denied"]["state"]["grants"][0]["role"],"operator"); self.assertEqual(d["allowed"]["state"]["grants"][0]["role"],"viewer"); self.assertEqual(d["allowed"]["state"]["grants"][0]["authority_level"],"L1_OPERATOR"); self.assertEqual(d["capability"]["status"],"owner_decision_required"); self.assertEqual(d["capability"]["state"]["grants"][0]["capability"],"venture.manage")
    def test_reset_and_reauth_are_requests_not_credentials(self):
        d=scenario("requests"); self.assertEqual(d["reset"]["state"]["requests"][0]["kind"],"reset"); self.assertEqual(d["reauth"]["state"]["requests"][0]["kind"],"reauth"); self.assertFalse(any(x in d["serialized"].lower() for x in ("password","cookie","otp","secret","credential","reset_token")))
    def test_mfa_is_metadata_only(self):
        d=scenario("mfa"); self.assertEqual(d["state"]["mfa"],{"required":True,"status":"required"}); self.assertNotIn("secret",json.dumps(d).lower())
    def test_audit_evidence_is_deterministic_and_sanitized(self):
        d=scenario("deterministic"); self.assertTrue(d["same"]); self.assertTrue(d["actor_scope_blocked"]); self.assertTrue(d["audit_full_blocked"]); self.assertEqual(d["first"]["audit_event"],d["second"]["audit_event"]); self.assertEqual(d["replay"]["status"],"already_applied"); self.assertEqual(d["denied_replay"]["status"],"owner_decision_required"); self.assertEqual(d["expired_suspend"]["status"],"applied"); self.assertEqual(d["expired_suspend"]["state"]["grants"][0]["grant_id"],"grant-expired"); self.assertEqual(d["expired_revoke"]["state"]["grants"],[]); self.assertEqual(d["first"]["audit_event"]["outcome"],"applied"); self.assertFalse(any(x in d["serialized"].lower() for x in ("password","token","cookie","otp","secret","credential")))
if __name__=="__main__": unittest.main()
