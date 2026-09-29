import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(n):
    r=subprocess.run(["php",str(ROOT/"tests"/"global_search_index_scenarios.php"),n],cwd=ROOT,text=True,capture_output=True,check=True)
    return json.loads(r.stdout)

class GlobalSearchIndexTests(unittest.TestCase):
    def test_incremental_batches_are_monotonic_and_idempotent_per_source(self):
        d=scenario("incremental");self.assertTrue(d["retry"]);self.assertEqual(d["watermark"],2)
    def test_stale_conflicting_or_source_mismatched_batches_fail_closed(self):
        self.assertTrue(all(scenario("invalid").values()))
    def test_stable_source_identity_survives_rename_without_duplicates(self):
        d=scenario("rename");self.assertEqual(d["count"],1);self.assertEqual(d["entity"]["document"]["repo"],"pl0n3r/factory")
    def test_delete_reindex_and_cross_source_isolation_are_idempotent(self):
        d=scenario("reindex");self.assertTrue(d["fingerprint"] and d["retry"] and d["exists"]);self.assertEqual(d["factory"],1)
    def test_query_reuses_core_without_expanding_access_or_freshness(self):
        d=scenario("query");self.assertEqual([x["number_or_id"] for x in d["items"]],[1,3]);self.assertEqual([x["freshness"] for x in d["items"]],["fresh","stale"])
    def test_fixture_query_p95_is_below_two_seconds_and_core_is_pure(self):
        d=scenario("performance");self.assertLess(d["p95"],2000);self.assertEqual(d["hits"],[])
if __name__=="__main__":unittest.main()
