import json
import subprocess
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"momentum_revenue_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class MomentumRevenueTests(unittest.TestCase):
    def test_revenue_pipeline_is_venture_scoped_and_cross_venture_fails_closed(self):
        d=scenario("scope"); self.assertEqual(d["pipeline"]["venture_id"],"venture-condor")
        self.assertRegex(d["pipeline_campaign_ref"],r"^campaign:[a-f0-9]{32}$"); self.assertRegex(d["pipeline_creative_ref"],r"^creative:[a-f0-9]{32}$")
        self.assertTrue(d["cross_forecast_rejected"]); self.assertTrue(d["cross_signal_rejected"])

    def test_funnel_and_qualification_are_closed_deterministic_and_pii_free(self):
        d=scenario("funnel"); self.assertEqual(d["lead"]["stage"],"lead"); self.assertEqual(d["won"]["qualification"],"qualified")
        self.assertRegex(d["lead"]["lead_ref"],r"^lead:[a-f0-9]{32}$"); self.assertRegex(d["lead"]["owner_ref"],r"^owner:[a-f0-9]{32}$")
        for key in ("bad_stage_rejected","bad_qualification_rejected","pii_lead_rejected","pii_owner_rejected","extra_rejected"): self.assertTrue(d[key])

    def test_forecast_keeps_confidence_source_and_freshness_without_becoming_observed_revenue(self):
        d=scenario("forecast"); row=d["forecast"]; self.assertEqual(row["classification"],"forecast"); self.assertFalse(row["demonstrated_revenue"])
        self.assertEqual((row["confidence"],row["freshness"]),(70,"current")); self.assertRegex(row["source_ref"],r"^source:[a-f0-9]{32}$")
        self.assertTrue(d["bad_confidence_rejected"]); self.assertTrue(d["bad_freshness_rejected"])

    def test_attribution_separates_observed_inferred_and_unknown(self):
        d=scenario("attribution"); self.assertEqual(d["observed"]["demonstrated_amount_minor"],12500000)
        self.assertIsNone(d["inferred"]["demonstrated_amount_minor"]); self.assertIsNone(d["unknown"]["demonstrated_amount_minor"]); self.assertIsNone(d["unknown"]["amount_minor"])
        for key in ("unknown_claim_rejected","observed_without_evidence_rejected","pre_won_revenue_rejected","campaign_mismatch_rejected"): self.assertTrue(d[key])

    def test_post_sale_handoff_and_retention_signals_preserve_domain_boundaries(self):
        d=scenario("handoff"); self.assertRegex(d["won"]["customer_success_handoff_ref"],r"^customer-success:[a-f0-9]{32}$")
        self.assertEqual(d["retention"]["kind"],"renewal"); self.assertEqual(d["churn"]["classification"],"inferred")
        self.assertRegex(d["retention"]["product_intelligence_ref"],r"^product-intelligence:[a-f0-9]{32}$"); self.assertRegex(d["retention"]["customer_success_ref"],r"^customer-success:[a-f0-9]{32}$")
        for key in ("premature_handoff_rejected","orphan_signal_rejected","pre_won_signal_rejected"): self.assertTrue(d[key])

    def test_core_has_no_provider_persistence_queue_spend_or_execution(self):
        d=scenario("pure"); self.assertEqual(d["methods"],["attribution","forecast","lifecycleSignal","pipeline"])
        source=(ROOT/"src"/"MomentumRevenue.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","pdo(","mysqli","factoryrunner","scheduler","workitem","spend("): self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
