import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"market_scope_lifecycle_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class MarketScopeLifecycleTests(unittest.TestCase):
    def test_tbd_to_defined_is_venture_scoped_versioned_and_deterministic(self):
        d=scenario("tbd")
        self.assertEqual(d["first"],d["second"])
        self.assertEqual(d["first"]["version"],1)
        self.assertEqual(d["first"]["venture_id"],"venture-condor")
        self.assertEqual(d["first"]["before"],{"state":"tbd","fingerprint":None})
        self.assertEqual(d["first"]["after"]["state"],"defined")
        self.assertRegex(d["first"]["event_ref"],r"^controlbot:market-scope-change/[a-f0-9]{64}$")

    def test_defined_change_has_canonical_deltas_and_reorder_only_is_noop(self):
        d=scenario("delta")
        delta=d["change"]["delta"]
        self.assertEqual(delta["target_countries"],{"added":["PE"],"removed":["MX"]})
        self.assertEqual(delta["launch_countries"],{"added":["PE"],"removed":["CO"]})
        self.assertEqual(delta["expansion_candidates"],{"added":["BR"],"removed":["CL"]})
        self.assertTrue(delta["default_currency"]["changed"])
        self.assertTrue(d["reorder_noop"])

    def test_material_changes_require_lex_and_readiness_reassessment_without_outcomes(self):
        event=scenario("signals")["event"]
        self.assertEqual(event["signals"],{
            "lex_reassessment_required":True,
            "readiness_reassessment_required":True,
        })
        serialized=json.dumps(event,sort_keys=True).lower()
        for forbidden in ("compliant","non_compliant","gap","legal_outcome","readiness_score"):
            self.assertNotIn(forbidden,serialized)

    def test_global_deltas_never_expand_implicit_countries(self):
        event=scenario("global")["event"]
        delta=event["delta"]
        self.assertEqual(delta["launch_countries"],{"added":["US"],"removed":[]})
        self.assertEqual(delta["target_countries"],{"added":[],"removed":[]})
        self.assertNotIn("all",json.dumps(delta).lower())

    def test_event_is_minimal_fingerprinted_and_secret_free(self):
        d=scenario("minimal")
        event=d["event"]
        self.assertEqual(set(event),{
            "version","event_ref","venture_id","actor_ref","changed_at",
            "before","after","delta","signals","execution",
        })
        self.assertNotIn("previous_scope",event)
        self.assertNotIn("next_scope",event)
        self.assertTrue(d["secret_actor_rejected"])
        self.assertTrue(d["cross_venture_format_rejected"])

    def test_contract_has_no_persistence_engine_calls_or_parallel_queue(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["change"])
        self.assertFalse(d["event"]["execution"])
        source=(ROOT/"src"/"MarketScopeLifecycle.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","mysqli","pdo(","factoryrunner","scheduler","workitem","lex::"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
