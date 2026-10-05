import base64
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(view, surface_state="ready"):
    encoded = base64.b64encode(json.dumps(view, separators=(",", ":")).encode()).decode()
    runner = r'''<?php
require __THEME__;
require __UI__;
$view = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo \ControlBot\GitHub\GitHubProjectUi::render($view, $argv[2]);
'''
    runner = runner.replace("__THEME__", json.dumps(str((ROOT / "src" / "UiTheme.php").resolve())))
    runner = runner.replace("__UI__", json.dumps(str((ROOT / "src" / "GitHubProjectUi.php").resolve())))
    base = ROOT / "build" / "test-runners"
    base.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=base) as tempdir:
        script = Path(tempdir) / "github_project_ui.php"
        script.write_text(runner, encoding="utf-8")
        result = subprocess.run(["php", str(script), encoded, surface_state], cwd=ROOT, text=True, capture_output=True, check=True, timeout=30)
    return result.stdout


def project_view(state="current", freshness="current", truncated=False):
    sha = "a" * 40
    return {
        "version": 1,
        "project_id": "factory-control",
        "state": state,
        "observed_at": 1000,
        "freshness": freshness,
        "age_seconds": 10,
        "repositories": [{
            "repository_id": "controlbot",
            "repository": "pl0n3r/ControlBot",
            "source_ref": "https://github.com/pl0n3r/ControlBot",
            "observed_at": 1000,
            "freshness": freshness,
            "age_seconds": 10,
            "state": state,
            "main_sha": sha,
            "checks": {"items": [{"name": "CI ControlBot", "status": "completed", "conclusion": "success"}], "truncated": truncated},
            "pull_requests": {"items": [{"number": 616, "title": "Read-only view", "draft": False, "head_sha": "b" * 40, "base_ref": "main", "review_state": "approved", "mergeability": "mergeable"}], "truncated": False},
            "issues": {"items": [{"number": 614, "title": "Project GitHub UI", "labels": ["estado: reservado"]}], "truncated": False},
            "latest_release": {"tag_name": "v0.1.0", "draft": False, "prerelease": False},
            "latest_workflow": {"name": "Validar", "status": "completed", "conclusion": "success", "head_sha": sha, "run_number": 1},
        }],
    }


class GitHubProjectUiTests(unittest.TestCase):
    def test_project_github_view_is_mobile_read_only_and_owner_first(self):
        view = project_view(state="unknown", freshness="stale", truncated=True)
        view["repositories"][0]["issues"]["items"][0]["title"] = "<script>alert(1)</script>"
        html = render(view)
        for expected in (
            "factory-control", "pl0n3r/ControlBot", "https://github.com/pl0n3r/ControlBot",
            "Main", "a" * 40, "Checks", "CI ControlBot", "Pull requests",
            "#616 Read-only view", "Issues", "Release", "v0.1.0", "Último workflow",
            "UNKNOWN / STALE", "Truncado: sí · UNKNOWN",
        ):
            self.assertIn(expected, html)
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        self.assertIn("@media(min-width:760px)", html)
        self.assertIn("prefers-reduced-motion:reduce", html)
        self.assertLess(html.index('<section class="attention'), html.index('<section class="repo-grid'))
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", "method=\"post\"", "merge pull", "dispatch workflow"):
            self.assertNotIn(forbidden, lowered)
        source = (ROOT / "src" / "GitHubProjectUi.php").read_text().lower()
        for forbidden in ("curl_", "file_get_contents(", "apiclient", "dispatchworkflow", "closeissue(", "mergepull"):
            self.assertNotIn(forbidden, source)

    def test_empty_loading_error_and_permission_states_are_explicit(self):
        empty = project_view()
        empty["repositories"] = []
        self.assertIn("EMPTY · Sin repositorios", render(empty))
        self.assertIn("LOADING · Cargando evidencia GitHub.", render(project_view(), "loading"))
        self.assertIn("ERROR · No fue posible proyectar la evidencia GitHub.", render(project_view(), "error"))
        denied = render(project_view(), "permission_denied")
        self.assertIn("SIN PERMISO · La evidencia GitHub no está disponible", denied)
        self.assertNotIn("CI ControlBot", denied)

    def test_pr_review_visibility_is_read_only_mobile_and_escapes_untrusted_titles(self):
        view = project_view()
        view["repositories"][0]["pull_requests"]["items"][0].update({
            "title": "<img src=x onerror=alert(1)>",
            "review_state": "changes_requested",
            "mergeability": "conflicting",
        })
        html = render(view)
        self.assertIn("review changes_requested", html)
        self.assertIn("merge conflicting", html)
        self.assertNotIn("<img src=x onerror=alert(1)>", html)
        self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", 'method="post"', "merge pull", "dispatch workflow"):
            self.assertNotIn(forbidden, lowered)


if __name__ == "__main__":
    unittest.main()
