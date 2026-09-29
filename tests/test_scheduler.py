import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"scheduler_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class SchedulerTests(unittest.TestCase):
    def test_degraded_presence_can_preserve_authoritative_idle_capacity(self):
        data=scenario("mixed_capacity")["degraded"]
        self.assertEqual(data["policy_ref"],"factory-dispatcher-v2")
        self.assertEqual(data["authoritative_idle_capacity"],2)
        self.assertEqual(data["dispatchable_capacity"],2)

    def test_unknown_presence_can_preserve_authoritative_idle_capacity_without_reclassification(self):
        data=scenario("mixed_capacity")
        self.assertEqual(data["unknown_input_state"],"unknown")
        self.assertEqual(data["unknown"]["authoritative_idle_capacity"],1)
        self.assertEqual(data["unknown"]["dispatchable_capacity"],1)
        self.assertEqual(data["unknown"]["policy_ref"],"factory-dispatcher-v2")

    def test_zero_authoritative_idle_capacity_remains_non_dispatchable(self):
        data=scenario("mixed_capacity")["zero"]
        self.assertEqual(data["authoritative_idle_capacity"],0)
        self.assertEqual(data["dispatchable_capacity"],0)
        self.assertIn("authoritative_capacity_unavailable",data["reasons"])

    def test_presence_capacity_contract_still_fails_closed_on_invalid_shape_policy_or_idle(self):
        data=scenario("mixed_capacity")["invalid"]
        self.assertTrue(data["policy"])
        self.assertTrue(data["idle"])
        self.assertTrue(data["shape"])
        self.assertTrue(data["saturated_positive"])
        self.assertTrue(data["idle_zero"])

    def test_scheduler_does_not_recompute_presence_capacity_from_accounts_or_plan(self):
        self.assertTrue(scenario("mixed_capacity")["account_noise_same"])
        source=(ROOT/"src"/"SchedulerCore.php").read_text(encoding="utf-8")
        self.assertNotIn("declared_capacity",source)
        self.assertNotIn("provider_id",source)
        self.assertNotIn("plan",source)

    def test_dispatchable_capacity_never_exceeds_authoritative_idle_capacity(self):
        data=scenario("idle_bound")
        self.assertEqual(data["small"]["authoritative_idle_capacity"],2)
        self.assertEqual(data["small"]["dispatchable_capacity"],2)
        self.assertEqual(data["large"]["dispatchable_capacity"],3)
        for row in data.values():
            self.assertLessEqual(row["dispatchable_capacity"],row["authoritative_idle_capacity"])

    def test_pending_dependencies_reduce_dispatchable_capacity(self):
        data=scenario("dependencies")
        self.assertEqual(data["dispatchable_capacity"],1)
        blocked=next(row for row in data["work_items"] if row["work_item_id"]=="work-a")
        self.assertFalse(blocked["eligible"])
        self.assertIn("pending_dependencies",blocked["reasons"])

    def test_claim_conflicts_reduce_capacity_without_duplicate_ownership(self):
        data=scenario("claims")
        self.assertEqual(data["active"]["dispatchable_capacity"],1)
        blocked=next(row for row in data["active"]["work_items"] if row["work_item_id"]=="work-a")
        self.assertIn("claim_conflict",blocked["reasons"])
        owned=next(row for row in data["owned"]["work_items"] if row["work_item_id"]=="work-a")
        self.assertTrue(owned["eligible"])
        self.assertNotIn("claim_conflict",owned["reasons"])
        self.assertEqual(data["owned"]["dispatchable_capacity"],2)
        self.assertEqual(data["peer"]["dispatchable_capacity"],1)
        self.assertEqual(data["peer"]["claim_lanes"],1)
        self.assertIn("claim_contention",data["peer"]["reasons"])

    def test_project_concurrency_policy_can_reduce_idle_capacity(self):
        data=scenario("concurrency")
        self.assertEqual(data["authoritative_idle_capacity"],5)
        self.assertEqual(data["concurrency_slots"],1)
        self.assertEqual(data["dispatchable_capacity"],1)
        self.assertIn("project_concurrency",data["reasons"])

    def test_joint_claim_and_concurrency_bound_does_not_overestimate(self):
        data=scenario("joint_bound")
        self.assertEqual(data["authoritative_idle_capacity"],4)
        self.assertEqual(data["ready_work_items"],4)
        self.assertEqual(data["claim_lanes"],3)
        self.assertEqual(data["concurrency_slots"],3)
        self.assertEqual(data["joint_constraint_slots"],2)
        self.assertEqual(data["dispatchable_capacity"],2)
        self.assertIn("claim_contention",data["reasons"])
        self.assertIn("project_concurrency",data["reasons"])

    def test_unknown_critical_constraint_fails_closed(self):
        data=scenario("unknown")
        for row in data.values():
            self.assertEqual(row["dispatchable_capacity"],0)
            self.assertIn("critical_constraint_unknown",row["reasons"])

    def test_capacity_projection_does_not_rank_or_select_work(self):
        data=scenario("contract")
        self.assertEqual(data["policy_ref"],"factory-dispatcher-v2")
        self.assertEqual([row["work_item_id"] for row in data["work_items"]],["work-a","work-b"])
        serialized=json.dumps(data)
        for forbidden in ('"score"','"rank"','"selected"','"winner"'):
            self.assertNotIn(forbidden,serialized)


if __name__=="__main__":
    unittest.main()
