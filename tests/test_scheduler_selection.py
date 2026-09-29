import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "scheduler_selection_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class SchedulerSelectionTests(unittest.TestCase):
    def test_request_accepts_only_factory_dispatcher_candidates(self):
        data = scenario("request")
        self.assertEqual(data["valid"]["policy_ref"], "factory-dispatcher-v2")
        self.assertEqual(len(data["valid"]["fingerprint"]), 64)
        self.assertTrue(data["wrong_policy"])
        self.assertTrue(data["extra"])
        self.assertTrue(data["duplicate"])

    def test_request_fingerprint_is_input_order_independent(self):
        data = scenario("order")
        self.assertTrue(data["same"])
        self.assertEqual(
            [row["key"] for row in data["one"]["candidates"]],
            ["work-a", "work-b"],
        )

    def test_decision_fails_closed_on_policy_fingerprint_key_or_readiness_drift(self):
        data = scenario("drift")
        self.assertEqual(data["valid"]["selected_key"], "work-a")
        for key in ("wrong_policy", "wrong_fingerprint", "unknown_key", "blocked_key", "readiness_drift"):
            self.assertTrue(data[key], (key, data))

    def test_decision_cannot_expand_generation_capabilities_or_authority(self):
        data = scenario("authority")
        self.assertEqual(data["validated"]["selected"]["generation"], data["candidate"]["generation"])
        self.assertEqual(
            data["validated"]["selected"]["required_capabilities"],
            data["candidate"]["required_capabilities"],
        )
        self.assertTrue(data["generation_rejected"])
        self.assertTrue(data["capabilities_rejected"])
        self.assertTrue(data["authority_rejected"])
        self.assertNotIn("authority", data["validated"]["selected"])

    def test_selection_reason_and_telemetry_are_deterministic_and_safe(self):
        data = scenario("telemetry")
        self.assertTrue(data["same"])
        self.assertEqual(data["validated"]["telemetry"]["ready_not_selected"], ["work-b"])
        self.assertEqual(set(data["validated"]["telemetry"]["excluded"]), {"work-c"})
        self.assertTrue(data["fabricated"])
        self.assertTrue(data["secret"])
        self.assertTrue(data["pii"])

    def test_selection_adapter_has_no_local_ranking_or_external_io(self):
        data = scenario("pure")
        self.assertTrue(data["pure"], data)
        self.assertEqual(data["hits"], [])


if __name__ == "__main__":
    unittest.main()
