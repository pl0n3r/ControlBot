import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def raw(name: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", str(ROOT / "tests/decision_history_scenarios.php"), name],
        cwd=ROOT, check=False, text=True, capture_output=True,
    )


def scenario(name: str):
    result = raw(name)
    result.check_returncode()
    return json.loads(result.stdout)


class DecisionHistoryTests(unittest.TestCase):
    def test_history_consolidates_decision_steps(self):
        data = scenario("history")
        self.assertEqual(len(data["all"]), 2)
        newest = data["all"][0]
        self.assertEqual(newest["repository"], "pl0n3r/factory")
        self.assertEqual(newest["result"], "failed")
        self.assertEqual(newest["actions"], ["comment", "dispatch-release"])
        self.assertEqual(len(data["filtered"]), 1)

    def test_history_filters_are_server_side_allowlisted(self):
        self.assertNotEqual(raw("bad-filter").returncode, 0)
        self.assertNotEqual(raw("bad-category").returncode, 0)

    def test_history_does_not_expose_untrusted_fields_or_secrets(self):
        rendered = json.dumps(scenario("secret"))
        self.assertNotIn("owner-secret-value", rendered)
        self.assertNotIn("supersecret", rendered)
        self.assertNotIn("evil.example", rendered)


    def test_snooze_history_is_safe_and_explicit(self):
        data = scenario("snooze")
        active = data["active"][0]
        expired = data["expired"][0]

        self.assertEqual(active["actions"], ["snooze"])
        self.assertEqual(active["option"], "S")
        self.assertEqual(active["snoozed_until"], 500)
        self.assertEqual(active["snooze_state"], "active")
        self.assertEqual(expired["snooze_state"], "expired")
        self.assertEqual(active["evidence"], [])
        self.assertNotIn("secret", json.dumps(data).lower())


if __name__ == "__main__":
    unittest.main()
