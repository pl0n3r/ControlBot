import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "presence_adapter_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(run.stdout)


class PresenceAdapterTests(unittest.TestCase):
    def test_capacity_uses_observed_runtime_signal_not_declared_ceiling(self):
        data = scenario("observed_capacity")["observed"]
        self.assertEqual(data["accounts"][0]["free_capacity"], 3)
        self.assertEqual(data["idle_capacity"], 3)
        self.assertEqual(data["accounts"][0]["observed_state"], "healthy")

    def test_stale_or_unknown_never_adds_idle_capacity(self):
        data = scenario("observed_capacity")
        for key in ("stale", "unknown", "missing"):
            self.assertEqual(data[key]["capacity_state"], "unknown")
            self.assertEqual(data[key]["idle_capacity"], 0)
            self.assertEqual(data[key]["accounts"][0]["free_capacity"], 0)

    def test_provider_degradation_zeroes_new_capacity_without_losing_assignment(self):
        data = scenario("provider_degradation")
        for state in ("rate_limited", "requires_login", "offline"):
            self.assertEqual(data[state]["capacity_state"], "degraded")
            self.assertEqual(data[state]["idle_capacity"], 0)
            self.assertFalse(data[state]["accounts"][0]["eligible"])
            self.assertEqual(data[state]["sessions"][0]["assignment_id"], "work-session_1")

    def test_global_presence_states_remain_deterministic(self):
        data = scenario("states")
        self.assertEqual(data["solo"]["presence_state"], "solo")
        self.assertEqual(data["multi"]["presence_state"], "multi")
        self.assertEqual(data["idle"]["capacity_state"], "idle_capacity")
        self.assertEqual(data["saturated"]["capacity_state"], "saturated")
        self.assertEqual(data["degraded"]["capacity_state"], "degraded")
        self.assertEqual(data["unknown"]["capacity_state"], "unknown")

    def test_generation_and_safe_point_contract_remains_intact(self):
        data = scenario("guard")
        self.assertIn("stale_generation", data["stale"]["reasons"])
        self.assertIn("non_preemptible_outside_safe_point", data["preemptBlocked"]["reasons"])
        self.assertTrue(data["preemptSafe"]["allowed"])

    def test_snapshot_is_single_authoritative_capacity_source(self):
        data = scenario("authoritative")
        self.assertEqual(data["small"]["idle_capacity"], data["large"]["idle_capacity"])
        self.assertEqual(data["small"]["accounts"][0]["free_capacity"], data["large"]["accounts"][0]["free_capacity"])
        source = (ROOT / "src" / "PresenceAdapter.php").read_text(encoding="utf-8")
        self.assertIn("AgentRuntime::observedCapacitySnapshot", source)
        self.assertNotIn("AgentRuntime::capacitySnapshot", source)
        self.assertNotIn("$account['capacity']", source)

    def test_presence_contract_distinguishes_global_states_fail_closed(self):
        data = scenario("states")
        self.assertEqual(data["solo"]["presence_state"], "solo")
        self.assertEqual(data["multi"]["presence_state"], "multi")
        self.assertEqual(data["idle"]["capacity_state"], "idle_capacity")
        self.assertEqual(data["saturated"]["capacity_state"], "saturated")
        self.assertEqual(data["degraded"]["capacity_state"], "degraded")
        self.assertEqual(data["unknown"]["presence_state"], "unknown")
        self.assertEqual(data["unknown"]["capacity_state"], "unknown")
        self.assertEqual(data["rateLimitedIdle"]["capacity_state"], "degraded")
        self.assertEqual(data["rateLimitedIdle"]["idle_capacity"], 0)
        self.assertFalse(data["rateLimitedIdle"]["accounts"][0]["eligible"])

    def test_stale_or_missing_heartbeat_never_creates_free_capacity_and_preserves_assignment(self):
        data = scenario("heartbeat")
        self.assertEqual(data["stale"]["capacity_state"], "degraded")
        self.assertEqual(data["missing"]["capacity_state"], "unknown")
        for key in ("stale", "missing"):
            self.assertEqual(data[key]["idle_capacity"], 0)
            self.assertEqual(data[key]["sessions"][0]["assignment_id"], "work-session_1")
            self.assertEqual(data[key]["sessions"][0]["work_item"], "pl0n3r/ControlBot#120")

    def test_join_leave_stale_recovery_events_are_idempotent(self):
        data = scenario("events")
        self.assertEqual(data["join"]["type"], "join")
        self.assertEqual(data["leave"]["type"], "leave")
        self.assertEqual(data["staleEvent"]["type"], "stale")
        self.assertEqual(data["recovery"]["type"], "recovery")
        self.assertEqual(data["join"]["fingerprint"], data["joinAgain"]["fingerprint"])
        self.assertTrue(data["join"]["recompute"])

    def test_snapshot_is_attributable_and_sanitized(self):
        data = scenario("sanitize")
        clean = data["clean"]
        row = clean["sessions"][0]
        self.assertEqual(row["repository"], "pl0n3r/ControlBot")
        self.assertEqual(row["work_item"], "pl0n3r/ControlBot#120")
        self.assertEqual(row["claims"], ["src/PresenceAdapter.php"])
        self.assertEqual(row["generation"], 3)
        self.assertEqual(row["attempt"], 1)
        self.assertEqual(row["capabilities"], ["php", "review"])
        serialized = json.dumps(clean)
        self.assertNotIn("person@example.com", serialized)
        self.assertNotIn("owner@example.com", serialized)
        self.assertTrue(data["emailId"])
        self.assertTrue(data["secretPath"])

    def test_replan_targets_factory_dispatcher_v2_without_local_ranking(self):
        guard = scenario("guard")["ok"]
        self.assertEqual(guard["policy_ref"], "factory-dispatcher-v2")
        self.assertTrue(guard["allowed"])
        self.assertTrue(guard["recompute"])
        for forbidden in ("score", "rank", "selected", "authority_class"):
            self.assertNotIn(forbidden, guard)

    def test_stale_generation_cannot_recover_current_ownership(self):
        guard = scenario("guard")["stale"]
        self.assertFalse(guard["allowed"])
        self.assertIn("stale_generation", guard["reasons"])
        self.assertEqual(guard["assignment_id"], "work-session_1")

    def test_non_preemptible_requires_safe_point_before_reassignment(self):
        data = scenario("guard")
        self.assertFalse(data["preemptBlocked"]["allowed"])
        self.assertIn("non_preemptible_outside_safe_point", data["preemptBlocked"]["reasons"])
        self.assertTrue(data["preemptSafe"]["allowed"])
        self.assertFalse(data["recoverUnhealthy"]["allowed"])
        self.assertIn("session_not_healthy", data["recoverUnhealthy"]["reasons"])

    def test_presence_simulation_is_deterministic_and_side_effect_free(self):
        data = scenario("deterministic")
        self.assertTrue(data["snapshot_same"])
        self.assertTrue(data["event_same"])
        source = (ROOT / "src" / "PresenceAdapter.php").read_text()
        for forbidden in ("time(", "microtime", "curl_", "new PDO", "mysqli", "file_put_contents", "shell_exec", "exec("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
