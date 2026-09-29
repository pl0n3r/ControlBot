import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_learning_work_origin_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ProductLearningWorkOriginTests(unittest.TestCase):
    def test_metric_and_experiment_signal_build_factory_work_item_scope(self):
        d=scenario("scope")
        for item in d.values():
            self.assertEqual((item["origin_mode"],item["origin_system"],item["venture_id"]),("automatic","controlbot","venture-condor"))
            self.assertEqual(item["producer_ref"],"controlbot:product-intelligence")

    def test_authority_priority_type_capabilities_roles_and_policy_are_explicit_inputs(self):
        d=scenario("explicit"); a,b=d["first"],d["second"]
        self.assertEqual((a["work_type"],a["priority_class"],a["authority_level"]),("product","high","operational"))
        self.assertEqual((b["work_type"],b["priority_class"],b["authority_level"]),("data_analytics","medium","owner"))
        self.assertEqual(b["requested_capabilities"],["analysis"]); self.assertEqual(b["required_roles"],["datos-analitica"])
        self.assertEqual(b["policy_ref"],"factory:policy-product")

    def test_evidence_and_idempotency_are_deterministic_and_preserve_provenance(self):
        d=scenario("idempotency"); a,b=d["a"],d["b"]
        self.assertEqual(a["idempotency_key"],b["idempotency_key"])
        self.assertEqual(a["evidence_refs"],sorted(a["evidence_refs"]))
        self.assertTrue(any(x.startswith("controlbot:product-learning/") for x in a["evidence_refs"]))
        self.assertTrue(any(x.startswith("product-intelligence:metric/") for x in a["evidence_refs"]))

    def test_observed_at_is_required_and_limited_evidence_never_becomes_ready_or_approved(self):
        d=scenario("limited"); self.assertTrue(d["missing_time"])
        for item in (d["unknown"],d["stale"]):
            self.assertEqual(item["observed_at"],"2026-09-29T14:00:00Z")
            for forbidden in ("ready","approved","authorized","readiness"): self.assertNotIn(forbidden,item)

    def test_factory_optional_refs_are_preserved_without_execution_fields(self):
        d=scenario("optional"); item=d["good"]
        self.assertEqual((item["project_id"],item["repository_ref"]),("controlbot","pl0n3r/ControlBot"))
        self.assertEqual((item["budget_ref"],item["approval_ref"]),("capital:product","owner-decision:260"))
        self.assertTrue(d["bad_execution_field"])
        for forbidden in ("provider","model","executor","dispatcher"): self.assertNotIn(forbidden,item)

    def test_bridge_has_no_factory_calls_persistence_ranking_scheduler_or_parallel_queue(self):
        self.assertEqual(scenario("pure")["methods"],["fromExperimentOutcome","fromMetric"])
        source=(ROOT/"src"/"ProductLearningWorkOrigin.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","scheduler","dispatcher","ranking","queue"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
