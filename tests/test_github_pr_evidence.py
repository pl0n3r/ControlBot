import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/github_pr_evidence_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class GitHubPrEvidenceTests(unittest.TestCase):
    def test_open_prs_include_bounded_mergeability_and_review_evidence(self):
        normal = scenario("normal")
        evidence = normal["evidence"]

        self.assertEqual(evidence["version"], 1)
        self.assertEqual(evidence["repository"], "pl0n3r/ControlBot")
        self.assertEqual(evidence["observed_at"], 200)
        self.assertFalse(evidence["truncated"])
        self.assertEqual([item["number"] for item in evidence["items"]], [7, 8])
        self.assertEqual(evidence["items"][0]["mergeability"]["state"], "MERGEABLE")
        self.assertEqual(evidence["items"][1]["mergeability"]["state"], "CONFLICTING")
        self.assertEqual(
            [item["reviews"]["evidence_state"] for item in evidence["items"]],
            ["COMPLETE", "COMPLETE"],
        )
        self.assertTrue(all(item["reviews"]["items"][0]["head_matches"] for item in evidence["items"]))
        self.assertTrue(all(item["source_ref"].startswith("github:pl0n3r/ControlBot#pull:") for item in evidence["items"]))

        for method, url, _headers, body in normal["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/pl0n3r/ControlBot/"))
        self.assertIn("state=open&per_page=25", normal["calls"][0][1])
        self.assertTrue(
            all(
                "per_page=25" in url
                for _method, url, _headers, _body in normal["calls"]
                if url.endswith("/reviews?per_page=25")
            )
        )

        bounded = scenario("bounded")["evidence"]
        self.assertTrue(bounded["truncated"])
        self.assertEqual(len(bounded["items"]), 25)

    def test_ambiguous_mergeability_or_partial_reviews_fail_closed_as_unknown(self):
        evidence = scenario("ambiguous")["evidence"]
        first = evidence["items"][0]
        second = evidence["items"][1]

        self.assertEqual(first["mergeability"]["state"], "UNKNOWN")
        self.assertIsNone(first["mergeability"]["mergeable"])
        self.assertEqual(first["reviews"]["evidence_state"], "UNKNOWN")
        self.assertIsNone(first["reviews"]["items"][0]["commit_id"])
        self.assertIsNone(first["reviews"]["items"][0]["head_matches"])

        self.assertEqual(second["mergeability"]["state"], "CONFLICTING")
        self.assertEqual(second["reviews"]["evidence_state"], "COMPLETE")


if __name__ == "__main__":
    unittest.main()
