import base64
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(view):
    encoded = base64.b64encode(json.dumps(view, separators=(",", ":")).encode()).decode()
    runner = r'''<?php
require __THEME__;
require __UI__;
$view = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo \ControlBot\GitHub\GitHubProjectUi::render($view);
'''
    runner = runner.replace("__THEME__", json.dumps(str((ROOT / "src" / "UiTheme.php").resolve())))
    runner = runner.replace("__UI__", json.dumps(str((ROOT / "src" / "GitHubProjectUi.php").resolve())))
    base = ROOT / "build" / "test-runners"
    base.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=base) as tempdir:
        script = Path(tempdir) / "github_project_ui.php"
        script.write_text(runner, encoding="utf-8")
        result = subprocess.run(["php", str(script), encoded], cwd=ROOT, text=True, capture_output=True, check=True, timeout=30)
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
            "pull_requests": {"items": [{"number": 616, "title": "Read-only view", "draft": False, "head_sha": "b" * 40, "base_ref": "main"}], "truncated": False},
            "issues": {"items": [{"number": 614, "title": "Project GitHub UI", "labels": ["estado: reservado"]}], "truncated": False},
            "latest_release": {"tag_name": "v0.1.0", "draft": False, "prerelease": False},
            "latest_workflow": {"name": "Validar", "status": "completed", "conclusion": "success", "head_sha": sha, "run_number": 1},
        }],
    }


class GitHubProjectUiTests(unittest.TestCase):
    def test_project_tab_renders_main_checks_prs_issues_release_and_workflow_with_source_and_freshness(self):
        html = render(project_view())
        for expected in (
            "factory-control", "pl0n3r/ControlBot", "https://github.com/pl0n3r/ControlBot",
            "CURRENT", "Main", "a" * 40, "Checks", "CI ControlBot",
            "Pull requests", "#616 Read-only view", "Issues", "#614 Project GitHub UI",
            "Release", "v0.1.0", "Último workflow", "Validar", "Truncado: no",
        ):
            self.assertIn(expected, html)

    def test_project_tab_is_mobile_owner_first_read_only_and_unknown_safe(self):
        view = project_view(state="unknown", freshness="stale", truncated=True)
        view["repositories"][0]["issues"]["items"][0]["title"] = "<script>alert(1)</script>"
        html = render(view)
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        self.assertIn("@media(min-width:760px)", html)
        self.assertIn("prefers-reduced-motion:reduce", html)
        self.assertLess(html.index('<section class="attention'), html.index('<section class="repo-grid'))
        self.assertIn("Truncado: sí · UNKNOWN", html)
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", "method=\"post\"", "merge pull", "dispatch workflow"):
            self.assertNotIn(forbidden, lowered)

        source = (ROOT / "src" / "GitHubProjectUi.php").read_text().lower()
        for forbidden in ("curl_", "file_get_contents(", "apiclient", "dispatchworkflow", "closeissue(", "mergepull"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
