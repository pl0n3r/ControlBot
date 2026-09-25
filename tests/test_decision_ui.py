import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests/decision_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout


class DecisionUiTests(unittest.TestCase):
    def test_mobile_first_approval_controls(self):
        html = render("ready")
        self.assertIn('name="viewport"', html)
        self.assertIn('content="width=device-width,initial-scale=1,viewport-fit=cover"', html)
        self.assertIn("min-height: 52px", html)
        self.assertIn(":focus-visible", html)
        self.assertIn("@media (prefers-reduced-motion: reduce)", html)
        self.assertIn("@media (min-width: 760px)", html)
        self.assertIn("CONTROLBOT / DECISIONES", html)

    def test_reauth_guard_disables_actions(self):
        html = render("reauth")
        self.assertIn("Reautenticación requerida", html)
        self.assertIn('data-state="reauth-required"', html)
        self.assertGreaterEqual(html.count('disabled aria-disabled="true"'), 2)

    def test_form_targets_approval_endpoint(self):
        html = render("ready")
        self.assertIn('method="post" action="/approvals/execute"', html)
        self.assertIn('name="repository" value="pl0n3r/factory"', html)
        self.assertIn('name="issue" value="114"', html)
        self.assertIn('name="displayed_sha" value="' + ("a" * 40) + '"', html)
        self.assertIn('name="option" value="A"', html)
        self.assertNotIn('disabled aria-disabled="true"', html)

    def test_empty_state_has_no_fake_decision(self):
        html = render("empty")
        self.assertIn('data-state="empty"', html)
        self.assertIn("Sin decisiones pendientes", html)
        self.assertNotIn("/approvals/execute", html)
        self.assertNotIn("pl0n3r/factory", html)

    def test_enriched_card_shows_plain_language_and_impact(self):
        html = render("enriched")
        self.assertIn("¿Publicar la versión 1.0.4?", html)
        self.assertIn("Por qué se recomienda:", html)
        self.assertIn("Si no decides:", html)
        self.assertIn("Trabajo en espera:", html)
        self.assertIn("Riesgo medio", html)
        self.assertIn("Costo: Sin costo adicional", html)
        self.assertIn("Reversible", html)
        self.assertIn("✓ Ventajas", html)
        self.assertIn("✗ Desventajas", html)
        self.assertIn("<summary>Ver detalles técnicos</summary>", html)
        self.assertIn('action="/approvals/execute"', html)

    def test_enriched_fields_escape_untrusted_html(self):
        html = render("enriched-escape")
        self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)

    def test_invalid_risk_fails_closed(self):
        result = subprocess.run(
            ["php", str(ROOT / "tests/decision_ui_scenarios.php"), "invalid-risk"],
            cwd=ROOT, text=True, capture_output=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("onerror=", result.stdout)

    def test_untrusted_copy_is_escaped(self):
        html = render("escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)


if __name__ == "__main__":
    unittest.main()
