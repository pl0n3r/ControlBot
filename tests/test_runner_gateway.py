import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "runner_gateway_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)


class RunnerGatewayTests(unittest.TestCase):
    def test_runner_identity_roundtrip_and_rejects_invalid_contract(self):
        data = scenario("identity")
        self.assertTrue(data["stable"])
        self.assertEqual(data["identity"]["version"], 1)
        self.assertEqual(data["identity"]["runner_id"], "runner_001")
        self.assertEqual(
            data["identity"]["capabilities"], ["test_php", "review_code"]
        )
        self.assertTrue(all(data["rejects"].values()), data["rejects"])

    def test_heartbeat_health_is_deterministic_and_fail_closed(self):
        data = scenario("heartbeat")
        states = {key: val["status"] for key, val in data["readings"].items()}
        self.assertEqual(states["fresh"], "healthy")
        self.assertEqual(states["stale"], "stale")
        for key in ["offline", "future", "missing", "paused"]:
            self.assertEqual(states[key], "offline")
            self.assertFalse(data["readings"][key]["eligible"])
            self.assertEqual(data["readings"][key]["free_capacity"], 0)
        self.assertEqual(data["readings"]["full"]["status"], "healthy")
        self.assertFalse(data["readings"]["full"]["eligible"])
        self.assertTrue(all(data["rejects"].values()), data["rejects"])

    def test_stale_runner_preserves_assignment_identity(self):
        rows = scenario("heartbeat")["readings"]
        for key in ["stale", "offline", "future", "missing", "paused"]:
            self.assertEqual(rows[key]["runner_id"], "runner_001")
            self.assertEqual(rows[key]["assignment_ids"], ["work_001"])
            self.assertEqual(rows[key]["free_capacity"], 0)

    def test_contract_is_location_agnostic(self):
        result = scenario("portable")
        self.assertTrue(result["same"])
        self.assertEqual(result["health"]["free_capacity"], 2)


if __name__ == "__main__":
    unittest.main()
