import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "github_public_entrypoint_scenarios.php"
SOURCE = ROOT / "src" / "GitHubPublicEntrypoint.php"


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


class GitHubPublicEntrypointTests(unittest.TestCase):
    def test_owner_get_github_renders_existing_global_view_from_local_projection(self):
        data = scenario("owner")
        owner = data["owner"]
        self.assertEqual(owner["status"], 200)
        self.assertEqual(owner["headers"]["Content-Type"], "text/html; charset=utf-8")
        self.assertEqual(owner["headers"]["Cache-Control"], "no-store")
        for expected in (
            "Evidencia GitHub por proyecto",
            "factory-control",
            "pl0n3r/ControlBot",
            "state=current",
            "freshness=current",
        ):
            self.assertIn(expected, owner["body"])
        self.assertEqual(data["wrong_owner"]["status"], 403)
        self.assertEqual(data["wrong_method"]["status"], 405)
        self.assertEqual(data["wrong_method"]["headers"]["Allow"], "GET")
        self.assertNotIn("factory-control", data["wrong_owner"]["body"])

    def test_missing_stale_or_invalid_projection_is_unknown_without_github_io(self):
        data = scenario("unknown")
        for key in ("missing", "invalid"):
            self.assertEqual(data[key]["status"], 200)
            self.assertIn("state=unknown", data[key]["body"])
            self.assertIn("freshness=unknown", data[key]["body"])
            self.assertNotIn("factory-control", data[key]["body"])
        self.assertEqual(data["stale"]["status"], 200)
        self.assertIn("factory-control", data["stale"]["body"])
        self.assertIn("state=unknown", data["stale"]["body"])
        self.assertIn("freshness=stale", data["stale"]["body"])

        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "api.github.com",
            "curl_",
            "apiclient",
            "gateway::",
            "workflow_dispatch",
            "'post'",
            "'put'",
            "'patch'",
            "'delete'",
            "token",
            "password",
            "secret",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("file_get_contents($path)", source)
        self.assertIn("githubprojectview::project", source)
        self.assertIn("githubglobalui::render", source)


if __name__ == "__main__":
    unittest.main()
