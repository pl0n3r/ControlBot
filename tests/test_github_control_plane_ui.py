#!/usr/bin/env python3
"""Cobertura de UI read-only del Control Plane sobre la proyección PHP real."""
from __future__ import annotations

import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PIPELINE = runpy.run_path(str(ROOT / "tests" / "test_github_control_plane.py"))
project = PIPELINE["project"]
project_snapshot = PIPELINE["project_snapshot"]
render = PIPELINE["render"]


def global_navigation() -> str:
    script = r'''
require "src/ControlCenterShell.php";
echo \ControlBot\Ui\ControlCenterShell::render(
    "GitHub", "github",
    ["github" => "/github", "projects" => "/projects"],
    "<section aria-label=\"Estado GitHub de solo lectura\">UNKNOWN / STALE</section>"
);
'''
    result = subprocess.run(
        ["php", "-r", script], cwd=ROOT, capture_output=True, text=True,
        check=True, timeout=30,
    )
    return result.stdout


class GitHubControlPlaneUiTests(unittest.TestCase):
    def test_project_and_global_views_work_on_mobile_and_desktop(self):
        snapshot = project_snapshot()
        snapshot["repositories"][1]["pull_requests"]["items"][0]["title"] = (
            "<img src=x onerror=alert(1)>"
        )
        snapshot["repositories"][1]["issues"]["items"][0]["title"] = (
            "<script>bad()</script>"
        )
        normalized = project(snapshot)
        html = render(normalized)
        for name in ("pl0n3r/ControlBot", "pl0n3r/Factory"):
            self.assertIn(name, html)
        for required in (
            'name="viewport"',
            "grid-template-columns:1fr",
            "@media(min-width:760px)",
            "grid-template-columns:repeat(2,minmax(0,1fr))",
            "prefers-reduced-motion:reduce",
            "EVIDENCIA ACTUAL",
            "Main", "Checks", "Pull requests", "Issues", "Release",
        ):
            self.assertIn(required, html)
        self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
        self.assertIn("&lt;script&gt;bad()&lt;/script&gt;", html)
        self.assertNotIn("<script>bad()</script>", html)
        self.assertNotIn("<img src=x onerror=alert(1)>", html)
        for forbidden in ("<form", "<button", 'method="post"'):
            self.assertNotIn(forbidden, html.lower())

        global_html = global_navigation()
        self.assertIn('data-nav="github"', global_html)
        self.assertIn('href="/github"', global_html)
        self.assertIn('aria-current="page"', global_html)
        self.assertIn('name="viewport"', global_html)
        self.assertIn("flex-wrap: wrap", global_html)
        self.assertIn("UNKNOWN / STALE", global_html)
        self.assertNotIn("<form", global_html.lower())

        denied = render(normalized, "permission_denied")
        self.assertIn("SIN PERMISO", denied)
        self.assertNotIn("pl0n3r/Factory", denied)
        stale = render(project(project_snapshot(observed_at=500)))
        self.assertIn("UNKNOWN / STALE", stale)


if __name__ == "__main__":
    unittest.main()
