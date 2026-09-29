import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_discovery_experiment_scenarios.php"),name],cwd=ROOT,check=False,text=True,capture_output=True)
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class ProductDiscoveryExperimentTests(unittest.TestCase):
    def test_plan_binds_to_hypothesis_initiative_and_primary_metric(self):
        d=scenario("bind")
        self.assertEqual(d["good"]["initiative_id"],"initiative:"+"1"*32)
        self.assertEqual(d["good"]["hypothesis_ref"],"hypothesis:"+"1"*32)
        self.assertEqual(d["good"]["primary_metric_ref"],"metric:"+"1"*32)
        self.assertTrue(d["initiative"] and d["hypothesis"] and d["metric"])

    def test_experiment_declares_kind_window_and_cost_before_execution(self):
        d=scenario("declared")
        self.assertEqual(d["good"]["kind"],"landing_test")
        self.assertEqual(d["good"]["evaluation_window"],{"start_at":1000,"end_at":2000})
        self.assertEqual(d["reversed_window"]["evaluation_window"],{"start_at":1000,"end_at":2000})
        self.assertRegex(d["good"]["validation_cost_ref"],r"^cost:[a-f0-9]{32}$")
        self.assertTrue(d["bad_kind"] and d["bad_window"] and d["bad_cost"])

    def test_scope_and_evidence_state_are_preserved_without_repository(self):
        d=scenario("scope")
        self.assertEqual((d["venture"]["scope"],d["venture"]["venture_id"]),("venture","venture-condor"))
        self.assertEqual((d["group"]["scope"],d["group"]["venture_id"]),("group",None))
        self.assertEqual((d["stale"]["hypothesis_freshness"],d["stale"]["hypothesis_confidence"]),("stale","medium"))
        self.assertEqual((d["unknown"]["hypothesis_freshness"],d["unknown"]["hypothesis_confidence"]),("unknown","unknown"))
        self.assertNotIn("repository",d["venture"])
        self.assertNotIn("project_ref",d["venture"])

    def test_invalid_refs_fields_state_window_and_mismatch_fail_closed(self):
        d=scenario("invalid")
        self.assertTrue(all(d.values()))

    def test_plan_is_deterministic_and_never_executes(self):
        d=scenario("deterministic")
        self.assertEqual(d["a"],d["b"])
        self.assertFalse(d["a"]["execution"])
        self.assertNotIn("decision",d["a"])

    def test_contract_has_no_runner_tracking_build_decision_or_factory_queue(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["plan"])
        source=d["source"].lower()
        for forbidden in ("curl_","http://","https://","pdo(","mysqli","factoryrunner","workitem","feature flag","setcookie","localstorage","build"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
