import subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def render(name):
 p=subprocess.run(["php",str(ROOT/"tests"/"incident_ui_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True);return p.stdout
class IncidentUiTests(unittest.TestCase):
 def test_projection_uses_existing_incident_contracts_without_reclassification(self):
  html=render("valid");self.assertIn("Causa raíz",html);self.assertIn("Bug independiente",html);self.assertIn("MTTR 110 s",html)
 def test_timeline_and_postmortem_render_without_secrets(self):
  html=render("valid");self.assertIn("Timeline",html);self.assertIn("Mitigación / recovery",html);self.assertIn("Fix permanente",html);self.assertNotIn("password",html.lower())
 def test_unknown_evidence_keeps_owner_action_required_visible(self):
  html=render("unknown");self.assertIn("OWNER ACTION REQUIRED",html);self.assertIn("Evidencia pendiente",html)
 def test_untrusted_content_is_escaped_and_sensitive_material_rejected(self):
  html=render("escape");self.assertNotIn("<script>alert(1)</script>",html);self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;",html);self.assertIn('"rejected":true',render("secret"))
 def test_mobile_accessibility_and_read_only_contract(self):
  html=render("valid");self.assertIn('name="viewport"',html);self.assertIn(":focus-visible",html);self.assertIn("prefers-reduced-motion",html);self.assertNotIn("<form",html.lower());self.assertNotIn("<button",html.lower())
 def test_incident_78_preserves_causal_categories_and_serial_recovery(self):
  html=render("incident78")
  for text in ("Private Actions capacity was exhausted","Coordination caller lacked checks write permission","Hourly coordination fan out","Production observer cadence moved","Canario #86 → cola serial 72 → #77 → #80 → #71 → #84 → #73 → #75"):self.assertIn(text,html)
if __name__=="__main__":unittest.main()
