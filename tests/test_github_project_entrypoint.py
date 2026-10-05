import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "github_project_entrypoint_scenarios.php"
SOURCE = ROOT / "src" / "GitHubProjectEntrypoint.php"


def scenario(name):
    run = subprocess.run(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class GitHubProjectEntrypointTests(unittest.TestCase):
    def test_exact_project_route_renders_existing_project_ui_from_same_projection(self):
        data = scenario("exact")
        current = data["alpha"]
        self.assertEqual(current["status"], 200)
        self.assertEqual(current["headers"]["Content-Type"], "text/html; charset=utf-8")
        self.assertIn("alpha-project", current["body"])
        self.assertIn("pl0n3r/Alpha", current["body"])
        self.assertNotIn("beta-project", current["body"])
        self.assertNotIn("pl0n3r/Beta", current["body"])
        self.assertIn("state=current", current["body"])
        self.assertIn("freshness=current", current["body"])
        self.assertIn("freshness=stale", data["stale"]["body"])

    def test_unknown_or_malformed_project_never_leaks_other_project_evidence(self):
        data = scenario("closed")
        for key in ("unknown", "malformed", "duplicate", "invalid_projection"):
            self.assertEqual(data[key]["status"], 404)
            self.assertEqual(data[key]["body"], "Not found.\n")
            self.assertNotIn("Alpha", data[key]["body"])
            self.assertNotIn("Beta", data[key]["body"])
            self.assertNotIn("Shadow", data[key]["body"])
        self.assertEqual(data["wrong_owner"]["status"], 403)
        self.assertEqual(data["wrong_method"]["status"], 405)
        self.assertEqual(data["wrong_method"]["headers"]["Allow"], "GET")

        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "api.github.com", "curl_", "apiclient", "workflow_dispatch",
            "'post'", "'put'", "'patch'", "'delete'",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("githubprojectview::project", source)
        self.assertIn("githubprojectui::render", source)


if __name__ == "__main__":
    unittest.main()
