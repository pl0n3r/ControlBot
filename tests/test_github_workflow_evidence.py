import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/github_workflow_evidence_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class GitHubWorkflowEvidenceTests(unittest.TestCase):
    def test_workflow_catalog_preserves_latest_run_sha_status_and_truncation(self):
        data = scenario("normal")
        evidence = data["evidence"]

        self.assertEqual(evidence["version"], 1)
        self.assertEqual(evidence["repository"], "pl0n3r/ControlBot")
        self.assertEqual(evidence["main_sha"], "a" * 40)
        self.assertEqual([item["id"] for item in evidence["items"]], [11, 12])

        completed = evidence["items"][0]["latest_run"]
        self.assertEqual(completed["evidence_state"], "COMPLETE")
        self.assertEqual(completed["freshness"], "CURRENT")
        self.assertEqual(completed["head_sha"], "a" * 40)
        self.assertEqual(completed["status"], "completed")
        self.assertEqual(completed["conclusion"], "success")
        self.assertTrue(completed["truncated"])
        self.assertIn("#workflow-run:1111@", completed["source_ref"])

        active = evidence["items"][1]["latest_run"]
        self.assertEqual(active["evidence_state"], "COMPLETE")
        self.assertEqual(active["status"], "in_progress")
        self.assertIsNone(active["conclusion"])

        for method, url, _headers, body in data["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/pl0n3r/ControlBot/"))

        bounded = scenario("bounded")["evidence"]
        self.assertEqual(len(bounded["items"]), 25)
        self.assertTrue(bounded["truncated"])

    def test_missing_or_ambiguous_workflow_run_is_unknown_not_green(self):
        evidence = scenario("missing-ambiguous")["evidence"]
        missing = evidence["items"][0]["latest_run"]
        ambiguous = evidence["items"][1]["latest_run"]

        self.assertEqual(missing["evidence_state"], "UNKNOWN")
        self.assertEqual(missing["freshness"], "MISSING")
        self.assertIsNone(missing["status"])
        self.assertIsNone(missing["conclusion"])

        self.assertEqual(ambiguous["evidence_state"], "UNKNOWN")
        self.assertEqual(ambiguous["freshness"], "AMBIGUOUS")
        self.assertIsNone(ambiguous["status"])
        self.assertIsNone(ambiguous["conclusion"])

        stale = scenario("stale")["evidence"]["items"][0]["latest_run"]
        self.assertEqual(stale["evidence_state"], "UNKNOWN")
        self.assertEqual(stale["freshness"], "STALE")
        self.assertEqual(stale["status"], "completed")
        self.assertEqual(stale["conclusion"], "success")
        self.assertNotEqual(stale["head_sha"], "a" * 40)


if __name__ == "__main__":
    unittest.main()
