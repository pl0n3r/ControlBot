import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "momentum_performance_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


class MomentumPerformanceTests(unittest.TestCase):
    def test_performance_is_venture_campaign_and_period_scoped(self):
        data = scenario("scope")
        row = data["performance"]
        self.assertEqual(row["venture_id"], "venture-condor")
        self.assertRegex(row["campaign_ref"], r"^campaign:[a-f0-9]{32}$")
        self.assertEqual(row["period"], {"start_at": 1000, "end_at": 2000})
        self.assertTrue(data["cross_venture_rejected"])
        self.assertTrue(data["campaign_mismatch_rejected"])
        self.assertTrue(data["period_mismatch_rejected"])

    def test_spend_lead_conversion_revenue_margin_require_compatible_evidence(self):
        data = scenario("chain")
        full = data["full"]
        self.assertEqual(full["derived"]["spend_minor"], 1000000)
        self.assertEqual(full["derived"]["leads"], 100)
        self.assertEqual(full["derived"]["conversions"], 10)
        self.assertEqual(full["derived"]["observed_revenue_minor"], 12500000)
        self.assertEqual(full["derived"]["cost_minor"], 2000000)
        self.assertEqual(full["derived"]["margin_minor"], 9500000)
        unknown = data["unknown_spend"]
        self.assertIsNone(unknown["derived"]["spend_minor"])
        self.assertIsNone(unknown["derived"]["margin_minor"])

    def test_observed_inferred_unknown_remain_distinct(self):
        data = scenario("classification")
        observed = data["observed"]
        inferred = data["inferred"]
        unknown = data["unknown"]
        self.assertEqual(observed["revenue"]["classification"], "observed")
        self.assertEqual(observed["derived"]["observed_revenue_minor"], 12500000)
        self.assertIsNone(observed["derived"]["inferred_revenue_minor"])
        self.assertEqual(inferred["revenue"]["classification"], "inferred")
        self.assertIsNone(inferred["derived"]["observed_revenue_minor"])
        self.assertEqual(inferred["derived"]["inferred_revenue_minor"], 9000000)
        self.assertIsNone(inferred["derived"]["margin_minor"])
        self.assertEqual(unknown["revenue"]["classification"], "unknown")
        self.assertIsNone(unknown["derived"]["observed_revenue_minor"])
        self.assertIsNone(unknown["derived"]["inferred_revenue_minor"])
        self.assertIsNone(unknown["derived"]["margin_minor"])

    def test_unit_economics_are_unknown_when_inputs_are_insufficient(self):
        data = scenario("unit")
        full = data["full"]["unit_economics"]
        self.assertEqual(full["cac_minor"], 100000)
        self.assertEqual(full["cac_classification"], "observed")
        self.assertEqual(full["roas_milli"], 12500)
        self.assertEqual(full["roas_classification"], "observed")
        self.assertIsNone(full["payback_days"])
        self.assertEqual(full["payback_classification"], "unknown")
        self.assertIsNone(full["ltv_minor"])
        self.assertEqual(full["ltv_classification"], "unknown")
        self.assertIsNone(data["zero_conversions"]["unit_economics"]["cac_minor"])
        self.assertEqual(data["zero_conversions"]["unit_economics"]["cac_classification"], "unknown")
        self.assertIsNone(data["no_spend"]["unit_economics"]["cac_minor"])
        self.assertIsNone(data["no_spend"]["unit_economics"]["roas_milli"])
        self.assertIsNone(data["no_cost"]["derived"]["margin_minor"])
        self.assertIsNone(data["inferred_revenue"]["unit_economics"]["roas_milli"])

    def test_currency_period_freshness_and_evidence_fail_closed(self):
        data = scenario("guardrails")
        for key in (
            "currency_mismatch_rejected",
            "attribution_currency_rejected",
            "outside_period_rejected",
            "duplicate_evidence_rejected",
            "missing_observed_evidence_rejected",
            "invalid_funnel_rejected",
            "extra_field_rejected",
        ):
            self.assertTrue(data[key], key)
        stale = data["stale"]
        self.assertEqual(stale["spend"]["freshness"], "stale")
        self.assertIsNone(stale["derived"]["spend_minor"])
        self.assertIsNone(stale["derived"]["margin_minor"])
        self.assertEqual(stale["evidence_refs"], sorted(stale["evidence_refs"]))

    def test_projection_has_no_pii_tracking_provider_or_side_effects(self):
        data = scenario("pure")
        self.assertEqual(data["methods"], ["project"])
        source = (ROOT / "src" / "MomentumPerformance.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_",
            "http://",
            "https://",
            "pdo(",
            "mysqli",
            "factoryrunner",
            "scheduler",
            "workitem",
            "decisionrights",
            "capitalpolicy",
            "provider",
            "tracking",
            "email",
            "phone",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
