import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "execution_guardrail_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)
class ExecutionGuardrailTests(unittest.TestCase):
    def test_canonical_stuck_patterns_are_detected_without_false_healthy(self):
        healthy = scenario("healthy")
        self.assertEqual(healthy["health"], "healthy")
        self.assertEqual(healthy["signals"], [])
        self.assertFalse(healthy["pause_required"])
        stuck = scenario("all-patterns")
        expected = {
            "same_approach_twice",
            "same_error_without_new_evidence",
            "too_many_commits",
            "review_loop",
            "issue_stale",
            "reservation_stale",
            "heartbeat_lost",
            "state_timeout",
            "duplicate_retry",
            "handoff_bounce",
        }
        self.assertEqual(set(stuck["signals"]), expected)
        self.assertEqual(stuck["health"], "stuck")
    def test_third_same_approach_attempt_requires_pause(self):
        data = scenario("all-patterns")
        self.assertIn("same_approach_twice", data["signals"])
        self.assertTrue(data["pause_required"])
    def test_timeouts_heartbeat_and_stale_thresholds_are_injected(self):
        data = scenario("thresholds")
        self.assertIn("heartbeat_lost", data["strict"]["signals"])
        self.assertIn("state_timeout", data["strict"]["signals"])
        self.assertNotIn("heartbeat_lost", data["relaxed"]["signals"])
        self.assertNotIn("state_timeout", data["relaxed"]["signals"])
    def test_alert_fingerprint_is_deterministic_and_deduplicated(self):
        first, second = scenario("fingerprint")
        self.assertEqual(first["alert_fingerprint"], second["alert_fingerprint"])
        self.assertEqual(first["signals"], second["signals"])
    def test_handoff_is_minimal_sanitized_and_sufficient(self):
        data = scenario("all-patterns")
        self.assertEqual(
            set(data["handoff"]),
            {"issue_ref","pr_ref","sha","last_result","attempts","next_approach","signals"},
        )
        self.assertEqual(data["handoff"]["issue_ref"], "pl0n3r/ControlBot#109")
        self.assertEqual(data["handoff"]["attempts"], 2)
        self.assertTrue(scenario("secret-handoff")["blocked"])
    def test_non_preemptible_work_never_requires_automatic_pause(self):
        data = scenario("non-preemptible")
        self.assertEqual(data["health"], "stuck")
        self.assertFalse(data["pause_required"])
        self.assertTrue(data["escalation_required"])
if __name__ == "__main__":
    unittest.main()
