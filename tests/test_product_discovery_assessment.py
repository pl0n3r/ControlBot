import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"product_discovery_assessment_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class ProductDiscoveryAssessmentTests(unittest.TestCase):
    def test_assessment_binds_exactly_to_plan_and_outcome(self):
        d=scenario("bindings"); self.assertTrue(all(d.values())); v=scenario("valid")
        self.assertEqual(v["experiment_ref"],"experiment:"+"c"*32)
        self.assertEqual(v["hypothesis_ref"],"hypothesis:"+"b"*32)
        self.assertEqual(v["primary_metric_ref"],"metric:"+"d"*32)
        self.assertEqual(v["outcome_id"],"outcome-alpha")
        self.assertTrue(d["window"])

    def test_classification_is_closed_and_requires_rule_and_evidence(self):
        d=scenario("classification")
        self.assertEqual((d["validated"],d["invalidated"],d["inconclusive"]),("VALIDATED","INVALIDATED","INCONCLUSIVE"))
        self.assertTrue(all(d[k] for k in ("unknown_class","empty_rule","empty_evidence")))

    def test_inconclusive_stale_unknown_or_inferred_cannot_be_validated_or_invalidated(self):
        d=scenario("unreliable")
        self.assertTrue(d["stale_reject"]); self.assertEqual(d["stale_ok"],"INCONCLUSIVE")
        self.assertTrue(d["inferred_reject"]); self.assertEqual(d["inferred_ok"],"INCONCLUSIVE")
        self.assertTrue(d["unknown_reject"]); self.assertEqual(d["unknown_ok"],"INCONCLUSIVE")
        self.assertTrue(d["zero_confidence_reject"]); self.assertEqual(d["zero_confidence_ok"],"INCONCLUSIVE")
        self.assertTrue(d["hypothesis_stale_reject"]); self.assertEqual(d["hypothesis_stale_ok"],"INCONCLUSIVE")
        self.assertTrue(d["hypothesis_unknown_reject"]); self.assertEqual(d["hypothesis_unknown_ok"],"INCONCLUSIVE")

    def test_observed_measured_result_preserves_numbers_without_inferring_desirability_or_causality(self):
        d=scenario("numbers")
        self.assertEqual((d["higher"]["comparison"],d["higher"]["delta"],d["higher"]["classification"]),("higher",5,"INVALIDATED"))
        self.assertEqual((d["lower"]["comparison"],d["lower"]["delta"],d["lower"]["classification"]),("lower",-15,"VALIDATED"))
        self.assertEqual((d["higher"]["confidence"],d["higher"]["nature"],d["higher"]["freshness"]),(0.9,"observed","fresh"))
        s=json.dumps(d).lower(); self.assertNotIn("desirability",s); self.assertNotIn("causal",s)

    def test_schema_refs_and_lists_are_closed_opaque_deterministic_and_secret_free(self):
        d=scenario("schema"); self.assertEqual(d["sorted"],sorted(d["sorted"]))
        self.assertTrue(all(d[k] for k in ("duplicate","extra","bad_ref","sensitive_text","direct_pii_text")))
        self.assertEqual(d["numeric_opaque_ref"],"assessment:"+"12345678901234567890123456789012")
        s=json.dumps(scenario("valid")).lower()
        for value in ("password","secret","authorization","bearer ","@"): self.assertNotIn(value,s)

    def test_contract_has_no_build_authority_budget_lex_factory_runner_or_side_effects(self):
        source=scenario("source")["source"].lower()
        for value in ("new pdo","mysqli","curl_","http://","https://","file_put_contents","fopen(","workitem","factoryrunner","dispatchworkflow","enqueue(","authority","budget","lexruntime"):
            self.assertNotIn(value,source)

if __name__=="__main__": unittest.main()
