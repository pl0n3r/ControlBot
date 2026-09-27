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
    def test_presence_contract_distinguishes_global_states_fail_closed(self):
        data = scenario("states")
        self.assertEqual(data["solo"]["presence_state"], "solo")
        self.assertEqual(data["multi"]["presence_state"], "multi")
        self.assertEqual(data["idle"]["capacity_state"], "idle_capacity")
        self.assertEqual(data["saturated"]["capacity_state"], "saturated")
        self.assertEqual(data["degraded"]["capacity_state"], "degraded")
        self.assertEqual(data["unknown"]["presence_state"], "unknown")
        self.assertEqual(data["unknown"]["capacity_state"], "unknown")

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

    def test_presence_simulation_is_deterministic_and_side_effect_free(self):
        data = scenario("deterministic")
        self.assertTrue(data["snapshot_same"])
        self.assertTrue(data["event_same"])
        source = (ROOT / "src" / "PresenceAdapter.php").read_text()
        for forbidden in ("time(", "microtime", "curl_", "new PDO", "mysqli", "file_put_contents", "shell_exec", "exec("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
