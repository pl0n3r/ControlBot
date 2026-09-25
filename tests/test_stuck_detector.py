import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run(name: str, check: bool = True):
    result = subprocess.run(
        ["php", str(ROOT / "tests/stuck_detector_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True,
    )
    if check and result.returncode != 0:
        raise AssertionError(result.stderr)
    if result.returncode != 0:
        return result
    return json.loads(result.stdout)


class StuckDetectorTests(unittest.TestCase):
    def test_all_supported_patterns_are_detected(self):
        data = run("all")
        expected = {
            "repeated-error": "repeated_error",
            "same-approach": "same_approach_loop",
            "commit-overflow": "pr_commit_overflow",
            "review-loop": "review_loop",
            "stale-reservation": "stale_reservation",
            "heartbeat": "heartbeat_timeout",
            "state-timeout": "state_timeout",
            "rapid-retry": "rapid_retry_loop",
            "handoff-bounce": "handoff_bounce",
        }
        for scenario, finding_type in expected.items():
            self.assertEqual(len(data[scenario]), 1, scenario)
            self.assertEqual(data[scenario][0]["type"], finding_type)

    def test_third_same_approach_attempt_is_not_auto_executable(self):
        finding = run("same-approach")[0]
        self.assertEqual(finding["type"], "same_approach_loop")
        self.assertFalse(finding["evidence"]["next_attempt_auto_executable"])
        self.assertEqual(finding["evidence"]["failures"], 2)

    def test_timeouts_are_configured_per_state(self):
        data = run("timeout-config")
        self.assertEqual(data["short"][0]["type"], "state_timeout")
        self.assertEqual(data["long"], [])

    def test_fingerprint_is_stable_for_same_cause(self):
        first = run("same-approach")[0]
        second = run("same-approach")[0]
        different = run("same-approach-different")
        self.assertEqual(first["fingerprint"], second["fingerprint"])
        self.assertNotEqual(
            different["a"][0]["fingerprint"],
            different["b"][0]["fingerprint"],
        )

    def test_invalid_snapshot_fails_closed(self):
        result = run("invalid", check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("inválido", result.stderr)

    def test_detector_only_recommends_safe_point_pause(self):
        data = run("all")
        actions = {
            finding["recommended_action"]
            for findings in data.values()
            for finding in findings
        }
        self.assertEqual(actions, {"pause_at_safe_point"})


if __name__ == "__main__":
    unittest.main()
