import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/decision_runtime_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class DecisionSnoozeTests(unittest.TestCase):
    def test_only_canonical_durations_are_accepted(self):
        tomorrow = scenario("snooze")
        week = scenario("snooze-week")
        invalid = scenario("snooze-invalid-duration")
        self.assertEqual(tomorrow["response"]["duration"], "tomorrow")
        self.assertEqual(
            tomorrow["response"]["snoozed_until"] - tomorrow["audit"][0]["at"],
            86400,
        )
        self.assertEqual(week["response"]["duration"], "week")
        self.assertEqual(
            week["response"]["snoozed_until"] - week["audit"][0]["at"],
            604800,
        )
        self.assertTrue(invalid["blocked"])
        self.assertEqual(invalid["audit"], [])
    def test_snooze_rebuilds_target_from_server_side_inbox(self):
        valid = scenario("snooze")
        manipulated = scenario("snooze-manipulated")
        self.assertEqual(valid["audit"][0]["repository"], "pl0n3r/factory")
        self.assertEqual(valid["audit"][0]["issue"], 137)
        self.assertEqual(valid["audit"][0]["category"], "factory-release")
        self.assertTrue(
            any("/issues?state=open&per_page=100&page=1" in row[1] for row in valid["seen"])
        )
        self.assertTrue(manipulated["blocked"])
        self.assertEqual(manipulated["audit"], [])

    def test_snooze_requires_csrf_and_recent_reauth(self):
        no_csrf = scenario("snooze-no-csrf")
        stale = scenario("snooze-stale-reauth")
        self.assertTrue(no_csrf["blocked"])
        self.assertEqual(no_csrf["audit"], [])
        self.assertTrue(stale["blocked"])
        self.assertEqual(stale["audit"], [])

    def test_snoozed_gate_reappears_after_expiry(self):
        data = scenario("snooze")
        self.assertNotIn("¿Publicamos Factory?", data["before"])
        self.assertIn("Sin decisiones pendientes", data["before"])
        self.assertIn("¿Publicamos Factory?", data["after"])

    def test_snoozed_gate_blocks_stale_individual_approval(self):
        data = scenario("snooze-direct-approval")
        self.assertTrue(data["blocked"])
        self.assertEqual([row["action"] for row in data["audit"]], ["snooze"])
        urls = [row[1] for row in data["seen"]]
        self.assertFalse(any("/issues/137/comments" in url for url in urls))
        self.assertFalse(any(
            url.endswith("/issues/137") and method == "PATCH"
            for method, url in data["seen"]
        ))

if __name__ == "__main__":
    unittest.main()
