import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/github_gateway_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)


class GitHubGatewayTests(unittest.TestCase):
    def test_factory_release_requests_are_exact(self):
        calls = scenario("exact")["calls"]
        self.assertEqual([row[0] for row in calls], ["GET", "POST", "PATCH", "PATCH", "POST"])
        self.assertEqual(calls[0][1], "https://api.github.com/repos/pl0n3r/factory/branches/main")
        self.assertEqual(calls[1][1], "https://api.github.com/repos/pl0n3r/factory/issues/137/comments")
        self.assertEqual(json.loads(calls[2][3]), {"state": "closed"})
        self.assertEqual(
            json.loads(calls[3][3]),
            {"sha": "a" * 40, "force": True},
        )
        self.assertEqual(
            json.loads(calls[4][3]),
            {"ref": "main", "inputs": {"expected_sha": "a" * 40, "gate_issue": "137"}},
        )
        headers = "\n".join(calls[0][2])
        self.assertIn("Authorization: Bearer ghp_test_only_token", headers)

    def test_transport_rejects_untrusted_destination(self):
        errors = scenario("destination")["errors"]
        self.assertEqual(len(errors), 4)
        self.assertTrue(all("no permitid" in error.lower() for error in errors))


if __name__ == "__main__":
    unittest.main()
