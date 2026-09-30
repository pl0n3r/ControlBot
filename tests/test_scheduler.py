import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def fixture(script, name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/script),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


def scenario(name):
    return fixture("scheduler_scenarios.php",name)


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


    def test_same_reserved_issue_is_never_assigned_to_two_sessions(self):
        data=fixture("dynamic_capacity_e2e_scenarios.php","lifecycle")
        previous=data["readiness"]["previous"]
        current=data["readiness"]["current"]
        stale=data["readiness"]["stale_owner"]
        self.assertTrue(previous["ready"])
        self.assertEqual(previous["reservation_owner"],"session-a")
        self.assertTrue(current["ready"])
        self.assertEqual(current["reservation_owner"],"session-b")
        self.assertTrue(data["readiness"]["duplicate_owners_rejected"])
        self.assertFalse(stale["ready"])
        self.assertIn("stale_reservation_generation",stale["reasons"])
        self.assertIn("stale_owner",stale["reasons"])

    def test_unsatisfied_dependency_prevents_dispatch(self):
        data=scenario("dependencies")
        blocked=next(row for row in data["work_items"] if row["work_item_id"]=="work-a")
        self.assertFalse(blocked["eligible"])
        self.assertIn("pending_dependencies",blocked["reasons"])
        self.assertEqual(data["dispatchable_capacity"],1)

    def test_rate_limited_account_reassigns_capacity_without_losing_workitem(self):
        data=fixture("dynamic_capacity_e2e_scenarios.php","lifecycle")
        self.assertEqual(data["signals"]["rate_limited"]["state"],"rate_limited")
        self.assertEqual(data["rate_limited"]["idle_capacity"],0)
        self.assertEqual(data["rate_limited"]["sessions"][0]["assignment_id"],"assignment-217")
        self.assertEqual(data["rate_limited"]["sessions"][0]["work_item"],"pl0n3r/ControlBot#217")
        self.assertEqual(data["dispatch"]["rate_limited"]["dispatchable_capacity"],0)
        self.assertEqual(data["handoff"]["from_session_id"],"session-a")
        self.assertEqual(data["handoff"]["to_session_id"],"session-b")
        self.assertTrue(data["readiness"]["duplicate_owners_rejected"])
        self.assertTrue(data["current_recovery_guard"]["allowed"])
        self.assertTrue(data["current_recovery_guard"]["recompute"])
        self.assertEqual(data["dispatch"]["recovered"]["dispatchable_capacity"],1)

    def test_selection_reason_is_deterministic_and_reproducible(self):
        data=fixture("scheduler_selection_scenarios.php","telemetry")
        self.assertTrue(data["same"])
        validated=data["validated"]
        self.assertEqual(
            validated["selection_reason"],
            "critical ready leaf selected by Factory dispatcher",
        )
        self.assertEqual(validated["telemetry"]["ready_not_selected"],["work-b"])
        self.assertEqual(set(validated["telemetry"]["excluded"]),{"work-c"})
        self.assertTrue(data["fabricated"])
        self.assertTrue(data["secret"])
        self.assertTrue(data["pii"])

    def test_lost_heartbeat_requeues_safely_with_handoff_and_generation_fencing(self):
        requeue=fixture("scheduler_requeue_plan_scenarios.php","requeue")
        guard=fixture("scheduler_requeue_plan_scenarios.php","owner_guard")
        self.assertFalse(requeue["release_reservation"]["active"])
        self.assertEqual(requeue["work_item"]["state"],"queued")
        self.assertIsNone(requeue["work_item"]["reservation_id"])
        self.assertIsNone(requeue["work_item"]["assigned_session_id"])
        self.assertEqual(
            (requeue["fence"]["previous_generation"],requeue["fence"]["next_generation"]),
            (4,5),
        )
        self.assertEqual(
            (requeue["fence"]["previous_attempt"],requeue["fence"]["next_attempt"]),
            (2,3),
        )
        self.assertIsNone(requeue["handoff"]["to_session_id"])
        self.assertTrue(guard["before"]["allowed"])
        self.assertFalse(guard["old_after"]["allowed"])
        self.assertIn("stale_generation",guard["old_after"]["reasons"])
        self.assertIn("work_unowned",guard["old_after"]["reasons"])

    def test_scheduler_simulation_is_deterministic(self):
        first=fixture("dynamic_capacity_e2e_scenarios.php","lifecycle")
        second=fixture("dynamic_capacity_e2e_scenarios.php","lifecycle")
        self.assertEqual(first,second)


if __name__=="__main__":
    unittest.main()
