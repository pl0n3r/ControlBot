import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(
        ["php",str(ROOT/"tests"/"pause_resume_plan_scenarios.php"),name],
        cwd=ROOT,text=True,capture_output=True,
    )
    if r.returncode or r.stderr.strip():
        raise AssertionError(r.stderr.strip() or r.stdout.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class PauseResumePlanTests(unittest.TestCase):
    def test_release_reuses_exact_current_workitem_order_attempt_and_generation(self):
        data=scenario("valid")
        self.assertEqual(data["work_item_id"],"work-a")
        self.assertEqual(data["order_id"],"11111111-1111-4111-8111-111111111111")
        self.assertEqual(data["attempt_id"],"22222222-2222-4222-8222-222222222222")
        self.assertEqual((data["generation"],data["attempt"]),(4,2))
        self.assertFalse(data["create_work_item"])
        self.assertFalse(data["create_order"])
        self.assertTrue(data["reuse_current_attempt"])

    def test_work_order_or_pause_identity_drift_fails_closed(self):
        data=scenario("drift")
        self.assertTrue(all(data.values()),data)

    def test_non_active_unowned_or_unreleased_work_cannot_resume(self):
        data=scenario("state")
        self.assertTrue(all(data.values()),data)

    def test_resume_replay_is_idempotent_and_conflict_fails_closed(self):
        data=scenario("replay")
        self.assertTrue(data["exact"])
        self.assertTrue(data["conflict"])

    def test_resume_plan_never_creates_work_or_order_and_has_no_external_io(self):
        data=scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint"])
        self.assertEqual(data["hits"],[])

if __name__=="__main__":
    unittest.main()
