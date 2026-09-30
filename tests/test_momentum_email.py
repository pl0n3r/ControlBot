import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"momentum_email_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)


class MomentumEmailTests(unittest.TestCase):
    def test_program_is_campaign_and_venture_scoped(self):
        data=scenario("scope")
        self.assertEqual(data["program"]["venture_id"],"venture-condor")
        self.assertEqual(data["program"]["message_class"],"marketing")
        self.assertTrue(data["cross_venture"])
        self.assertTrue(data["channel_mismatch"])

    def test_marketing_eligibility_fails_closed_on_consent_suppression_or_unsubscribe(self):
        data=scenario("eligibility")
        self.assertTrue(data["cases"]["eligible"]["marketing_eligible"])
        for key in ("denied","suppressed","unsubscribed","stale"):
            self.assertFalse(data["cases"][key]["marketing_eligible"])
        self.assertEqual(data["transactional"]["message_class"],"transactional")
        self.assertFalse(data["transactional"]["marketing_eligible"])

    def test_audit_signals_preserve_source_freshness_and_unknown(self):
        row=scenario("audit")
        self.assertEqual(row["consent"]["status"],"unknown")
        self.assertEqual(row["suppression"]["freshness"],"unknown")
        self.assertTrue(row["consent"]["source_ref"].startswith("source:"))
        self.assertFalse(row["marketing_eligible"])

    def test_direct_pii_secrets_urls_and_extra_fields_fail_closed(self):
        data=scenario("invalid")
        self.assertTrue(all(data.values()))

    def test_core_has_no_provider_send_persistence_scheduler_factory_or_execution(self):
        data=scenario("pure")
        self.assertFalse(data["state"]["execution"])
        self.assertEqual(sorted(data["methods"]),["audienceState","program"])
        source=(ROOT/"src"/"MomentumEmail.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","factoryrunner","scheduler","mysqli","pdo(","mail(","->send"):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()
