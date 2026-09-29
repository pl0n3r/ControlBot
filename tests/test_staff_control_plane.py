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
    def test_customer_records_or_ambiguous_staff_boundary_fail_closed(self):
        self.assertTrue(all(scenario("invalid").values()))
    def test_privacy_map_and_generated_docs_cover_staff_directory(self):
        data=json.loads((ROOT/"datos.yml").read_text())
        ids={row["id"] for row in data["treatments"]}; self.assertIn("staff_directory_contact",ids); self.assertIn("staff_directory_identity",ids)
        self.assertEqual(data["d063_attestation"],{"nothing_live":True,"no_real_customer_data":True})
        for name in ("politica-tratamiento.md","aviso-privacidad.md","registro-tratamientos.md","retencion.md"):
            text=(ROOT/"docs"/"privacidad"/name).read_text(); self.assertIn("staff_directory_identity",text); self.assertIn("staff_directory_contact",text)
if __name__=="__main__": unittest.main()
