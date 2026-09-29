import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"momentum_revenue_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class MomentumRevenueTests(unittest.TestCase):
    def test_revenue_pipeline_is_venture_scoped_and_cross_venture_fails_closed(self):
        data=scenario("scope")
        self.assertEqual(data["pipeline"]["venture_id"],"venture-condor")
        self.assertRegex(data["pipeline_campaign_ref"],r"^campaign:[a-f0-9]{32}$")
        self.assertRegex(data["pipeline_creative_ref"],r"^creative:[a-f0-9]{32}$")
        self.assertTrue(data["cross_forecast_rejected"])
        self.assertTrue(data["cross_signal_rejected"])

    def test_funnel_and_qualification_are_closed_deterministic_and_pii_free(self):
        data=scenario("funnel")
        self.assertEqual(data["lead"]["stage"],"lead")
        self.assertEqual(data["won"]["qualification"],"qualified")
        self.assertRegex(data["lead"]["lead_ref"],r"^lead:[a-f0-9]{32}$")
        self.assertRegex(data["lead"]["owner_ref"],r"^owner:[a-f0-9]{32}$")
        for key in ("bad_stage_rejected","bad_qualification_rejected","pii_lead_rejected","pii_owner_rejected","extra_rejected"):
            self.assertTrue(data[key])

    def test_forecast_keeps_confidence_source_and_freshness_without_becoming_observed_revenue(self):
        data=scenario("forecast")
        row=data["forecast"]
        self.assertEqual(row["classification"],"forecast")
        self.assertFalse(row["demonstrated_revenue"])
        self.assertEqual(row["confidence"],70)
        self.assertEqual(row["freshness"],"current")
        self.assertRegex(row["source_ref"],r"^source:[a-f0-9]{32}$")
        self.assertTrue(data["bad_confidence_rejected"])
        self.assertTrue(data["bad_freshness_rejected"])

    def test_attribution_separates_observed_inferred_and_unknown(self):
        data=scenario("attribution")
        self.assertEqual(data["observed"]["demonstrated_amount_minor"],12500000)
        self.assertIsNone(data["inferred"]["demonstrated_amount_minor"])
        self.assertIsNone(data["unknown"]["demonstrated_amount_minor"])
        self.assertIsNone(data["unknown"]["amount_minor"])
        self.assertTrue(data["unknown_claim_rejected"])
        self.assertTrue(data["observed_without_evidence_rejected"])
        self.assertTrue(data["pre_won_revenue_rejected"])
        self.assertTrue(data["campaign_mismatch_rejected"])

    def test_post_sale_handoff_and_retention_signals_preserve_domain_boundaries(self):
        data=scenario("handoff")
        self.assertRegex(data["won"]["customer_success_handoff_ref"],r"^customer-success:[a-f0-9]{32}$")
        self.assertEqual(data["retention"]["kind"],"renewal")
        self.assertEqual(data["churn"]["classification"],"inferred")
        self.assertRegex(data["retention"]["product_intelligence_ref"],r"^product-intelligence:[a-f0-9]{32}$")
        self.assertRegex(data["retention"]["customer_success_ref"],r"^customer-success:[a-f0-9]{32}$")
        self.assertTrue(data["premature_handoff_rejected"])
        self.assertTrue(data["orphan_signal_rejected"])
        self.assertTrue(data["pre_won_signal_rejected"])

    def test_core_has_no_provider_persistence_queue_spend_or_execution(self):
        data=scenario("pure")
        self.assertEqual(data["methods"],["attribution","forecast","lifecycleSignal","pipeline"])
        source=(ROOT/"src"/"MomentumRevenue.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","pdo(","mysqli","factoryrunner","scheduler","workitem","spend("):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()
