import json
import subprocess
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"executive_cockpit_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if run.returncode!=0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed")
    if run.stderr.strip():
        raise AssertionError(run.stderr.strip())
    return json.loads(run.stdout)
class ExecutiveCockpitTests(unittest.TestCase):
    def test_business_and_technical_health_remain_separate(self):
        d=scenario("base")
        v=d["ventures"][0]
        self.assertEqual(d["group_id"],"group-one")
        self.assertEqual(v["business_health"]["state"],"healthy")
        self.assertEqual(v["technical_health"]["state"],"healthy")
        self.assertEqual(v["venture"]["responsible"]["identity_id"],"owner-alpha")
    def test_stale_and_unknown_never_become_healthy(self):
        v=scenario("stale")["ventures"][0]
        self.assertEqual(v["business_health"]["freshness"],"stale")
        self.assertEqual(v["business_health"]["state"],"degraded")
        self.assertEqual(v["technical_health"],{"state":"unknown","freshness":"unknown","source_ref":None,"observed_at":None})
        self.assertEqual(v["product_health"]["freshness"],"unknown")
    def test_canonical_signals_are_scope_checked(self):
        d=scenario("scope")
        self.assertTrue(all(d.values()))
    def test_owner_inbox_is_counted_without_hidden_priority(self):
        v=scenario("base")["ventures"][0]
        self.assertEqual(v["owner_inbox_counts"],{"fyi":0,"watch":1,"decision":1,"critical":0})
        self.assertNotIn("score",json.dumps(v).lower())
        self.assertNotIn("priority",json.dumps(v).lower())
    def test_collection_is_deterministic_and_secret_free(self):
        d=scenario("order")
        self.assertEqual([v["venture"]["venture_id"] for v in d["ventures"]],["venture-alpha","venture-beta"])
        self.assertTrue(scenario("duplicate")["duplicate"])
        serialized=json.dumps(d).lower()
        for forbidden in ("password","secret","authorization","api_key","customer_id"):
            self.assertNotIn(forbidden,serialized)
    def test_aggregate_has_no_external_io_or_execution(self):
        source=scenario("source")["source"].lower()
        for forbidden in ("new pdo","mysqli","curl_","http://","https://","factoryrunner::","dispatchworkflow","enqueue(","scheduler","file_put_contents","fopen("):
            self.assertNotIn(forbidden,source)
if __name__=="__main__":
    unittest.main()
