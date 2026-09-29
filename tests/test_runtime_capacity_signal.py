import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "runtime_capacity_signal_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


class RuntimeCapacitySignalTests(unittest.TestCase):
    def test_autofactory_and_factoryrunner_share_normalized_signal_contract(self):
        data = scenario("sources")
        self.assertTrue(data["same_keys"])
        self.assertEqual(data["auto"]["source"], "autofactory")
        self.assertEqual(data["runner"]["source"], "factoryrunner")
        self.assertEqual(data["auto"]["total_capacity"], data["runner"]["total_capacity"])

    def test_signal_rejects_transcript_credentials_and_chain_of_thought(self):
        data = scenario("secrets")
        self.assertTrue(all(data.values()), data)

    def test_degraded_provider_states_are_preserved_fail_closed(self):
        data = scenario("states")
        for state in ("rate_limited", "requires_login", "offline", "stale", "unknown"):
            self.assertEqual(data[state]["state"], state)
        self.assertIsNone(data["unknown"]["heartbeat_at"])
        self.assertIsNone(data["unknown"]["total_capacity"])
        self.assertIsNone(data["unknown"]["occupied_capacity"])

    def test_heartbeat_generation_and_attempt_are_explicit(self):
        data = scenario("clock")
        self.assertEqual(data["valid"]["heartbeat_at"], 995)
        self.assertEqual(data["valid"]["generation"], 4)
        self.assertEqual(data["valid"]["attempt"], 2)
        for key in ("future_heartbeat", "bad_generation", "bad_attempt", "over_occupied"):
            self.assertTrue(data[key], (key, data))

    def test_extra_fields_fail_closed(self):
        data = scenario("extra")
        self.assertTrue(data["extra"])
        self.assertTrue(data["missing"])

    def test_contract_has_no_provider_plan_concurrency_constants(self):
        data = scenario("contract")
        self.assertFalse(data["has_plan_constant"])
        self.assertNotIn("PLAN_CAPACITY", data["constant_names"])
        self.assertNotIn("MAX_SESSIONS", data["constant_names"])
        self.assertNotIn("plan", data["output"])


if __name__ == "__main__":
    unittest.main()
