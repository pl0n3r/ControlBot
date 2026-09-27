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
        self.assertEqual(data["identity"]["protocol_version"], 1)
        self.assertEqual(
            data["identity"]["runner_id"],
            "11111111-1111-7111-8111-111111111111",
        )
        self.assertEqual(data["identity"]["runtime"], "php")
        self.assertEqual(data["identity"]["placement"], "hostinger-shared")
        self.assertNotIn("location", data["identity"])
        self.assertEqual(
            data["identity"]["capabilities"], ["review-code", "test-php"]
        )
        self.assertEqual(data["identity"]["max_parallel"], 3)
        self.assertTrue(all(data["rejects"].values()), data["rejects"])

    def test_heartbeat_health_is_deterministic_and_fail_closed(self):
        data = scenario("heartbeat")
        states = {key: val["status"] for key, val in data["readings"].items()}
        self.assertEqual(states["fresh"], "healthy")
        self.assertEqual(states["stale"], "stale")
        for key in ["offline", "future", "missing"]:
            self.assertEqual(states[key], "offline")
            self.assertFalse(data["readings"][key]["eligible"])
            self.assertEqual(data["readings"][key]["free_capacity"], 0)
        self.assertEqual(states["draining"], "healthy")
        self.assertFalse(data["readings"]["draining"]["eligible"])
        self.assertEqual(data["readings"]["draining"]["free_capacity"], 0)
        self.assertEqual(data["readings"]["full"]["status"], "healthy")
        self.assertFalse(data["readings"]["full"]["eligible"])
        self.assertTrue(all(data["rejects"].values()), data["rejects"])
        self.assertTrue(all(data["ttl_rejects"].values()), data["ttl_rejects"])

    def test_stale_runner_preserves_assignment_identity(self):
        rows = scenario("heartbeat")["readings"]
        for key in ["stale", "offline", "future", "missing", "draining"]:
            self.assertEqual(
                rows[key]["runner_id"],
                "11111111-1111-7111-8111-111111111111",
            )
            self.assertEqual(rows[key]["assignment_ids"], ["work_001"])
            self.assertEqual(rows[key]["free_capacity"], 0)

    def test_contract_is_placement_agnostic(self):
        result = scenario("portable")
        self.assertTrue(result["same"])
        self.assertEqual(result["health"]["free_capacity"], 2)


if __name__ == "__main__":
    unittest.main()
