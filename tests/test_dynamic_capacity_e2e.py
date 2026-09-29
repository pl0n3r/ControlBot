import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario():
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"dynamic_capacity_e2e_scenarios.php"),"lifecycle"],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class DynamicCapacityE2ETests(unittest.TestCase):
    def test_join_increases_observed_capacity_without_plan_constant(self):
        data=scenario()
        self.assertEqual(data["declared_capacity"],1)
        self.assertEqual(data["joined"]["accounts"][0]["observed_state"],"healthy")
        self.assertEqual(data["joined"]["idle_capacity"],4)
        self.assertGreater(data["joined"]["idle_capacity"],data["declared_capacity"])
        self.assertEqual(data["dispatch"]["joined"]["dispatchable_capacity"],1)

    def test_working_consumes_capacity(self):
        data=scenario()
        self.assertEqual(data["joined"]["idle_capacity"],4)
        self.assertEqual(data["working"]["idle_capacity"],3)
        self.assertEqual(data["working"]["sessions"][0]["state"],"working")
        self.assertEqual(data["working"]["sessions"][0]["assignment_id"],"assignment-217")

    def test_rate_limit_or_stale_reduces_capacity_and_preserves_work(self):
        data=scenario()
        for key in ("rate_limited","stale"):
            snapshot=data[key]
            self.assertEqual(snapshot["idle_capacity"],0)
            self.assertEqual(snapshot["capacity_state"],"degraded")
            self.assertEqual(snapshot["sessions"][0]["assignment_id"],"assignment-217")
            self.assertEqual(snapshot["sessions"][0]["work_item"],"pl0n3r/ControlBot#217")
            self.assertEqual(data["dispatch"][key]["dispatchable_capacity"],0)
            self.assertIn("authoritative_capacity_unavailable",data["dispatch"][key]["reasons"])

    def test_replan_increments_generation_without_duplicate_ownership(self):
        data=scenario()
        self.assertEqual(data["stale"]["sessions"][0]["generation"],1)
        self.assertEqual(data["recovered"]["sessions"][0]["generation"],2)
        self.assertEqual(data["handoff"]["from_session_id"],"session-a")
        self.assertEqual(data["handoff"]["to_session_id"],"session-b")
        self.assertEqual(data["current_owner_session_ids"],["session-b"])

    def test_stale_generation_recovery_is_rejected_and_current_recovery_recomputes(self):
        data=scenario()
        self.assertFalse(data["old_recovery_guard"]["allowed"])
        self.assertIn("stale_generation",data["old_recovery_guard"]["reasons"])
        self.assertFalse(data["stale_current_guard"]["allowed"])
        self.assertIn("stale_generation",data["stale_current_guard"]["reasons"])
        self.assertTrue(data["current_recovery_guard"]["allowed"])
        self.assertTrue(data["current_recovery_guard"]["recompute"])
        self.assertEqual(data["current_stale"]["idle_capacity"],0)
        self.assertEqual(data["recovered"]["idle_capacity"],3)
        self.assertEqual(data["dispatch"]["recovered"]["dispatchable_capacity"],1)

    def test_full_simulation_is_deterministic_and_secret_free(self):
        first=scenario()
        second=scenario()
        self.assertEqual(first,second)
        serialized=json.dumps(first,sort_keys=True).lower()
        for forbidden in (
            "transcript","password","passwd","token:","secret:","cookie:",
            "authorization:","private_key","chain-of-thought","@example.",
        ):
            self.assertNotIn(forbidden,serialized)


if __name__=="__main__":
    unittest.main()
