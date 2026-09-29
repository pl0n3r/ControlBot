import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"market_scope_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class MarketScopeTests(unittest.TestCase):
    def test_modes_are_closed_and_global_never_implies_launch(self):
        d=scenario("modes")
        self.assertEqual(d["single"]["launch_countries"],["CO"])
        self.assertEqual(d["multi"]["target_countries"],["CO","MX"])
        self.assertEqual(d["global"]["target_countries"],[])
        self.assertEqual(d["global"]["launch_countries"],["US"])
        self.assertTrue(d["single_missing_primary"] and d["multi_too_small"] and d["launch_outside_target"])

    def test_country_currency_locale_and_lists_are_deterministic_and_fail_closed(self):
        d=scenario("closed")
        self.assertEqual(d["canonical"]["target_countries"],["CO","MX"])
        self.assertEqual(d["canonical"]["expansion_candidates"],["CL","PE"])
        for key in ("duplicate","excluded_overlap","expansion_overlap","bad_country","lower_country","bad_currency","bad_locale","extra"):
            self.assertTrue(d[key],key)

    def test_market_is_venture_scoped_and_cross_venture_fails_closed(self):
        d=scenario("venture")
        self.assertEqual(d["market"]["venture_id"],"venture-condor")
        self.assertTrue(d["cross_venture"])

    def test_market_states_are_closed_without_fabricating_readiness(self):
        d=scenario("state")
        self.assertEqual(d["live"]["status"],"live")
        self.assertEqual(d["live"]["freshness"],"unknown")
        self.assertNotIn("lex_status",d["live"])
        self.assertNotIn("readiness",d["live"])
        self.assertTrue(d["invalid_status"])
        self.assertEqual(d["global"]["geography"],{"kind":"global","code":None})

    def test_condor_can_be_colombia_first_without_core_country_hardcode(self):
        d=scenario("condor")
        self.assertEqual(d["condor"]["primary_country"],"CO")
        self.assertEqual(d["condor"]["default_currency"],"COP")
        self.assertEqual(d["other_country"]["primary_country"],"FR")
        self.assertEqual(d["other_country"]["default_currency"],"EUR")

    def test_contract_has_no_persistence_execution_or_parallel_queue(self):
        d=scenario("pure")
        self.assertEqual(sorted(d["methods"]),["market","scope"])
        source=(ROOT/"src"/"MarketScope.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","factoryrunner","scheduler","workitem","lex::"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
