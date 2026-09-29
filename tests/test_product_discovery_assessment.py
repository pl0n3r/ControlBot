import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"product_discovery_assessment_scenarios.php"),name],
        cwd=ROOT,text=True,capture_output=True
    )
    if run.returncode!=0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed")
    if run.stderr.strip():
        raise AssertionError(run.stderr.strip())
    return json.loads(run.stdout)

class ProductDiscoveryAssessmentTests(unittest.TestCase):
    def test_assessment_binds_exactly_to_plan_and_outcome(self):
        d=scenario("bindings")
        self.assertTrue(all(d.values()))
        valid=scenario("valid")
        self.assertEqual(valid["experiment_ref"],"experiment:"+"c"*32)
        self.assertEqual(valid["hypothesis_ref"],"hypothesis:"+"b"*32)
        self.assertEqual(valid["primary_metric_ref"],"metric:"+"d"*32)
        self.assertEqual(valid["outcome_id"],"outcome-alpha")

    def test_classification_is_closed_and_requires_rule_and_evidence(self):
        d=scenario("classification")
        self.assertEqual(d["validated"],"VALIDATED")
        self.assertEqual(d["invalidated"],"INVALIDATED")
        self.assertEqual(d["inconclusive"],"INCONCLUSIVE")
        self.assertTrue(d["unknown_class"])
        self.assertTrue(d["empty_rule"])
        self.assertTrue(d["empty_evidence"])

    def test_inconclusive_stale_unknown_or_inferred_cannot_be_validated_or_invalidated(self):
        d=scenario("unreliable")
        self.assertTrue(d["stale_reject"])
        self.assertEqual(d["stale_ok"],"INCONCLUSIVE")
        self.assertTrue(d["inferred_reject"])
        self.assertEqual(d["inferred_ok"],"INCONCLUSIVE")
        self.assertTrue(d["unknown_reject"])
        self.assertEqual(d["unknown_ok"],"INCONCLUSIVE")

    def test_observed_measured_result_preserves_numbers_without_inferring_desirability_or_causality(self):
        d=scenario("numbers")
        self.assertEqual(d["higher"]["comparison"],"higher")
        self.assertEqual(d["higher"]["delta"],5)
        self.assertEqual(d["higher"]["classification"],"INVALIDATED")
        self.assertEqual(d["lower"]["comparison"],"lower")
        self.assertEqual(d["lower"]["delta"],-15)
        self.assertEqual(d["lower"]["classification"],"VALIDATED")
        self.assertEqual(d["higher"]["confidence"],0.9)
        self.assertEqual(d["higher"]["nature"],"observed")
        self.assertEqual(d["higher"]["freshness"],"fresh")
        serialized=json.dumps(d).lower()
        self.assertNotIn("desirability",serialized)
        self.assertNotIn("causal",serialized)

    def test_schema_refs_and_lists_are_closed_opaque_deterministic_and_secret_free(self):
        d=scenario("schema")
        self.assertEqual(d["sorted"],sorted(d["sorted"]))
        self.assertTrue(d["duplicate"])
        self.assertTrue(d["extra"])
        self.assertTrue(d["bad_ref"])
        serialized=json.dumps(scenario("valid")).lower()
        for forbidden in ("password","secret","authorization","bearer ","@"):
            self.assertNotIn(forbidden,serialized)

    def test_contract_has_no_build_authority_budget_lex_factory_runner_or_side_effects(self):
        source=scenario("source")["source"].lower()
        for forbidden in (
            "new pdo","mysqli","curl_","http://","https://","file_put_contents","fopen(",
            "workitem","factoryrunner","dispatchworkflow","enqueue(","authority","budget","lexruntime"
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
