import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"momentum_campaign_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class MomentumCampaignTests(unittest.TestCase):
    def test_brand_and_campaign_are_venture_scoped_and_cross_venture_fails_closed(self):
        data=scenario("scope")
        self.assertEqual(data["brand"]["venture_id"],"venture-condor")
        self.assertEqual(data["campaign"]["venture_id"],"venture-condor")
        self.assertTrue(data["cross_venture"])

    def test_campaign_is_vendor_neutral_and_multichannel(self):
        row=scenario("neutral")["campaign"]
        self.assertEqual(row["channels"],["e"+"mail","paid_social","web"])
        serialized=json.dumps(row,sort_keys=True).lower()
        for vendor in ("meta","facebook","instagram","tiktok","google_ads","mail_service_vendor"):
            self.assertNotIn(vendor,serialized)

    def test_references_are_opaque_and_sensitive_or_direct_pii_fails_closed(self):
        data=scenario("privacy")
        self.assertTrue(data["human_slug_rejected"])
        self.assertTrue(data["phone_like_rejected"])
        self.assertTrue(data["wrong_namespace_rejected"])
        self.assertTrue(data["secret_rejected"])

    def test_state_schedule_channels_and_variants_are_closed_and_deterministic(self):
        data=scenario("closed")
        row=data["canonical"]
        self.assertEqual(row["creative_variant_refs"],["creative:a","creative:b"])
        self.assertEqual(row["channels"],["email","paid_social","web"])
        self.assertTrue(data["duplicate_rejected"])
        self.assertTrue(data["extra_rejected"])
        self.assertTrue(data["schedule_rejected"])
        self.assertTrue(data["active_without_start_rejected"])

    def test_contract_has_no_execution_spend_or_parallel_scheduler(self):
        data=scenario("pure")
        self.assertFalse(data["campaign"]["execution"])
        self.assertEqual(sorted(data["methods"]),["brandContext","campaign"])
        source=(ROOT/"src"/"MomentumCampaign.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","factoryrunner","scheduler","publishcampaign","spend("):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()
