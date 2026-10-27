"""ControlBot #981: exact PHP read-only GitHub project UI on mobile/desktop.

This tests the rendered HTML and CSS contract at synthetic viewport widths; it
does not claim a real iPhone, browser engine, network or live authorization.
"""
from __future__ import annotations

from html.parser import HTMLParser
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/github_view_responsive_scenarios.php"
UI = ROOT / "src/GitHubProjectUi.php"
VIEWPORTS = ("390", "1280")


class Structure(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.headings: list[str] = []
        self.landmarks: list[str] = []
        self._h3 = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if tag == "h3":
            self._h3 = True
        if tag in {"main", "section", "article"}:
            self.landmarks.append(values.get("aria-label", ""))

    def handle_endtag(self, tag: str) -> None:
        if tag == "h3":
            self._h3 = False

    def handle_data(self, data: str) -> None:
        if self._h3:
            self.headings.append(data.strip())


def render(viewport: str, scenario: str = "full") -> str:
    result = subprocess.run(
        ["php", "-d", "allow_url_fopen=0", str(SCENARIOS), viewport, scenario],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(f"Synthetic GitHub UI scenario rejected: {scenario}")
    if result.stderr:
        raise AssertionError("Synthetic UI wrote to stderr")
    return result.stdout


class Cb55GithubMovilTests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        outputs = [render(width) for width in VIEWPORTS]
        # CSS, not a different or invented mobile renderer, controls layout.
        self.assertEqual(outputs[0], outputs[1])
        for html in outputs:
            self.assertTrue(html.startswith("<!doctype html>"))
            self.assertIn('name="viewport"', html)
            self.assertIn("width=device-width,initial-scale=1", html)
            self.assertIn("grid-template-columns:1fr", html)
            self.assertRegex(
                html, r"@media\s*\(min-width:760px\)"
                      r"\s*\{[^}]*\.shell\s*\{",
            )
            self.assertIn("repeat(2,minmax(0,1fr))", html)
            self.assertIn(":focus-visible", html)
            self.assertIn("prefers-reduced-motion:reduce", html)
            structure = Structure()
            structure.feed(html)
            for section in (
                "Main", "Checks", "Pull requests", "Issues",
                "Release", "Último workflow",
            ):
                self.assertIn(section, structure.headings)
            self.assertIn("Repositorios GitHub", structure.landmarks)
            for value in (
                "example/Fixture", "CI synthetic", "Synthetic pull",
                "Synthetic issue", "v0.0.0-fixture", "Validate fixture",
            ):
                self.assertIn(value, html)
            self.assertIn("EVIDENCIA ACTUAL", html)
            self.assertNotIn("<form", html.lower())
            self.assertNotIn("<button", html.lower())
            self.assertNotIn('method="post"', html.lower())

    def test_falla_cerrado(self) -> None:
        for width in VIEWPORTS:
            error = render(width, "error")
            denied = render(width, "permission_denied")
            loading = render(width, "loading")
            malformed = render(width, "malformed")
            self.assertIn("surface-error", error)
            self.assertIn("ERROR", error)
            self.assertIn("surface-permission_denied", denied)
            self.assertIn("SIN PERMISO", denied)
            self.assertIn("surface-loading", loading)
            self.assertIn("LOADING", loading)
            self.assertIn("attention-unknown", malformed)
            self.assertIn("UNKNOWN", malformed)
            for html in (error, denied, loading, malformed):
                self.assertNotIn("Synthetic pull", html)
                self.assertNotIn("v0.0.0-fixture", html)
        for width, name in (("390", "live"), ("0", "full"), ("999999", "full")):
            result = subprocess.run(
                ["php", str(SCENARIOS), width, name], cwd=ROOT,
                text=True, capture_output=True, timeout=30, check=False,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout, "")
            self.assertIn("Invalid synthetic", result.stderr)

    def test_sin_datos_reales(self) -> None:
        src = SCENARIOS.read_text(encoding="utf-8")
        self.assertTrue(UI.is_file())
        self.assertIn("GitHubProjectUi::render", src)
        for forbidden in (
            "curl_exec(", "file_get_contents(", "new ApiClient(",
            "getenv(", "Authorization:", "process.env", "ghp_", "gho_",
            "CONTROLBOT_GITHUB_TOKEN", "http.request(",
        ):
            self.assertNotIn(forbidden, src)
        for width in VIEWPORTS:
            html = render(width, "hostile")
            self.assertNotIn("<svg onload=alert(1)>", html)
            self.assertNotIn("<img src=x onerror=alert(1)>", html)
            self.assertIn("&lt;svg onload=alert(1)&gt;", html)
            self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
            self.assertNotIn("<script", html.lower())
            self.assertNotIn("Authorization: Bearer", html)
        self.assertNotIn("localhost:", render("390"))

    def test_estados_unknown_explicitos(self) -> None:
        for width in VIEWPORTS:
            empty = render(width, "empty")
            self.assertIn("surface-empty", empty)
            self.assertIn("EMPTY", empty)
            self.assertNotIn('<article class="repo ', empty)
            for scenario in ("stale", "truncated", "ambiguous"):
                html = render(width, scenario)
                self.assertIn("attention-unknown", html)
                self.assertIn("UNKNOWN / STALE", html)
                self.assertNotIn("EVIDENCIA ACTUAL", html)
                self.assertIn("synthetic-project", html)
            self.assertIn("STALE", render(width, "stale"))
            self.assertIn("review unknown", render(width, "ambiguous"))
            self.assertIn("merge unknown", render(width, "ambiguous"))
            self.assertIn("truncation", render(width, "truncated"))


if __name__ == "__main__":
    unittest.main()
