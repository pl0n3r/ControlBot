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


    def test_order_is_idempotent_and_rejects_secret_shaped_fields(self):
        data = scenario("order")
        self.assertTrue(data["stable"])
        self.assertTrue(data["fingerprint_same"])
        self.assertTrue(data["idempotent"])
        self.assertTrue(data["generation_conflict"])
        self.assertEqual(data["order"]["generation"], 1)
        self.assertEqual(
            data["order"]["attempt_id"],
            "44444444-4444-7444-8444-444444444444",
        )
        self.assertEqual(data["order"]["scope"], "repo:pl0n3r/ControlBot")
        self.assertTrue(all(data["rejects"].values()), data["rejects"])

    def test_event_state_machine_and_sanitized_evidence(self):
        data = scenario("event")
        self.assertEqual(data["completed"]["state"], "completed")
        self.assertEqual(data["completed"]["generation"], 1)
        self.assertEqual(data["next_owner"]["generation"], 2)
        self.assertEqual(
            data["next_owner"]["runner_id"],
            "99999999-9999-7999-8999-999999999999",
        )
        self.assertTrue(data["rejects"]["terminal_reopen"])
        self.assertTrue(data["rejects"]["sequence_gap"])
        self.assertTrue(data["rejects"]["stale_after_handoff"])
        self.assertFalse(data["rejects"]["new_owner_accepts"])
        for key in [
            "secret_summary",
            "evil_ref",
            "query_ref",
            "encoded_secret_ref",
            "backslash_ref",
            "dot_segment_ref",
        ]:
            self.assertTrue(data["rejects"][key], (key, data["rejects"]))

    def test_orders_and_events_are_placement_agnostic(self):
        data = scenario("portable-execution")
        self.assertTrue(data["same_order"])
        self.assertTrue(data["same_event"])
        self.assertTrue(data["identity_only_differs_in_placement"])


if __name__ == "__main__":
    unittest.main()
