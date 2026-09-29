import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"product_discovery_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class ProductDiscoveryTests(unittest.TestCase):
    def test_initiative_requires_no_repository_and_preserves_scope_problem_and_evidence(self):
        d=scenario("initiative")
        self.assertFalse(d["has_repo"])
        self.assertEqual((d["venture"]["scope"],d["venture"]["venture_id"]),("venture","venture-condor"))
        self.assertRegex(d["venture"]["problem_ref"],r"^problem:[a-f0-9]{32}$")
        self.assertRegex(d["venture"]["segment_ref"],r"^segment:[a-f0-9]{32}$")
        self.assertEqual(d["venture"]["evidence_refs"],sorted(d["venture"]["evidence_refs"]))
        self.assertEqual((d["group"]["scope"],d["group"]["venture_id"],d["group"]["market_ref"]),("group",None,None))
        self.assertTrue(d["bad_group_scope"] and d["bad_venture_scope"])

    def test_hypothesis_binds_to_initiative_and_requires_outcome_and_primary_metric(self):
        d=scenario("hypothesis"); h=d["good"]
        self.assertRegex(h["hypothesis_ref"],r"^hypothesis:[a-f0-9]{32}$")
        self.assertRegex(h["expected_outcome_ref"],r"^outcome:[a-f0-9]{32}$")
        self.assertRegex(h["primary_metric_ref"],r"^metric:[a-f0-9]{32}$")
        self.assertEqual(h["initiative_id"],"initiative:"+"1"*32)
        self.assertTrue(d["missing_outcome"] and d["missing_metric"] and d["wrong_initiative"])

    def test_freshness_and_confidence_fail_closed_without_promoting_unknown(self):
        d=scenario("freshness")
        self.assertEqual((d["stale"]["freshness"],d["stale"]["confidence"]),("stale","medium"))
        self.assertEqual((d["unknown"]["freshness"],d["unknown"]["confidence"]),("unknown","unknown"))
        self.assertTrue(d["stale_high"] and d["unknown_high"] and d["unknown_medium_hypothesis"])

    def test_refs_and_lists_are_closed_opaque_and_deterministic(self):
        d=scenario("closed")
        self.assertEqual(d["initiative"]["evidence_refs"],sorted(d["initiative"]["evidence_refs"]))
        self.assertEqual(d["hypothesis"]["constraint_refs"],sorted(d["hypothesis"]["constraint_refs"]))
        for key in ("extra","bad_ref","duplicate_evidence","bad_scope","duplicate_constraints","extra_hypothesis"):
            self.assertTrue(d[key],key)
        payload=json.dumps({"i":d["initiative"],"h":d["hypothesis"]})
        self.assertNotIn("@",payload)

    def test_core_has_no_experiment_execution_build_or_factory_queue(self):
        self.assertEqual(scenario("pure")["methods"],["hypothesis","initiative"])
        source=(ROOT/"src"/"ProductDiscovery.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","pdo(","mysqli","factoryrunner","scheduler","workitem","queue(","execute(","experimentrunner"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
