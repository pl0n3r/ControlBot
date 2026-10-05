import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "github_shell_e2e_scenarios.php"
ROUTER = ROOT / "src" / "ControlBotWebEntrypoint.php"
PUBLIC_INDEX = ROOT / "public" / "index.php"


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


class GitHubShellE2ETests(unittest.TestCase):
    def test_global_and_project_github_navigation_is_mobile_accessible_and_read_only(self):
        data = scenario("surfaces")
        for key in ("global", "project"):
            response = data[key]
            self.assertEqual(response["status"], 200)
            html = response["body"]
            self.assertEqual(html.count("<!doctype html>"), 1)
            self.assertEqual(html.count("<html"), 1)
            self.assertIn('data-section="github"', html)
            self.assertIn('data-nav="github" href="/github" aria-current="page"', html)
            self.assertIn('data-nav="overview" href="/overview"', html)
            self.assertIn('name="viewport"', html)
            self.assertIn("focus-visible", html)
            self.assertIn("prefers-reduced-motion", html)
            lowered = html.lower()
            for forbidden in ("<form", "<button", 'method="post"', "<script"):
                self.assertNotIn(forbidden, lowered)

        self.assertIn("Evidencia GitHub por proyecto", data["global"]["body"])
        self.assertIn("alpha-project", data["global"]["body"])
        self.assertIn("CONTROLBOT / GITHUB / PROJECT", data["project"]["body"])
        self.assertIn("pl0n3r/Alpha", data["project"]["body"])

        delegated = data["delegated"]
        self.assertEqual(delegated["status"], 200)
        self.assertIn("application/json", delegated["headers"]["Content-Type"])
        self.assertTrue(json.loads(delegated["body"])["read_only"])

    def test_no_github_mutation_or_secret_surface_is_reachable(self):
        data = scenario("guards")
        self.assertEqual(data["wrong_owner"]["status"], 403)
        self.assertEqual(data["wrong_method"]["status"], 405)
        self.assertEqual(data["unknown_project"]["status"], 404)
        for response in data.values():
            self.assertNotIn("pl0n3r/Alpha", response["body"])

        source = (ROUTER.read_text(encoding="utf-8") + PUBLIC_INDEX.read_text(encoding="utf-8")).lower()
        for forbidden in (
            "api.github.com",
            "curl_",
            "workflow_dispatch",
            "merge_pull",
            "close_issue",
            "authorization:",
            "password=",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("factoryorchestratorwebentrypoint::handle", source)
        self.assertIn("githubpublicentrypoint::handle", source)
        self.assertIn("githubprojectentrypoint::handle", source)
        self.assertIn("controlcentershell::render", source)


if __name__ == "__main__":
    unittest.main()
