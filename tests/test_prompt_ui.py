import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "prompt_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return run.stdout


class PromptUiTests(unittest.TestCase):
    def test_projection_uses_existing_prompt_contracts(self):
        html = render("projection")
        self.assertIn("support-agent", html)
        self.assertIn("v3 · approved", html)
        self.assertIn("v4 · candidate", html)
        self.assertEqual(json.loads(render("pure"))["hits"], [])
        self.assertTrue(json.loads(render("tamper"))["rejected"])
        self.assertTrue(json.loads(render("binding"))["rejected"])

    def test_version_metrics_and_evaluation_set_render(self):
        html = render("projection")
        self.assertIn("support-v1 · v1", html)
        self.assertIn("Sample size: <strong>40</strong>", html)
        self.assertIn("acceptance_rate", html)
        self.assertIn("rework_rate", html)
        self.assertIn("0.8", html)
        self.assertIn("0.9", html)
        self.assertIn("evaluation set fingerprint", html)
        self.assertIn("decision fingerprint", html)

    def test_promotion_hold_and_human_gate_render_without_action(self):
        eligible = render("projection")
        hold = render("hold")
        self.assertIn("Elegible para aprobación humana", eligible)
        self.assertIn("evaluation_supports_candidate", eligible)
        self.assertIn("human_gate_required = true", eligible)
        self.assertIn("Mantener versión actual", hold)
        self.assertIn("insufficient_evidence", hold)
        self.assertNotIn("<form", eligible.lower())
        self.assertNotIn("<button", eligible.lower())

    def test_unknown_evidence_remains_fail_closed(self):
        insufficient = render("hold")
        unsafe = render("unsafe")
        self.assertIn('data-decision="hold"', insufficient)
        self.assertIn("insufficient_evidence", insufficient)
        self.assertIn('data-decision="hold"', unsafe)
        self.assertIn("candidate_safety_or_policy_failed", unsafe)
        self.assertNotIn("Elegible para aprobación humana", insufficient)
        self.assertNotIn("Elegible para aprobación humana", unsafe)

    def test_untrusted_content_is_escaped_and_secrets_rejected(self):
        untrusted = json.loads(render("untrusted"))
        secret = json.loads(render("secret"))
        self.assertTrue(untrusted["escaper"])
        self.assertTrue(untrusted["rejected"])
        self.assertTrue(secret["rejected"])

    def test_mobile_accessibility_and_read_only_contract(self):
        html = render("projection")
        self.assertIn('name="viewport"', html)
        self.assertIn('aria-label="Resumen del prompt"', html)
        self.assertIn(":focus-visible", html)
        self.assertIn("prefers-reduced-motion", html)
        self.assertIn("Proyección read-only", html)
        self.assertNotIn("<form", html.lower())
        self.assertNotIn("<button", html.lower())
        self.assertNotIn("<input", html.lower())

    def test_version_metrics_and_promotion_reason_render(self):
        html = render("projection")
        for text in (
            "Historial inmutable",
            "v1",
            "approved",
            "deprecated",
            "support-v1",
            "acceptance_rate",
            "evaluation_supports_candidate",
            "Targets previos",
            "target aprobado previo",
        ):
            self.assertIn(text, html)


if __name__ == "__main__":
    unittest.main()
