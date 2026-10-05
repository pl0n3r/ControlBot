import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "control_center_shell_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return result.stdout


class ControlCenterShellTests(unittest.TestCase):
    def test_shell_renders_canonical_owner_first_navigation_and_active_section(self):
        html = render("canonical")
        expected = [
            ("overview", "Resumen", "/overview"),
            ("projects", "Proyectos", "/projects"),
            ("accounts", "Cuentas", "/accounts"),
            ("agents", "Agentes", "/agents"),
            ("work", "Trabajo", "/work"),
            ("factory-live", "Fábrica viva", "/factory-live"),
            ("github", "GitHub", "/github"),
            ("decisions", "Decisiones", "/decisions"),
        ]
        for key, label, route in expected:
            self.assertIn(f'data-nav="{key}"', html)
            self.assertIn(f'href="{route}"', html)
            self.assertIn(f">{label}<", html)
        self.assertIn('data-section="work"', html)
        self.assertIn('data-nav="work" href="/work" aria-current="page"', html)

    def test_missing_extra_or_invalid_routes_fail_closed_without_fake_links(self):
        html = render("invalid-routes")
        self.assertIn('data-nav="overview" href="/overview" aria-current="page"', html)
        self.assertIn('data-nav="work" href="/work"', html)
        for key in ("projects", "accounts", "agents", "factory-live", "github", "decisions"):
            self.assertIn(f'data-nav="{key}" aria-disabled="true"', html)
        self.assertNotIn("evil.example", html)
        self.assertNotIn("javascript:", html)
        self.assertNotIn("should-never-render", html)
        self.assertNotIn('data-nav="extra"', html)

    def test_dashboard_uses_shell_and_preserves_existing_domain_and_health_contract(self):
        html = render("dashboard")
        self.assertIn('data-section="overview"', html)
        for domain in ("production", "work", "security", "costs"):
            self.assertIn(f'data-domain="{domain}"', html)
        self.assertIn("health-unknown", html)
        self.assertIn(">desconocido<", html)
        self.assertIn("abc123", html)
        self.assertIn("issues activos", html)
        source = (ROOT / "src" / "DashboardUi.php").read_text(encoding="utf-8")
        self.assertIn("ControlCenterShell::render", source)

    def test_shell_is_read_only_escaped_mobile_and_accessible(self):
        html = render("escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn('name="viewport"', html)
        self.assertIn("flex-wrap: wrap", html)
        self.assertIn("focus-visible", html)
        self.assertIn("prefers-reduced-motion: reduce", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", 'method="post"', "<script"):
            self.assertNotIn(forbidden, lowered)

        source = (ROOT / "src" / "ControlCenterShell.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "file_get_contents(", "mysqli", "pdo(", "fetch(", "javascript:"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
