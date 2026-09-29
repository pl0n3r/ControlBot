import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_intelligence_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ProductIntelligenceTests(unittest.TestCase):
    def test_metrics_are_venture_product_surface_and_period_scoped(self):
        d=scenario("scope")
        self.assertEqual(d["metric"]["venture_id"],"venture-condor")
        self.assertEqual(d["metric"]["product_id"],"product-condor")
        self.assertEqual(d["metric"]["surface"],"web_app")
        self.assertEqual(d["metric"]["period"],{"start_at":1000,"end_at":2000})
        self.assertTrue(d["wrong_venture"] and d["wrong_product"] and d["bad_period"])

    def test_provenance_sample_freshness_confidence_and_nature_are_required(self):
        d=scenario("provenance")
        self.assertEqual(d["inferred"]["nature"],"inferred")
        self.assertEqual(d["inferred"]["confidence"],0.61)
        self.assertTrue(d["zero_sample"] and d["bad_confidence"] and d["bad_freshness"] and d["sensitive_ref"])

    def test_unknown_and_insufficient_data_never_become_healthy_or_observed(self):
        d=scenario("unknown")
        self.assertEqual(d["unknown"]["status"],"unknown")
        self.assertIsNone(d["unknown"]["value"])
        self.assertEqual(d["unknown"]["freshness"],"unknown")
        self.assertEqual(d["insufficient"]["status"],"insufficient_data")
        self.assertIsNone(d["insufficient"]["value"])
        self.assertNotIn("healthy",json.dumps(d).lower())
        self.assertTrue(d["unknown_with_value"] and d["insufficient_zero"])

    def test_funnels_and_cohorts_are_aggregate_and_reject_pii_membership(self):
        d=scenario("aggregate")
        self.assertEqual(d["funnel"]["stages"],[{"name":"visited","count":200},{"name":"activated","count":84}])
        self.assertEqual(d["cohort"]["sample_size"],80)
        self.assertEqual(d["cohort"]["retained_count"],46)
        self.assertTrue(d["increasing"] and d["member_list"] and d["cohort_members"] and d["retained_over_sample"])

    def test_revenue_outcomes_keep_aggregate_refs_without_customer_transactions(self):
        d=scenario("revenue")
        self.assertEqual(d["good"]["category"],"revenue_outcome")
        self.assertTrue(d["good"]["source_ref"].startswith("aggregate:finance/"))
        self.assertTrue(d["bad_source"] and d["customer_ref"])

    def test_contract_has_no_persistence_tracking_provider_or_parallel_queue(self):
        d=scenario("pure")
        self.assertEqual(sorted(d["methods"]),["cohort","funnel","metric"])
        source=(ROOT/"src"/"ProductIntelligence.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","factoryrunner","scheduler","workitem","setcookie","localstorage","segment","mixpanel"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
