import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_health_snapshot_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ProductHealthSnapshotTests(unittest.TestCase):
    def test_snapshot_is_venture_product_surface_and_period_scoped(self):
        d=scenario("scope"); out=d["good"]
        self.assertEqual((out["venture_id"],out["product_id"],out["surface"]),("venture-condor","product-condor","web_app"))
        self.assertEqual(out["period"],{"start_at":1000,"end_at":2000})
        for key in ("venture","product","surface","period"): self.assertTrue(d[key],key)

    def test_dimensions_are_unique_deterministic_and_preserve_metric_provenance_without_score(self):
        d=scenario("dimensions"); dims=d["good"]["dimensions"]
        self.assertEqual([x["category"] for x in dims],["activation","retention"])
        self.assertEqual(dims[0]["source_ref"],"aggregate:analytics/activation")
        self.assertEqual(dims[1]["evidence_ref"],"evidence:product/retention")
        self.assertTrue(d["duplicate"])
        self.assertNotIn("score",d["good"])

    def test_unknown_insufficient_stale_and_inferred_emit_explicit_reasons_without_healthy(self):
        out=scenario("reasons"); by={x["category"]:x for x in out["dimensions"]}
        self.assertIn("unknown",by["activation"]["reasons"])
        self.assertIn("insufficient_data",by["adoption"]["reasons"])
        self.assertIn("stale",by["retention"]["reasons"])
        self.assertIn("inferred",by["satisfaction"]["reasons"])
        self.assertEqual(by["completion_rate"]["reasons"],["observed_fresh"])
        self.assertNotIn("healthy",json.dumps(out).lower())

    def test_global_freshness_and_reasons_degrade_fail_closed_deterministically(self):
        out=scenario("global")
        self.assertEqual(out["freshness"],"unknown")
        self.assertEqual(out["reasons"],sorted(out["reasons"]))
        for reason in ("freshness_unknown","inferred","observed_fresh","stale","unknown"): self.assertIn(reason,out["reasons"])

    def test_snapshot_does_not_mix_customer_success_technical_finance_or_decisions(self):
        d=scenario("boundary"); payload=json.dumps(d["good"]).lower()
        for key in ("churn","support","revenue"): self.assertTrue(d[key],key)
        for forbidden in ("customer_success","churn","support","technical","finance","decision"): self.assertNotIn(forbidden,payload)

    def test_contract_has_no_workitem_persistence_provider_recommendation_scoring_or_ui(self):
        self.assertEqual(scenario("pure")["methods"],["snapshot"])
        source=(ROOT/"src"/"ProductHealthSnapshot.php").read_text(encoding="utf-8").lower()
        for forbidden in ("workitem","curl_","http://","https://","mysqli","pdo(","provider","recommend","score","render","html"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
