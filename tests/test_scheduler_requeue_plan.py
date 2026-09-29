import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"scheduler_requeue_plan_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class SchedulerRequeuePlanTests(unittest.TestCase):
    def test_requeue_requires_real_stale_presence_transition(self):
        d=scenario("transition")
        self.assertEqual(d["valid"]["version"],1)
        self.assertTrue(all(d[k] for k in ("no_change","leave","recovery")))

    def test_requeue_respects_generation_and_safe_point_guard(self):
        d=scenario("guard")
        self.assertTrue(d["stale_generation"]); self.assertTrue(d["unsafe_preemption"]); self.assertTrue(d["safe_non_preemptible"])

    def test_owner_assignment_reservation_and_handoff_must_match(self):
        self.assertTrue(all(scenario("ownership").values()))

    def test_requeue_increments_generation_attempt_and_releases_owner_once(self):
        d=scenario("requeue")
        self.assertFalse(d["release_reservation"]["active"]); self.assertEqual(d["release_reservation"]["generation"],4)
        self.assertEqual(d["work_item"]["state"],"queued"); self.assertIsNone(d["work_item"]["reservation_id"]); self.assertIsNone(d["work_item"]["assigned_session_id"])
        self.assertEqual((d["fence"]["previous_generation"],d["fence"]["next_generation"]),(4,5))
        self.assertEqual((d["fence"]["previous_attempt"],d["fence"]["next_attempt"]),(2,3))
        self.assertIsNone(d["handoff"]["to_session_id"])

    def test_previous_owner_generation_cannot_complete_requeued_work(self):
        d=scenario("owner_guard")
        self.assertTrue(d["before"]["allowed"]); self.assertFalse(d["old_after"]["allowed"])
        self.assertIn("stale_generation",d["old_after"]["reasons"]); self.assertIn("work_unowned",d["old_after"]["reasons"])
        self.assertFalse(d["new_generation_unowned"]["allowed"]); self.assertIn("work_unowned",d["new_generation_unowned"]["reasons"])

    def test_requeue_plan_is_deterministic_and_external_io_free(self):
        d=scenario("deterministic")
        self.assertTrue(d["same"]); self.assertTrue(d["same_fingerprint"]); self.assertEqual(d["hits"],[])

if __name__=="__main__": unittest.main()
