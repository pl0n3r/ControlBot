import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"momentum_creative_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class MomentumCreativeTests(unittest.TestCase):
    def test_brief_and_variant_are_venture_and_brand_scoped(self):
        data=scenario("scope")
        self.assertEqual(data["brief"]["venture_id"],"venture-condor")
        self.assertEqual(data["variant"]["brand_context_id"],data["brief"]["brand_context_id"])
        self.assertTrue(data["cross_venture"])

    def test_variant_is_provider_neutral_and_uses_opaque_refs(self):
        data=scenario("neutral")
        row=data["full"]
        self.assertRegex(row["content_ref"],r"^content:[a-f0-9]{32}$")
        self.assertRegex(row["asset_ref"],r"^asset:[a-f0-9]{32}$")
        self.assertIsNone(data["content_only"]["asset_ref"])
        self.assertIsNone(data["content_only"]["hypothesis_ref"])
        self.assertIsNone(data["asset_only"]["content_ref"])
        self.assertIsNone(data["asset_only"]["metric_ref"])
        source=(ROOT/"src"/"MomentumCreative.php").read_text(encoding="utf-8").lower()
        for forbidden in ("facebook","instagram","tiktok","google_ads","mail_service_vendor"):
            self.assertNotIn(forbidden,source)

    def test_lifecycle_requires_provenance_for_approved(self):
        data=scenario("lifecycle")
        self.assertEqual(data["approved"]["status"],"approved")
        self.assertTrue(data["approved"]["provenance_refs"])
        self.assertTrue(data["missing_rejected"])
        self.assertEqual(data["archived"]["provenance_refs"],data["approved"]["provenance_refs"])

    def test_variant_set_is_deterministic_and_rejects_duplicates(self):
        data=scenario("set")
        self.assertEqual([row["variant_key"] for row in data["ordered"]],["A","B"])
        self.assertEqual(data["empty"],[])
        self.assertTrue(data["duplicate_rejected"])

    def test_core_has_no_generation_publish_spend_or_scheduler(self):
        source=(ROOT/"src"/"MomentumCreative.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","publish","spend(","scheduler","factoryrunner"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
