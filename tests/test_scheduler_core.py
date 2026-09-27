import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"scheduler_core_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class SchedulerCoreTests(unittest.TestCase):
    def test_workitem_schema_is_exact_and_fail_closed(self):
        data=scenario("schema")
        self.assertEqual(data["valid"]["work_item_id"],"work-118")
        for key in ("extra","generation","source","state"):
            self.assertTrue(data[key],(key,data))

    def test_dependencies_approval_and_freeze_fail_closed_with_structured_reasons(self):
        data=scenario("readiness")
        expected={
            "open":"open_dependencies","unknown":"unknown_dependencies",
            "pending":"pending_human_gate","approvalUnknown":"approval_unknown",
            "freeze":"freeze_active","freezeUnknown":"freeze_unknown",
        }
        for key,reason in expected.items():
            self.assertFalse(data[key]["ready"],(key,data[key]))
            self.assertIn(reason,data[key]["reasons"])

    def test_reservation_and_generation_fencing_prevent_double_owner(self):
        data=scenario("fencing")
        self.assertTrue(data["ready"]["ready"])
        self.assertIn("stale_generation",data["stale_generation"]["reasons"])
        self.assertIn("stale_owner",data["stale_owner"]["reasons"])
        self.assertIn("incompatible_reservation",data["conflict"]["reasons"])
        self.assertTrue(data["double_owner"])

    def test_unavailable_account_capacity_does_not_lose_workitem(self):
        data=scenario("capacity")
        self.assertEqual(data["before"],data["after"])
        for key in ("full","rate_limited"):
            self.assertFalse(data[key]["ready"])
            self.assertIn("account_capacity_unavailable",data[key]["reasons"])

    def test_candidate_targets_factory_dispatcher_v2_without_parallel_ranking(self):
        data=scenario("candidate")
        self.assertEqual(data["policy_ref"],"factory-dispatcher-v2")
        self.assertEqual(data["priority"],"critical")
        self.assertTrue(data["readiness"]["ready"])
        for forbidden in ("score","rank","selected","authority_class"):
            self.assertNotIn(forbidden,data)

    def test_scheduler_core_simulation_is_deterministic_and_side_effect_free(self):
        data=scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"],data["second"])
        source=(ROOT/"src"/"SchedulerCore.php").read_text()
        for forbidden in ("curl_","new PDO","mysqli","file_put_contents","shell_exec","exec("):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
