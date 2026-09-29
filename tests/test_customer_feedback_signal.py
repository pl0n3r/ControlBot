import json
import subprocess
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"customer_feedback_signal_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)
class CustomerFeedbackSignalTests(unittest.TestCase):
    def test_feedback_is_venture_scoped_and_product_target_requires_explicit_product_ref(self):
        data=scenario("scope")
        self.assertEqual(data["good"]["venture_id"],"venture-condor")
        self.assertTrue(data["good"]["product_ref"].startswith("product:"))
        self.assertTrue(data["cross"])
        self.assertTrue(data["missingProduct"])
        self.assertTrue(data["extraRejected"])
    def test_origins_and_targets_are_closed_unique_and_deterministic(self):
        data=scenario("closed")
        self.assertEqual(data["good"]["targets"],["discovery","product_intelligence"])
        self.assertEqual(data["good"]["origin"],"support_pattern")
        self.assertTrue(data["duplicate"])
        self.assertTrue(data["unknown"])
        refs=[row["feedback_ref"] for row in data["collection"]]
        self.assertEqual(refs,sorted(refs))
        self.assertTrue(data["duplicatedCollection"])
        self.assertTrue(data["forgedSource"])
        self.assertTrue(data["forgedScope"])
        self.assertTrue(data["forgedUnknown"])
    def test_evidence_freshness_confidence_and_nature_remain_fail_closed(self):
        data=scenario("fail_closed")
        self.assertEqual(data["stale"]["freshness"],"stale")
        self.assertEqual(data["stale"]["nature"],"observed")
        self.assertIsNone(data["stale"]["confidence"])
        self.assertEqual(data["unknownSupport"]["freshness"],"unknown")
        self.assertEqual(data["unknownSupport"]["nature"],"unknown")
        self.assertIsNone(data["unknownSupport"]["observed_at"])
        self.assertEqual(data["unknownSupport"]["evidence_refs"],[])
        self.assertEqual(data["churn"]["nature"],"inferred")
        self.assertEqual(data["churn"]["confidence"],0.42)
        self.assertEqual(data["unknownHealth"]["nature"],"unknown")
        self.assertEqual(data["unknownHealth"]["freshness"],"unknown")
        self.assertIsNone(data["unknownHealth"]["observed_at"])
    def test_feedback_rejects_transcript_ticket_body_pii_attachments_and_free_text(self):
        data=scenario("privacy")
        self.assertTrue(all(data.values()),data)
    def test_contract_has_no_consumer_execution_decisions_scores_workitems_or_operational_side_effects(self):
        data=scenario("pure")
        self.assertEqual(data["methods"],["collection","fromHealthDimension","fromSupport"])
        source=(ROOT/"src"/"CustomerFeedbackSignal.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_","http://","https://","mysqli","pdo(","factoryrunner","scheduler",
            "workitem","decisionengine","notify(","notificationservice","helpdeskclient",
            "crmclient","score=","execute(",
        ):
            self.assertNotIn(forbidden,source)
if __name__=="__main__":
    unittest.main()
