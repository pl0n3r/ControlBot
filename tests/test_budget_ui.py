import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "budget_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout


class BudgetUiTests(unittest.TestCase):
    def test_dashboard_renders_owner_project_headroom_without_secrets(self):
        html = render("real")
        self.assertIn("github_actions", html)
        self.assertIn("pl0n3r", html)
        self.assertIn("private_minutes", html)
        self.assertIn("crítico", html)
        self.assertIn("1800 / 2000", html)
        self.assertIn("90%", html)
        self.assertIn("1440", html)
        self.assertIn("Headroom</span><strong>200</strong>", html)
        self.assertIn("2026-10-01", html)
        self.assertIn("FactoryRunner", html)
        self.assertIn("pl0n3r/FactoryRunner", html)
        self.assertIn("cuenta hacia el scope", html)
        self.assertIn("fuera del scope", html)
        self.assertNotIn("github_api", html)
        self.assertNotIn(">source<", html.lower())

        unknown = render("unknown")
        self.assertIn('class="state state-unknown">unknown</strong>', unknown)
        self.assertIn("unknown / unknown", unknown)
        self.assertIn("Sin atribuciones disponibles.", unknown)

        escaped = render("escape")
        self.assertNotIn('<script>alert("budget")</script>', escaped)
        self.assertIn("&lt;script&gt;alert(&quot;budget&quot;)&lt;/script&gt;", escaped)

        self.assertIn(
            'name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"',
            html,
        )
        self.assertNotIn("<script", html.lower())


if __name__ == "__main__":
    unittest.main()
