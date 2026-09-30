import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def render(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"prompt_ui_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=60)
    return run.stdout

class PromptUiTests(unittest.TestCase):
    def test_projection_uses_existing_prompt_contracts(self):
        html=render("projection"); pure=json.loads(render("pure"))
        self.assertIn("support-agent",html); self.assertIn("v3 · approved",html); self.assertIn("v4 · candidate",html)
        self.assertEqual(pure["hits"],[]); self.assertTrue(json.loads(render("tamper"))["rejected"]); self.assertTrue(json.loads(render("binding"))["rejected"])

    def test_version_metrics_and_evaluation_set_render(self):
        html=render("projection")
        for text in ("support-v1 · v1","Sample size: <strong>40</strong>","acceptance_rate","rework_rate","0.8","0.9","evaluation set fingerprint","decision fingerprint"):
            self.assertIn(text,html)

    def test_promotion_hold_and_human_gate_render_without_action(self):
        eligible,hold=render("projection"),render("hold")
        for text in ("Elegible para aprobación humana","evaluation_supports_candidate","human_gate_required = true"): self.assertIn(text,eligible)
        for text in ("Mantener versión actual","insufficient_evidence"): self.assertIn(text,hold)
        for tag in ("<form","<button","<input"): self.assertNotIn(tag,eligible.lower())

    def test_unknown_evidence_remains_fail_closed(self):
        for name,reason in (("hold","insufficient_evidence"),("unsafe","candidate_safety_or_policy_failed")):
            html=render(name); self.assertIn('data-decision="hold"',html); self.assertIn(reason,html); self.assertNotIn("Elegible para aprobación humana",html)

    def test_untrusted_content_is_escaped_and_secrets_rejected(self):
        pure=json.loads(render("pure")); self.assertTrue(pure["escaper"]); self.assertTrue(json.loads(render("secret"))["rejected"])

    def test_mobile_accessibility_and_read_only_contract(self):
        html=render("projection")
        for text in ('name="viewport"','aria-label="Resumen del prompt"',":focus-visible","prefers-reduced-motion","Proyección read-only"): self.assertIn(text,html)
        for tag in ("<form","<button","<input"): self.assertNotIn(tag,html.lower())

    def test_version_metrics_and_promotion_reason_render(self):
        html=render("projection")
        for text in ("Historial inmutable","v1","approved","deprecated","support-v1","acceptance_rate","evaluation_supports_candidate","Targets previos","target aprobado previo"):
            self.assertIn(text,html)

if __name__=="__main__": unittest.main()
