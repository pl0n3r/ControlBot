import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def run(script: str, scenario: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / script), scenario],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)

class DecisionBatchTests(unittest.TestCase):
    def test_only_explicit_low_risk_recommendations_are_eligible(self):
        data = run("decision_batch_scenarios.php", "eligible")
        self.assertEqual([row["issue"] for row in data["eligible"]], [1])
        self.assertEqual(data["eligible"][0]["option"], "A")

    def test_batch_rebuilds_selection_from_server_side_inbox(self):
        data = run("decision_runtime_scenarios.php", "batch")
        self.assertEqual(data["response"]["state"], "success")
        self.assertEqual([row["issue"] for row in data["response"]["completed"]], [138])
        self.assertTrue(any("/issues?state=open&per_page=100&page=1" in row[1] for row in data["seen"]))
        tampered = run("decision_runtime_scenarios.php", "batch-untrusted-selection")
        self.assertTrue(tampered["blocked"])
        self.assertEqual(tampered["seen"], [])

    def test_snoozed_decisions_are_excluded_from_server_side_batch(self):
        data = run("decision_runtime_scenarios.php", "batch-snoozed")
        self.assertEqual(data["response"]["state"], "empty")
        self.assertEqual(data["response"]["completed"], [])
        urls = [row[1] for row in data["seen"]]
        self.assertTrue(any("/issues?state=open&per_page=100&page=1" in url for url in urls))
        self.assertFalse(any("/issues/138/comments" in url for url in urls))
        self.assertFalse(any(
            url.endswith("/issues/138") and method == "PATCH"
            for method, url in data["seen"]
        ))

    def test_batch_reuses_individual_approval_guards(self):
        expired = run("decision_runtime_scenarios.php", "batch-reauth")
        self.assertTrue(expired["blocked"])
        self.assertEqual(expired["seen"], [])
        missing_csrf = run("decision_runtime_scenarios.php", "batch-no-csrf")
        self.assertTrue(missing_csrf["blocked"])
        self.assertEqual(missing_csrf["seen"], [])
        valid = run("decision_runtime_scenarios.php", "batch")
        urls = [row[1] for row in valid["seen"]]
        self.assertTrue(any("/issues/138/comments" in url for url in urls))
        self.assertTrue(any(url.endswith("/issues/138") for url in urls))

    def test_batch_stops_on_first_failure_with_partial_evidence(self):
        data = run("decision_batch_scenarios.php", "partial")
        self.assertEqual(data["calls"], [1, 2])
        self.assertEqual(data["result"]["state"], "blocked")
        self.assertEqual([row["issue"] for row in data["result"]["completed"]], [1])
        self.assertEqual(data["result"]["failed"]["issue"], 2)
        self.assertEqual(data["result"]["failed"]["error"], "approval-failed")

    def test_sensitive_or_unknown_risk_gates_are_never_auto_included(self):
        data = run("decision_batch_scenarios.php", "eligible")
        self.assertEqual({row["category"] for row in data["eligible"]}, {"brand"})
        self.assertNotIn(3, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(4, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(5, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(6, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(7, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(8, [row["issue"] for row in data["eligible"]])
        self.assertNotIn(9, [row["issue"] for row in data["eligible"]])

if __name__ == "__main__":
    unittest.main()
