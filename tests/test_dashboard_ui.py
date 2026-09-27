import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests/dashboard_ui_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return result.stdout


class DashboardUiTests(unittest.TestCase):
    def test_dashboard_has_four_real_or_empty_domains(self):
        html = render("empty")
        for domain, label in [
            ("production", "Producción"),
            ("work", "Trabajo"),
            ("security", "Seguridad"),
            ("costs", "Costos"),
        ]:
            self.assertIn(f'data-domain="{domain}"', html)
            self.assertIn(f"<h2>{label}</h2>", html)
        self.assertEqual(html.count("Sin evidencia disponible."), 4)

        real = render("real")
        self.assertIn("abc123", real)
        self.assertIn("issues activos", real)

    def test_empty_domain_array_renders_empty_state(self):
        html = render("empty-domain")
        self.assertIn('data-domain="production"', html)
        self.assertEqual(html.count("Sin evidencia disponible."), 4)

    def test_health_core_fails_closed_to_unknown(self):
        empty = render("empty")
        invalid = render("invalid-health")
        self.assertIn("health-unknown", empty)
        self.assertIn(">desconocido<", empty)
        self.assertIn("health-unknown", invalid)
        self.assertIn(">desconocido<", invalid)

    def test_dashboard_does_not_invent_operational_values(self):
        html = render("empty")
        self.assertNotIn("99.9%", html)
        self.assertNotIn("12 agentes", html)
        self.assertNotIn("$0.00", html)
        self.assertEqual(html.count("Sin evidencia disponible."), 4)

    def test_dashboard_uses_canonical_sober_visual_contract(self):
        html = render("empty")
        for token in [
            "--bg: #0a0e13",
            "--panel: #10161d",
            "--cyan: #7cc9dd",
            "--green: #6fcf9e",
            "--amber: #e3a857",
            "--red: #e0666f",
        ]:
            self.assertIn(token, html)
        self.assertNotIn("text-shadow", html)
        self.assertNotIn("box-shadow", html)
        self.assertNotIn("scanline", html)
        self.assertIn('class="brand-mark">CONTROLBOT</span>', html)

    def test_dashboard_is_mobile_first_and_reduced_motion_safe(self):
        html = render("empty")
        self.assertIn('name="viewport"', html)
        self.assertIn(".dashboard-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }", html)
        self.assertIn("@media (min-width: 760px)", html)
        self.assertIn("@media (prefers-reduced-motion: reduce)", html)
        self.assertIn(":focus-visible", html)

    def test_dashboard_escapes_untrusted_content(self):
        html = render("escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertNotIn("<img src=x onerror=alert(1)>", html)


if __name__ == "__main__":
    unittest.main()
