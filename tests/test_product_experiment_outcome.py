import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"product_experiment_outcome_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)

class ProductExperimentOutcomeTests(unittest.TestCase):
    def test_baseline_and_variant_require_same_scope_surface_category_and_unit(self):
        d=scenario("scope")
        self.assertEqual(d["good"]["venture_id"],"venture-condor")
        self.assertEqual(d["good"]["product_id"],"product-condor")
        for key in ("venture","product","surface","category","unit"):
            self.assertTrue(d[key],key)

    def test_measured_outcome_calculates_delta_and_comparison_with_window_and_provenance(self):
        d=scenario("measured")
        out=d["outcome"]
        self.assertEqual(out["status"],"measured")
        self.assertAlmostEqual(out["delta"],0.12)
        self.assertEqual(out["comparison"],"higher")
        self.assertEqual(out["evaluation_window"],{"start_at":900,"end_at":2100})
        self.assertEqual(out["baseline_metric_id"],"metric-baseline")
        self.assertEqual(out["variant_metric_id"],"metric-variant")
        self.assertTrue(out["source_ref"].startswith("aggregate:"))
        self.assertTrue(out["evidence_ref"].startswith("evidence:product/"))
        self.assertTrue(d["window_reject"])

    def test_unknown_or_insufficient_metrics_are_inconclusive_without_delta(self):
        d=scenario("inconclusive")
        for key in ("unknown","insufficient"):
            self.assertEqual(d[key]["status"],"inconclusive")
            self.assertIsNone(d[key]["delta"])
            self.assertEqual(d[key]["comparison"],"unknown")
        self.assertEqual(d["unknown"]["freshness"],"unknown")

    def test_nature_freshness_and_confidence_combine_fail_closed(self):
        d=scenario("fail_closed")
        self.assertEqual(d["inferred"]["nature"],"inferred")
        self.assertEqual(d["inferred"]["confidence"],0.70)
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertEqual(d["stale"]["confidence"],0.80)
        self.assertEqual(d["unknown_freshness"]["freshness"],"unknown")

    def test_experiment_reference_is_opaque_without_discovery_decisions_or_causal_claims(self):
        d=scenario("boundary")
        out=d["good"]
        self.assertRegex(out["experiment_ref"],r"^experiment:[a-f0-9]{32}$")
        payload=json.dumps(out).lower()
        for forbidden in ("validated","invalidated","build","stop","causal"):
            self.assertNotIn(forbidden,payload)
        self.assertTrue(d["bad_ref"] and d["extra_decision"])

    def test_contract_is_pure_aggregate_and_has_no_tracking_provider_or_queue(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["outcome"])
        source=(ROOT/"src"/"ProductExperimentOutcome.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","factoryrunner","scheduler","workitem","setcookie","localstorage","feature flag"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
