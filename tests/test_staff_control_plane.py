import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"staff_control_plane_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)
class StaffControlPlaneTests(unittest.TestCase):
    def test_summary_contains_only_staff_and_admin_accounts(self):
        d=scenario("summary"); self.assertEqual(d["valid"]["active_staff"],2); self.assertTrue(d["ambiguous_rejected"])
    def test_search_is_on_demand_masked_and_not_persisted_as_directory(self):
        d=scenario("search"); self.assertEqual(d["stateful_properties"],0); self.assertFalse(d["contains_raw_email"]); self.assertTrue(all("*" in r["masked_email"] for r in d["result"]["records"]))
    def test_invitation_requires_passkey_and_product_keeps_activation_secret(self):
        d=scenario("invite"); self.assertEqual(d["ok"]["status"],"applied"); self.assertTrue(d["mfa_rejected"]); self.assertTrue(d["role_rejected"]); self.assertNotIn("token",d["serialized"].lower())
    def test_mutations_are_typed_scoped_and_idempotent(self):
        rows=scenario("mutations"); self.assertTrue(all(r["status"]=="applied" and r["mutations"]==1 and r["scope"]=="project:alpha" and r["idempotency_key"].startswith("idem_") for r in rows))
    def test_ambiguous_mutation_reconciles_before_retry(self):
        d=scenario("ambiguous"); self.assertEqual((d["resolved"]["status"],d["mutations"],d["lookups"]),("applied",1,2)); self.assertEqual(d["unknown"]["status"],"unknown_outcome"); self.assertEqual(d["unknown_mutations"],1)
    def test_customer_records_or_ambiguous_staff_boundary_fail_closed(self):
        d=scenario("boundary"); self.assertTrue(d["customer_rejected"]); self.assertTrue(d["mixed_rejected"])
    def test_agents_cannot_invoke_owner_staff_actions(self):
        d=scenario("agent"); self.assertTrue(d["rejected"]); self.assertEqual(d["mutations"],0)
    def test_dual_audit_is_secret_free_and_minimized(self):
        d=scenario("audit"); self.assertTrue(d["has_both"]); self.assertTrue(d["secret_free"]); self.assertEqual(len(d["entries"]),2); self.assertEqual(d["entries"][1]["evidence"],d["result"]["audit"]["product_audit_ref"]); self.assertEqual(set(d["result"]["audit"]),{"controlbot_audit_ref","product_audit_ref","project_id","action","intent_id","outcome","occurred_at"})
if __name__=="__main__": unittest.main()
