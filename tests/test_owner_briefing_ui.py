import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "owner_briefing_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout


class OwnerBriefingUiTests(unittest.TestCase):
    def test_briefing_renders_all_sections_and_attention_mobile_first(self):
        html = render("attention")
        for label in (
            "Entregado ayer",
            "Hoy",
            "Roto / atención",
            "Decisiones",
            "Costos",
        ):
            self.assertIn(label, html)

        self.assertIn("Necesitas entrar", html)
        self.assertIn("Budget dashboard integrado", html)
        self.assertIn("Ver evidencia", html)
        self.assertIn("controlbot:issue/102", html)
        self.assertIn("Sin novedades.", html)
        self.assertIn(
            'name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"',
            html,
        )
        self.assertIn(
            ".briefing-grid { display: grid; grid-template-columns: 1fr;",
            html,
        )
        self.assertIn("@media (min-width: 760px)", html)
        self.assertIn("@media (prefers-reduced-motion: reduce)", html)
        self.assertNotIn("<script", html.lower())

        quiet = render("quiet")
        self.assertIn("No necesitas entrar", quiet)
        self.assertIn("Sin novedades.", quiet)

        unknown = render("unknown")
        self.assertIn("Fuente no disponible.", unknown)
        self.assertIn("Necesitas entrar", unknown)

    def test_untrusted_content_is_escaped_and_mobile_safe(self):
        html = render("escape")
        self.assertNotIn('<script>alert("briefing")</script>', html)
        self.assertIn(
            "&lt;script&gt;alert(&quot;briefing&quot;)&lt;/script&gt;",
            html,
        )
        self.assertIn("min-width: 0", html)
        self.assertIn("overflow-wrap: anywhere", html)
        self.assertIn(":focus-visible", html)


if __name__ == "__main__":
    unittest.main()
