import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "project_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return result.stdout


class ProjectUiTests(unittest.TestCase):
    def test_list_renders_normalized_projects_without_parallel_state(self):
        html = render("list")
        self.assertIn("Proyectos", html)
        self.assertIn("Condor", html)
        self.assertIn("ControlBot", html)
        self.assertIn("1 repos · 1 entornos", html)
        source = (ROOT / "src" / "ProjectUi.php").read_text(encoding="utf-8")
        self.assertIn("ProjectModel::normalize", source)
        self.assertNotIn("private const PHASES", source)
        self.assertLess(
            html.index('data-project="project-condor"'),
            html.index('data-project="project-controlbot"'),
        )

    def test_detail_preserves_repository_environment_and_aggregate_provenance(self):
        html = render("detail")
        for expected in (
            "pl0n3r/ControlBot",
            "controlbot:environment/project-controlbot/prod",
            "Roadmap",
            "Agentes",
            "Decisiones",
            "Salud",
            "Incidentes",
            "Costos",
            "observed_at 1002",
            "observed_at 1007",
        ):
            self.assertIn(expected, html)
        self.assertIn('href="https://github.com/pl0n3r/ControlBot"', html)
        self.assertIn('href="https://github.com/pl0n3r/ControlBot/issues/1"', html)

    def test_missing_aggregate_is_unknown_and_internal_refs_never_become_fake_links(self):
        missing = render("missing")
        self.assertEqual(missing.count("UNKNOWN · Sin referencia."), 6)
        detail = render("detail")
        self.assertIn("controlbot:health/project-controlbot", detail)
        self.assertNotIn('href="controlbot:', detail)

    def test_untrusted_project_content_is_escaped(self):
        html = render("escape")
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)

    def test_ui_is_mobile_first_accessible_and_read_only(self):
        html = render("detail")
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        self.assertIn("@media(min-width:760px)", html)
        self.assertIn("prefers-reduced-motion:reduce", html)
        self.assertIn("focus-visible", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", "method=\"post\"", "<script"):
            self.assertNotIn(forbidden, lowered)
        source = (ROOT / "src" / "ProjectUi.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "file_get_contents(", "mysqli", "pdo(", "dispatchworkflow"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
