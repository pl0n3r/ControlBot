import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/approval_endpoint_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)


class ApprovalEndpointTests(unittest.TestCase):
    def test_request_cannot_supply_identity_or_token(self):
        data = scenario("request")
        self.assertEqual(data["token"], "ghp_server_secret")
        self.assertEqual(data["source_calls"], [["pl0n3r/factory", 137]])
        rendered = json.dumps(data)
        self.assertNotIn("ghp_attacker", rendered)
        self.assertNotIn("attacker", rendered)

    def test_release_flows_through_session_gateway(self):
        data = scenario("flow")
        names = [row[0] for row in data["gateway_calls"]]
        self.assertEqual(names, ["mainSha", "commentIssue", "closeIssue", "moveTag", "dispatchWorkflow"])
        self.assertEqual(data["result"]["sha"], "a" * 40)
        self.assertGreaterEqual(len(data["audit"]), 4)


if __name__ == "__main__":
    unittest.main()
