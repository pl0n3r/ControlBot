import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"market_readiness_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class MarketReadinessTests(unittest.TestCase):
    def test_country_market_with_complete_gates_is_scoped_and_deterministic(self):
        d=scenario("complete")
        self.assertEqual(d["first"],d["second"])
        self.assertEqual(d["first"]["venture_id"],"venture-condor")
        self.assertEqual(d["first"]["country"],"CO")
        self.assertEqual(d["first"]["launch_state"],"ready")
        self.assertEqual(d["first"]["missing_domains"],[])
        self.assertEqual(len(d["first"]["gates"]),13)

    def test_domain_and_scope_contract_fails_closed(self):
        d=scenario("contract")
        for key in ("missing","duplicate","extra","field_extra","venture_mismatch","country_mismatch"):
            self.assertTrue(d[key],key)

    def test_stale_unknown_and_evidenceless_ready_never_become_ready(self):
        d=scenario("freshness")
        self.assertEqual(d["stale"]["launch_state"],"unknown")
        gate=next(g for g in d["stale"]["gates"] if g["domain"]=="lex")
        self.assertEqual(gate["status"],"ready")
        self.assertEqual(gate["effective_status"],"unknown")
        self.assertTrue(d["unknown_ready_rejected"])
        self.assertTrue(d["evidenceless_ready_rejected"])

    def test_missing_domains_and_launch_state_are_explicit_without_score(self):
        d=scenario("missing")
        self.assertEqual(d["blocked"]["launch_state"],"blocked")
        self.assertEqual(d["blocked"]["missing_domains"],["payments","support"])
        self.assertEqual(d["unknown"]["launch_state"],"unknown")
        self.assertEqual(d["unknown"]["missing_domains"],["support"])
        self.assertEqual(d["ready_with_na"]["launch_state"],"ready")
        self.assertNotIn("score",json.dumps(d).lower())

    def test_market_lifecycle_or_non_country_geography_cannot_fabricate_country_readiness(self):
        d=scenario("market")
        self.assertEqual(d["live"]["market_status"],"live")
        self.assertEqual(d["live"]["launch_state"],"blocked")
        self.assertTrue(d["global_rejected"])
        self.assertTrue(d["region_rejected"])
        self.assertTrue(d["stale_market_rejected"])

    def test_contract_has_no_persistence_engines_providers_or_parallel_queue(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["forCountry"])
        self.assertFalse(d["projection"]["execution"])
        source=(ROOT/"src"/"MarketReadiness.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","mysqli","pdo(","factoryrunner","scheduler","workitem","lex::","capital::","aegis::","momentum::"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
