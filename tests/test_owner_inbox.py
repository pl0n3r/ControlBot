import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"owner_inbox_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class OwnerInboxTests(unittest.TestCase):
    def test_entry_is_strictly_scoped_and_classification_is_closed(self):
        d=scenario("scope")
        self.assertEqual(d["good"]["scope"],{"kind":"venture","ref":"controlbot:venture/condor"})
        self.assertTrue(d["scope"] and d["class"] and d["extra"])

    def test_decision_and_critical_preserve_authority_without_authorizing(self):
        d=scenario("authority")
        self.assertEqual(d["decision"]["required_authority_level"],"L4_OWNER")
        self.assertEqual(d["decision"]["decision_ref"],"controlbot:decision/decide-aa")
        self.assertEqual(d["critical"]["class"],"critical")
        self.assertNotIn("authorized",json.dumps(d).lower())
        self.assertTrue(d["missing"] and d["fyi_authority"])

    def test_freshness_and_provenance_are_fail_closed(self):
        d=scenario("freshness")
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertEqual(d["unknown"]["freshness"],"unknown")
        self.assertIsNone(d["unknown"]["source_ref"])
        self.assertTrue(d["unknown_with_source"] and d["current_without_source"])

    def test_text_and_refs_are_safe_and_evidence_is_deterministic(self):
        d=scenario("safe")
        self.assertEqual(d["sorted"]["evidence_refs"],["controlbot:evidence/aa","controlbot:evidence/zz"])
        self.assertTrue(d["duplicate"] and d["html"] and d["secret"] and d["mail"] and d["number"])
        self.assertTrue(d["local_phone_title"] and d["local_phone_summary"] and d["local_phone_impact"])

    def test_collection_deduplicates_and_orders_without_hidden_score(self):
        d=scenario("collection")
        self.assertEqual([x["class"] for x in d["entries"]],["critical","decision","decision","watch","fyi"])
        self.assertEqual([x["entry_ref"] for x in d["entries"][1:3]],["controlbot:inbox/entry-d2","controlbot:inbox/entry-d1"])
        self.assertTrue(d["duplicate"])
        self.assertNotIn("score",json.dumps(d).lower())

    def test_core_has_no_decision_execution_factory_provider_persistence_notification_scheduler_or_ui(self):
        self.assertEqual(scenario("pure")["methods"],["collection","entry"])
        source=(ROOT/"src"/"OwnerInbox.php").read_text(encoding="utf-8").lower()
        for forbidden in ("decisionrights","workitem","factoryrunner","curl_","http://","https://","mysqli","pdo(","notify","scheduler","render","html"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
