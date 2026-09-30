import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"scheduler_policy_guard_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=60)
    return json.loads(r.stdout)

class SchedulerPolicyGuardTests(unittest.TestCase):
    def test_protected_selection_can_request_preemption_of_lower_priority_work(self):
        d=scenario("preemption"); s=d["safe"]
        self.assertTrue(s["allowed"]); self.assertEqual((s["policy_ref"],s["selected_work_item_id"],s["running_work_item_id"],s["reasons"]),("factory-dispatcher-v2","work-incident","work-running",[]))
    def test_non_preemptible_requires_safe_point_and_current_generation(self):
        d=scenario("preemption")
        self.assertFalse(d["blocked"]["allowed"]); self.assertIn("non_preemptible_outside_safe_point",d["blocked"]["reasons"]); self.assertTrue(d["safe"]["allowed"]); self.assertFalse(d["stale"]["allowed"]); self.assertIn("stale_generation",d["stale"]["reasons"]); self.assertFalse(d["higher"]["allowed"]); self.assertIn("running_priority_not_lower",d["higher"]["reasons"])
    def test_weekly_focus_cannot_outrank_ready_incident_or_critical_work(self):
        d=scenario("focus")
        self.assertTrue(d["critical_rejected"]); self.assertTrue(d["incident_rejected"]); self.assertEqual(d["protected_selected"]["selected_key"],"work-critical"); self.assertTrue(d["protected_selected"]["focus"]["influenced"])
    def test_focus_never_changes_selection_readiness(self):
        d=scenario("blocked_focus")
        self.assertEqual((d["selected_key"],d["ready_ids"],d["excluded_ids"],d["protected_ready_ids"]),("work-high",["work-high"],["work-critical"],[]))
    def test_policy_trace_and_workitem_drift_fail_closed(self):
        d=scenario("drift"); self.assertTrue(all(d.values()),d)
    def test_policy_guard_is_deterministic_and_external_io_free(self):
        d=scenario("deterministic"); self.assertTrue(d["same"]); self.assertTrue(d["fingerprint_same"]); self.assertEqual(d["hits"],[])

if __name__=="__main__": unittest.main()
