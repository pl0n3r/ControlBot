import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "scheduler_policy_guard_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return json.loads(run.stdout)


class SchedulerPolicyGuardTests(unittest.TestCase):
    def test_protected_selection_can_request_preemption_of_lower_priority_work(self):
        data = scenario("preemption")
        safe = data["safe"]
        self.assertTrue(safe["allowed"])
        self.assertEqual(safe["policy_ref"], "factory-dispatcher-v2")
        self.assertEqual(safe["selected_work_item_id"], "work-incident")
        self.assertEqual(safe["running_work_item_id"], "work-running")
        self.assertEqual(safe["reasons"], [])

    def test_non_preemptible_requires_safe_point_and_current_generation(self):
        data = scenario("preemption")
        self.assertFalse(data["blocked"]["allowed"])
        self.assertIn(
            "non_preemptible_outside_safe_point",
            data["blocked"]["reasons"],
        )
        self.assertTrue(data["safe"]["allowed"])
        self.assertFalse(data["stale"]["allowed"])
        self.assertIn("stale_generation", data["stale"]["reasons"])

    def test_weekly_focus_cannot_outrank_ready_incident_or_critical_work(self):
        data = scenario("focus")
        self.assertTrue(data["critical_rejected"])
        self.assertTrue(data["incident_rejected"])
        self.assertEqual(
            data["protected_selected"]["selected_key"],
            "work-critical",
        )
        self.assertTrue(data["protected_selected"]["focus"]["influenced"])

    def test_focus_never_changes_selection_readiness(self):
        data = scenario("blocked_focus")
        self.assertEqual(data["selected_key"], "work-high")
        self.assertEqual(data["ready_ids"], ["work-high"])
        self.assertEqual(data["excluded_ids"], ["work-critical"])
        self.assertEqual(data["protected_ready_ids"], [])

    def test_policy_trace_and_workitem_drift_fail_closed(self):
        data = scenario("drift")
        self.assertTrue(all(data.values()), data)

    def test_policy_guard_is_deterministic_and_external_io_free(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint_same"])
        self.assertEqual(data["hits"], [])


if __name__ == "__main__":
    unittest.main()
