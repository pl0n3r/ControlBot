import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "scheduler_requeue_plan_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class SchedulerRequeuePlanTests(unittest.TestCase):
    def test_requeue_requires_real_stale_presence_transition(self):
        data = scenario("transition")
        self.assertEqual(data["valid"]["version"], 1)
        self.assertTrue(data["no_change"])
        self.assertTrue(data["leave"])
        self.assertTrue(data["recovery"])

    def test_requeue_respects_generation_and_safe_point_guard(self):
        data = scenario("guard")
        self.assertTrue(data["stale_generation"])
        self.assertTrue(data["unsafe_preemption"])
        self.assertTrue(data["safe_non_preemptible"])

    def test_owner_assignment_reservation_and_handoff_must_match(self):
        data = scenario("ownership")
        self.assertTrue(all(data.values()), data)

    def test_requeue_increments_generation_attempt_and_releases_owner_once(self):
        data = scenario("requeue")
        self.assertFalse(data["release_reservation"]["active"])
        self.assertEqual(data["release_reservation"]["generation"], 4)
        self.assertEqual(data["work_item"]["state"], "queued")
        self.assertIsNone(data["work_item"]["reservation_id"])
        self.assertIsNone(data["work_item"]["assigned_session_id"])
        self.assertEqual(data["fence"]["previous_generation"], 4)
        self.assertEqual(data["fence"]["next_generation"], 5)
        self.assertEqual(data["fence"]["previous_attempt"], 2)
        self.assertEqual(data["fence"]["next_attempt"], 3)
        self.assertIsNone(data["handoff"]["to_session_id"])

    def test_previous_owner_generation_cannot_complete_requeued_work(self):
        data = scenario("owner_guard")
        self.assertTrue(data["before"]["allowed"])
        self.assertFalse(data["old_after"]["allowed"])
        self.assertIn("stale_generation", data["old_after"]["reasons"])
        self.assertIn("work_unowned", data["old_after"]["reasons"])
        self.assertFalse(data["new_generation_unowned"]["allowed"])
        self.assertIn("work_unowned", data["new_generation_unowned"]["reasons"])

    def test_requeue_plan_is_deterministic_and_external_io_free(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["same_fingerprint"])
        self.assertEqual(data["hits"], [])


if __name__ == "__main__":
    unittest.main()
