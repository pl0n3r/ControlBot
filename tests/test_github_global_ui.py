import base64
import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(projects):
    encoded = base64.b64encode(json.dumps(projects, separators=(",", ":")).encode()).decode()
    runner = r'''<?php
require __SOURCE__;
$projects = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo \ControlBot\GitHub\GitHubGlobalUi::render($projects);
'''
    runner = runner.replace(
        "__SOURCE__",
        json.dumps(str((ROOT / "src" / "GitHubGlobalUi.php").resolve())),
    )
    runner_root = ROOT / "build" / "test-runners"
    runner_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=runner_root) as tempdir:
        script = Path(tempdir) / "github_global_ui.php"
        script.write_text(runner, encoding="utf-8")
        run = subprocess.run(
            ["php", str(script), encoded],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=True,
            timeout=30,
        )
    return run.stdout


def projected_project(project_id="factory-control", state="current", freshness="current", truncated=False):
    sha = "a" * 40
    return {
        "version": 1,
        "project_id": project_id,
        "state": state,
        "observed_at": 900,
        "freshness": freshness,
        "age_seconds": 100,
        "repositories": [
            {
                "repository_id": "controlbot",
                "repository": "pl0n3r/ControlBot",
                "source_ref": "https://github.com/pl0n3r/ControlBot",
                "observed_at": 900,
                "freshness": freshness,
                "age_seconds": 100,
                "state": state,
                "main_sha": sha,
                "checks": {"items": [{"name": "CI"}], "truncated": False},
                "pull_requests": {"items": [{"number": 7}], "truncated": False},
                "issues": {"items": [{"number": 9}], "truncated": truncated},
                "latest_release": None,
                "latest_workflow": None,
            }
        ],
    }


class GitHubGlobalUiTests(unittest.TestCase):
    def test_global_view_reuses_project_projection_without_parallel_state(self):
        current = projected_project()
        stale = projected_project(
            project_id="factory-product",
            state="unknown",
            freshness="stale",
            truncated=True,
        )

        html = render([current, stale])

        self.assertIn("factory-control", html)
        self.assertIn("factory-product", html)
        self.assertIn("pl0n3r/ControlBot", html)
        self.assertIn("state=unknown", html)
        self.assertIn("freshness=stale", html)
        self.assertIn("truncated=true", html)
        self.assertNotIn("healthy", html.lower())
        self.assertNotIn(">green<", html.lower())

        source = (ROOT / "src" / "GitHubGlobalUi.php").read_text()
        lower = source.lower()
        self.assertNotIn("githubprojectsnapshot", lower)
        self.assertNotIn("file_get_contents", lower)
        self.assertNotIn("curl_", lower)
        self.assertNotIn("api.github.com", lower)

    def test_links_are_evidence_navigation_only_and_never_actions(self):
        html = render([projected_project()])
        links = re.findall(r'href="([^"]+)"', html)

        self.assertEqual(links, ["https://github.com/pl0n3r/ControlBot"])
        self.assertNotIn("<form", html.lower())
        self.assertNotIn("<button", html.lower())
        self.assertNotIn("method=", html.lower())

        source = (ROOT / "src" / "GitHubGlobalUi.php").read_text().lower()
        for token in (
            "workflow_dispatch",
            "/merge",
            "mergepull",
            "closeissue",
            "commentissue",
            "'post'",
            "'put'",
            "'patch'",
            "'delete'",
        ):
            self.assertNotIn(token, source)


if __name__ == "__main__":
    unittest.main()
