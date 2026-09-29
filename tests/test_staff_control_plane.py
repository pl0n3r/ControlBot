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
    def test_privacy_map_and_generated_docs_cover_staff_directory(self):
        data=json.loads((ROOT/"datos.yml").read_text())
        ids={row["id"] for row in data["treatments"]}; self.assertIn("staff_directory_contact",ids); self.assertIn("staff_directory_identity",ids)
        self.assertEqual(data["d063_attestation"],{"nothing_live":True,"no_real_customer_data":True})
        for name in ("politica-tratamiento.md","aviso-privacidad.md","registro-tratamientos.md","retencion.md"):
            text=(ROOT/"docs"/"privacidad"/name).read_text(); self.assertIn("staff_directory_identity",text); self.assertIn("staff_directory_contact",text)
    def test_invitation_requires_passkey_and_product_keeps_activation_secret(self):
        d=scenario("invite"); i=d["intent"]
        self.assertEqual((i["action"],i["requested_role"]),("staff.invite","admin"))
        self.assertFalse(i["execution"]); self.assertTrue(d["role_reject"]); self.assertTrue(d["secret_reject"])
        self.assertTrue(i["idempotency_key"].startswith("staff-action:"))

    def test_mutations_are_typed_scoped_and_idempotent(self):
        d=scenario("mutations")
        for action,row in d.items():
            self.assertEqual(row["typed"]["action"],action); self.assertEqual(row["typed"]["project_id"],"controlbot")
            self.assertTrue(row["stable"]); self.assertFalse(row["typed"]["execution"])

    def test_ambiguous_mutation_reconciles_before_retry(self):
        d=scenario("ambiguous")
        self.assertEqual(d["unknown"]["state"],"unknown"); self.assertFalse(d["unknown"]["retry_allowed"]); self.assertTrue(d["unknown"]["reconciliation_required"])
        self.assertEqual(d["applied"]["state"],"applied"); self.assertFalse(d["applied"]["retry_allowed"])
        self.assertEqual(d["not_found"]["state"],"not_found"); self.assertTrue(d["not_found"]["retry_allowed"])

    def test_agents_cannot_invoke_owner_staff_actions(self):
        self.assertTrue(all(scenario("authority").values()))

    def test_dual_audit_is_secret_free_and_minimized(self):
        d=scenario("audit"); a=d["result"]["audit"]; s=d["serialized"].lower()
        self.assertIn("controlbot_audit_ref",a); self.assertIn("product_audit_ref",a)
        self.assertNotIn("staff_id_or_invitee_ref",a)
        for value in ("password","activation_token","recovery_code","credential","@"): self.assertNotIn(value,s)

if __name__=="__main__": unittest.main()
