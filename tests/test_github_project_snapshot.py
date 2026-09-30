import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "github_project_snapshot_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class GitHubProjectSnapshotTests(unittest.TestCase):
    def test_snapshot_uses_project_repositories_and_get_only_allowlisted_routes(self):
        data = scenario("routes")
        repos = data["snapshot"]["repositories"]
        self.assertEqual([row["repository"] for row in repos], ["pl0n3r/ControlBot", "pl0n3r/Factory"])
        self.assertEqual(data["snapshot"]["observed_at"], 200)
        self.assertEqual(len(data["calls"]), 12)
        for method, url, _headers, body in data["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/"))

    def test_snapshot_normalizes_main_checks_prs_issues_release_and_workflow(self):
        row = scenario("normalize")["snapshot"]["repositories"][0]
        self.assertEqual(row["main_sha"], "a" * 40)
        self.assertEqual(
            row["checks"]["items"],
            [
                {"name": "CI ControlBot", "status": "completed", "conclusion": "success"},
                {"name": "Security", "status": "in_progress", "conclusion": None},
            ],
        )
        self.assertEqual(row["pull_requests"]["items"][0]["number"], 7)
        self.assertEqual([item["number"] for item in row["issues"]["items"]], [9])
        self.assertEqual(row["issues"]["items"][0]["labels"], ["prioridad: alta"])
        self.assertEqual(row["latest_release"]["tag_name"], "v0.1.20")
        self.assertEqual(
            row["latest_workflow"],
            {
                "name": "CI ControlBot",
                "status": "completed",
                "conclusion": "success",
                "head_sha": "a" * 40,
                "run_number": 123,
            },
        )

    def test_malformed_or_ambiguous_github_state_fails_closed(self):
        blocked = scenario("invalid")["blocked"]
        self.assertEqual(
            blocked,
            {"bad-sha": True, "bad-check": True, "bad-workflow": True, "project": True},
        )

    def test_lists_are_bounded_and_release_absence_is_explicit(self):
        data = scenario("bounded")
        row = data["bounded"]
        self.assertTrue(row["checks"]["truncated"])
        self.assertTrue(row["pull_requests"]["truncated"])
        self.assertTrue(row["issues"]["truncated"])
        self.assertEqual(len(row["checks"]["items"]), 100)
        self.assertEqual(len(row["pull_requests"]["items"]), 100)
        self.assertEqual(len(row["issues"]["items"]), 100)
        self.assertIsNone(data["no_release"])

    def test_projection_has_no_write_or_secret_surface(self):
        source = (ROOT / "src" / "GitHubProjectSnapshot.php").read_text(encoding="utf-8")
        upper = source.upper()
        for verb in ("'POST'", "'PATCH'", "'PUT'", "'DELETE'"):
            self.assertNotIn(verb, upper)
        lower = source.lower()
        for forbidden in ("dispatchworkflow", "movetag(", "closeissue(", "commentissue(", "token", "password", "secret"):
            self.assertNotIn(forbidden, lower)
        self.assertIn("'observed_at' => $observedAt", source)


if __name__ == "__main__":
    unittest.main()
