import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "financial_planning_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class FinancialPlanningTests(unittest.TestCase):
    def test_actual_budget_target_and_forecast_remain_distinct(self):
        data = scenario("distinct")
        self.assertEqual(data["series"]["actual"]["kind"], "actual")
        self.assertEqual(data["series"]["budget"]["kind"], "budget")
        self.assertEqual(data["series"]["target"]["kind"], "target")
        self.assertEqual(data["series"]["forecast"]["kind"], "forecast")
        self.assertEqual(data["series"]["actual"]["amount_minor"], 12_000_000)
        self.assertEqual(data["series"]["forecast"]["amount_minor"], 14_000_000)

    def test_variance_requires_compatible_period_and_currency(self):
        data = scenario("compatible")
        compatible = data["compatible"]["variances"]
        self.assertEqual(compatible["budget"]["status"], "available")
        self.assertEqual(compatible["budget"]["delta_minor"], 2_000_000)
        self.assertEqual(compatible["target"]["delta_minor"], -3_000_000)
        self.assertEqual(compatible["forecast"]["delta_minor"], -2_000_000)

        incompatible = data["incompatible"]["variances"]
        self.assertEqual(incompatible["budget"]["status"], "unavailable")
        self.assertEqual(incompatible["budget"]["reason"], "budget_incompatible")
        self.assertIsNone(incompatible["budget"]["delta_minor"])
        self.assertEqual(incompatible["forecast"]["status"], "unavailable")
        self.assertEqual(incompatible["forecast"]["reason"], "forecast_incompatible")
        self.assertEqual(incompatible["target"]["status"], "available")

    def test_projection_never_replaces_missing_actual(self):
        data = scenario("missing-actual")
        self.assertEqual(data["status"], "unavailable")
        self.assertEqual(data["reason"], "actual_missing")
        self.assertIsNone(data["series"]["actual"])
        self.assertEqual(data["series"]["forecast"]["kind"], "forecast")
        self.assertEqual(data["series"]["forecast"]["amount_minor"], 14_000_000)
        for variance in data["variances"].values():
            self.assertEqual(variance["status"], "unavailable")
            self.assertEqual(variance["reason"], "actual_missing")
            self.assertIsNone(variance["delta_minor"])

    def test_attribution_without_provenance_remains_unknown(self):
        data = scenario("attribution")
        by_id = {row["attribution_id"]: row for row in data["attributions"]}
        self.assertEqual(by_id["revenue-core"]["status"], "attributed")
        self.assertEqual(by_id["revenue-core"]["target_scope"], "venture:condor")
        self.assertEqual(by_id["shared-platform"]["status"], "unattributed")
        self.assertIsNone(by_id["shared-platform"]["target_scope"])
        self.assertIsNone(by_id["shared-platform"]["rule_ref"])
        self.assertIsNone(by_id["shared-platform"]["provenance_ref"])

    def test_confidence_freshness_and_source_are_preserved(self):
        data = scenario("evidence")
        budget = data["series"]["budget"]
        self.assertEqual(budget["source_ref"], "controlbot:finance/budget-approved")
        self.assertEqual(budget["observed_at"], 2100)
        self.assertEqual(budget["as_of"], 2050)
        self.assertEqual(budget["freshness"], "stale")
        self.assertEqual(budget["confidence"], "estimated")

    def test_comparison_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(data["unknown"]["series"]["forecast"]["freshness"], "unknown")
        self.assertEqual(data["unknown"]["status"], "available")
        self.assertEqual(data["unknown"]["variances"]["forecast"]["status"], "unavailable")
        self.assertEqual(data["unknown"]["variances"]["forecast"]["reason"], "forecast_unknown")
        self.assertIsNone(data["unknown"]["variances"]["forecast"]["delta_minor"])


if __name__ == "__main__":
    unittest.main()
