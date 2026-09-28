import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"venture_access_runtime_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)
class VentureAccessRuntimeTests(unittest.TestCase):
    def test_staff_visibility_is_scoped_to_venture(self):
        d=scenario("scope"); self.assertEqual(d["status"],"deny"); self.assertEqual(d["state"]["grants"][0]["scope"],"venture:beta")
    def test_owner_decision_required_reuses_existing_gate_flow(self):
        d=scenario("owner"); self.assertEqual(d["status"],"owner_decision_required"); self.assertEqual(d["parsed_gate"]["category"],"product-direction"); self.assertEqual(d["parsed_gate"]["safe_default"],"B"); self.assertEqual(d["state"]["grants"][0]["capability"],"venture.manage")
    def test_budget_and_production_authority_only_restrict(self):
        d=scenario("restrictions"); self.assertEqual(d["budget"]["status"],"deny"); self.assertEqual(d["budget_replay"]["status"],"deny"); self.assertEqual(d["budget_replay"]["audit"]["reason_code"],"budget_blocked"); self.assertEqual(d["production"]["status"],"owner_decision_required"); self.assertEqual(d["production_replay"]["status"],"owner_decision_required"); self.assertEqual(d["production_replay"]["audit"]["reason_code"],"production_override_required"); self.assertEqual(d["allow"]["status"],"applied"); self.assertEqual(d["budget"]["state"]["identity"]["state"],"active")
    def test_applied_replay_preserves_original_audit_outcome(self):
        d=scenario("replay"); self.assertEqual(d["first"]["status"],"applied"); self.assertEqual(d["replay"]["status"],"already_applied"); self.assertEqual(d["replay"]["audit"]["outcome"],"applied"); self.assertEqual(d["replay"]["audit"]["occurred_at"],d["first"]["audit"]["occurred_at"])
    def test_server_side_identity_and_scope_are_not_client_authority(self):
        d=scenario("trusted"); self.assertTrue(d["actor"]); self.assertTrue(d["scope"])
    def test_access_outcomes_are_audited_without_secrets(self):
        d=scenario("audit"); keys=("grant","revoke","override","deny"); self.assertEqual([d[x]["kind"] for x in keys],["grant","revoke","override","deny"]); self.assertEqual([d[x]["actor_identity_id"] for x in keys],["identity-admin"]*4); self.assertEqual([d[x]["scope"] for x in keys],[ "venture:alpha"]*4); self.assertFalse(any(x in json.dumps(d).lower() for x in ("password","token","cookie","otp","secret")))
    def test_lifecycle_surface_rejects_credentials(self):
        self.assertTrue(all(scenario("secrets")["blocked"].values()))
    def test_cross_venture_and_l4_escalation_e2e(self):
        d=scenario("e2e"); self.assertEqual(d["foreign"]["status"],"deny"); self.assertEqual(d["owner"]["status"],"owner_decision_required"); self.assertEqual(d["gate"]["safe_default"],"B")
if __name__=="__main__": unittest.main()
