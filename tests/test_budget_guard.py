import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests/budget_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)


class BudgetGuardTests(unittest.TestCase):
    def test_snapshot_is_deterministic_and_unknown_is_fail_closed(self):
        first = run("summary")
        second = run("summary")
        self.assertEqual(first, second)
        missing = run("missing")
        self.assertEqual(missing["state"], "unknown")
        self.assertIsNone(missing["utilization_percent"])
        self.assertIsNone(missing["headroom_for_change"])

    def test_actions_capacity_is_aggregated_by_owner_without_double_counting(self):
        data = run("summary")
        self.assertEqual(data["account_scope"], "pl0n3r")
        self.assertEqual(data["used"], 1800)
        self.assertEqual(data["projected_baseline_monthly"], 1440)
        by_repo = {row["repository"]: row for row in data["projects"]}
        self.assertTrue(by_repo["pl0n3r/FactoryRunner"]["counts_toward_scope"])
        self.assertFalse(by_repo["pl0n3r/ControlBot"]["counts_toward_scope"])
        self.assertEqual(data["variable_usage"], 800)

    def test_projected_baseline_detects_structural_quota_pressure(self):
        data = run("baseline")
        self.assertEqual(data["used"], 100)
        self.assertEqual(data["projected_baseline_monthly"], 1700)
        self.assertEqual(data["headroom_for_change"], 300)
        self.assertEqual(data["state"], "warning")

    def test_incident_78_threshold_fixtures_keep_exhausted_and_blocked_distinct(self):
        data = run("thresholds")
        self.assertEqual(data["critical"]["state"], "critical")
        self.assertEqual(data["exhausted"]["state"], "exhausted")
        self.assertEqual(data["blocked"]["state"], "blocked")

    def test_exhaustion_pauses_noncritical_private_runner_work(self):
        data = run("policy")
        for key in ("noncritical", "unknown"):
            self.assertFalse(data[key]["auto_executable"])
            self.assertTrue(data[key]["pause_noncritical"])
            self.assertTrue(data[key]["preserve_pr_fail_closed"])

    def test_recovery_requires_single_exact_head_canary(self):
        data = run("recovery")
        self.assertEqual(data["pending"]["required_canaries"], 1)
        self.assertFalse(data["pending"]["release_queue"])
        self.assertTrue(data["success"]["release_queue"])
        self.assertFalse(data["stale"]["release_queue"])

    def test_financial_mutations_are_not_supported(self):
        data = run("mutation")
        self.assertTrue(data["blocked"])
        self.assertIn("no soportadas", data["message"])


if __name__ == "__main__":
    unittest.main()
