import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"customer_success_core_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class CustomerSuccessCoreTests(unittest.TestCase):
    def test_snapshot_and_support_signal_are_venture_scoped(self):
        data=scenario("scope")
        self.assertEqual(data["snapshot"]["venture_id"],"venture-condor")
        self.assertEqual(data["support"]["venture_id"],"venture-condor")
        self.assertTrue(data["snapshot_cross"])
        self.assertTrue(data["support_cross"])

    def test_health_preserves_dimensions_without_opaque_global_score(self):
        data=scenario("dimensions")
        names=[row["name"] for row in data["snapshot"]["dimensions"]]
        self.assertEqual(names,sorted(names))
        self.assertEqual(len(names),9)
        self.assertNotIn("health_score",data["snapshot"])
        self.assertTrue(data["score_rejected"])

    def test_churn_risk_keeps_confidence_evidence_and_freshness(self):
        data=scenario("churn")
        churn=next(row for row in data["valid"]["dimensions"] if row["name"]=="churn_risk")
        self.assertEqual(churn["nature"],"inferred")
        self.assertEqual(churn["freshness"],"fresh")
        self.assertIsNotNone(churn["evidence_ref"])
        self.assertGreater(churn["confidence"],0)
        self.assertLess(churn["confidence"],1)
        self.assertTrue(data["observed_churn_rejected"])

    def test_support_signal_rejects_transcript_ticket_body_and_direct_pii(self):
        data=scenario("privacy")
        self.assertTrue(all(data.values()),data)

    def test_stale_and_unknown_remain_explicit(self):
        data=scenario("freshness")
        self.assertEqual(data["unknown"]["freshness"],"unknown")
        self.assertIsNone(data["unknown"]["observed_at"])
        self.assertTrue(data["unknown_with_evidence_rejected"])
        stale=next(row for row in data["stale"]["dimensions"] if row["name"]=="adoption")
        self.assertEqual(stale["freshness"],"stale")
        unknown=next(row for row in data["unknown_dimension"]["dimensions"] if row["name"]=="usage_recency")
        self.assertEqual(unknown["status"],"unknown")
        self.assertEqual(unknown["freshness"],"unknown")
        self.assertEqual(unknown["confidence"],0)

    def test_core_has_no_provider_persistence_workitem_scheduler_or_notifications(self):
        source=scenario("pure")["source"].lower()
        for forbidden in (
            "curl_","http://","https://","pdo","mysqli","workitem","scheduler",
            "factoryrunner","notify(","notificationservice","helpdeskclient","crmclient",
        ):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()
