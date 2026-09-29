import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"staff_control_plane_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode: raise AssertionError(r.stderr)
    return json.loads(r.stdout)
class StaffControlPlaneTests(unittest.TestCase):
    def test_summary_contains_only_staff_and_admin_accounts(self):
        d=scenario("summary"); self.assertEqual(d["valid"]["population"],"staff_only"); self.assertTrue(d["customer_boundary"])
        self.assertEqual((d["valid"]["active_staff"],d["valid"]["suspended_staff"]),(2,1))
    def test_search_is_on_demand_masked_and_not_persisted_as_directory(self):
        d=scenario("search"); v=d["valid"]; self.assertFalse(v["directory_persisted"]); self.assertFalse(d["raw_email_leaked"])
        self.assertEqual(v["records"][0]["masked_email"],"a***@example.com")
        self.assertNotIn("email",v["records"][0])
        self.assertNotIn("private-name",d["quoted_masked"]); self.assertTrue(d["quoted_masked"].endswith("@example.com"))
    def test_customer_records_or_ambiguous_staff_boundary_fail_closed(self):
        self.assertTrue(all(scenario("invalid").values()))
    def test_invitation_requires_passkey_and_product_keeps_activation_secret(self):
        d=scenario("invite"); self.assertEqual(d["ok"]["status"],"applied"); self.assertTrue(d["mfa_rejected"]); self.assertTrue(d["role_rejected"])
        self.assertNotIn("token",d["serialized"].lower()); self.assertNotIn("password",d["serialized"].lower())
    def test_mutations_are_typed_scoped_and_idempotent(self):
        d=scenario("mutations"); rows=d["rows"]; self.assertTrue(all(r["status"]=="applied" and r["mutations"]==1 and r["scope"]=="project:controlbot" and r["idempotency_key"]==r["expected"] for r in rows)); self.assertTrue(d["payload_keys_distinct"])
    def test_ambiguous_mutation_reconciles_before_retry(self):
        d=scenario("ambiguous"); self.assertEqual((d["resolved"]["status"],d["mutations"],d["lookups"]),("applied",1,2)); self.assertEqual(d["unknown"]["status"],"unknown_outcome"); self.assertEqual(d["unknown_mutations"],1)
    def test_agents_cannot_invoke_owner_staff_actions(self):
        self.assertTrue(scenario("authority")["rejected"])
    def test_dual_audit_is_secret_free_and_minimized(self):
        d=scenario("audit"); self.assertTrue(d["secret_free"]); self.assertEqual(len(d["entries"]),2)
        a=d["result"]["audit"]; self.assertTrue(a["controlbot_audit_ref"].startswith("controlbot:audit/staff/")); self.assertTrue(a["product_audit_ref"].startswith("product:audit/"))
        self.assertEqual(set(a),{"controlbot_audit_ref","product_audit_ref","project_id","action","intent_id","outcome","occurred_at"})
        self.assertTrue(d["gateway_error_caught"]); self.assertEqual(len(d["gateway_error_entries"]),2); self.assertEqual(d["gateway_error_entries"][-1]["result"],"unknown_outcome")
    def test_privacy_map_and_generated_docs_cover_staff_directory(self):
        data=json.loads((ROOT/"datos.yml").read_text())
        ids={row["id"] for row in data["treatments"]}; self.assertIn("staff_directory_contact",ids); self.assertIn("staff_directory_identity",ids)
        self.assertEqual(data["d063_attestation"],{"nothing_live":True,"no_real_customer_data":True})
        for name in ("politica-tratamiento.md","aviso-privacidad.md","registro-tratamientos.md","retencion.md"):
            text=(ROOT/"docs"/"privacidad"/name).read_text(); self.assertIn("staff_directory_identity",text); self.assertIn("staff_directory_contact",text)
if __name__=="__main__": unittest.main()
