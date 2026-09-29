import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "scheduler_assignment_plan_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class SchedulerAssignmentPlanTests(unittest.TestCase):
    def test_selection_must_match_current_workitem_exactly(self):
        data = scenario("selection")
        self.assertEqual(data["valid"]["work_item"]["work_item_id"], "work-a")
        for field, value in data["rejected"].items():
            self.assertTrue(value, (field, data))

    def test_target_session_and_agent_must_be_eligible_and_capable(self):
        data = scenario("target")
        self.assertEqual(data["valid"]["session"]["status"], "assigned")
        for key in ("stale", "busy", "account", "agent_identity", "capability"):
            self.assertTrue(data[key], (key, data))

    def test_plan_binds_reservation_workitem_and_assignment_to_same_generation(self):
        data = scenario("binding")
        self.assertEqual(data["reservation"]["owner_session_id"], data["session"]["session_id"])
        self.assertEqual(data["work_item"]["assigned_session_id"], data["session"]["session_id"])
        self.assertEqual(data["assignment"]["session_id"], data["session"]["session_id"])
        self.assertEqual(data["reservation"]["generation"], data["work_item"]["generation"])
        self.assertEqual(data["work_item"]["state"], "assigned")
        self.assertEqual(data["assignment"]["status"], "assigned")
        self.assertEqual(data["session"]["status"], "assigned")
        self.assertEqual(data["required_capabilities"], ["php", "review"])
        self.assertEqual(data["work_item"]["attempt"], 1)

    def test_existing_ownership_or_stale_state_fails_closed_without_takeover(self):
        data = scenario("ownership")
        for key in ("reserved", "assigned", "occupied", "stale_generation"):
            self.assertTrue(data[key], (key, data))

    def test_plan_and_cas_fingerprints_are_deterministic_and_state_bound(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint_same"])
        self.assertTrue(data["work_cas_changed"])
        self.assertTrue(data["session_cas_changed"])

    def test_assignment_plan_has_no_persistence_runner_or_generation_mutation(self):
        data = scenario("pure")
        self.assertTrue(data["pure"], data)
        self.assertEqual(data["hits"], [])
        self.assertTrue(data["generation_preserved"])
        self.assertTrue(data["attempt_preserved"])


if __name__ == "__main__":
    unittest.main()
