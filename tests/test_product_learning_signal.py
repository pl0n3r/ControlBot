import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_learning_signal_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ProductLearningSignalTests(unittest.TestCase):
    def test_signal_is_scoped_and_references_normalized_product_intelligence(self):
        d=scenario("scope"); out=d["good"]
        self.assertEqual((out["venture_id"],out["product_id"],out["surface"]),("venture-condor","product-condor","web_app"))
        self.assertEqual((out["source_kind"],out["aggregate_ref"]),("metric","product-intelligence:metric/metric-activation"))
        self.assertTrue(d["wrong_scope"])

    def test_targets_and_types_are_closed_deterministic_and_non_authoritative(self):
        d=scenario("closed"); self.assertEqual(d["good"]["targets"],["capital","discovery","momentum"])
        self.assertEqual(d["good"]["type"],"observed_change"); self.assertTrue(d["duplicate"] and d["bad_target"] and d["bad_type"])
        payload=json.dumps(d["good"]).lower()
        for forbidden in ("command","authority","execute","workitem"): self.assertNotIn(forbidden,payload)

    def test_unknown_inconclusive_stale_and_inferred_are_preserved_fail_closed(self):
        d=scenario("fail_closed")
        self.assertEqual((d["unknown"]["type"],d["unknown"]["status"],d["unknown"]["freshness"]),("evidence_gap","unknown","unknown"))
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertEqual((d["inferred"]["type"],d["inferred"]["nature"],d["inferred"]["confidence"]),("inferred_change","inferred",0.55))
        self.assertEqual((d["experiment"]["status"],d["experiment"]["type"]),("inconclusive","experiment_result"))

    def test_signal_rejects_free_text_pii_members_events_and_actions(self):
        self.assertTrue(all(scenario("reject_payload").values()))

    def test_signal_preserves_provenance_without_duplicating_consumer_logic(self):
        d=scenario("provenance")
        self.assertEqual((d["revenue"]["type"],d["revenue"]["source_ref"]),("revenue_outcome","aggregate:finance/product"))
        self.assertEqual(d["experiment"]["type"],"experiment_result")
        self.assertTrue(d["experiment"]["aggregate_ref"].startswith("product-intelligence:experiment-outcome/"))
        self.assertEqual(d["gap"]["type"],"evidence_gap")
        for item in d.values():
            for key in ("evidence_ref","confidence","nature"): self.assertIn(key,item)

    def test_contract_has_no_persistence_tracking_provider_queue_scheduler_or_decision_engine(self):
        self.assertEqual(scenario("pure")["methods"],["fromExperimentOutcome","fromMetric"])
        source=(ROOT/"src"/"ProductLearningSignal.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","setcookie","localstorage","factoryrunner","scheduler","workitem","decisionengine","provider"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
