import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"momentum_campaign_scenarios.php"),name],cwd=ROOT,check=False,text=True,capture_output=True)
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class MomentumCampaignTests(unittest.TestCase):
    def test_campaign_is_provider_neutral_venture_scoped_and_closed(self):
        d=scenario("closed")
        self.assertEqual(d["valid"]["venture_ref"],"controlbot:venture/venture-alpha")
        self.assertEqual(d["valid"]["channels"],["email","paid_social"])
        self.assertTrue(d["bad_channel"] and d["bad_status"] and d["bad_id"])

    def test_budget_and_authority_refs_never_grant_execution(self):
        d=scenario("authority")
        for row in d.values():
            self.assertNotIn("can_execute",row)
            self.assertNotIn("spend_allowed",row)
            self.assertNotIn("authority_level",row)
        self.assertIsNone(d["without_authority"]["authority_ref"])

    def test_attribution_preserves_observed_inferred_unknown_without_inventing_evidence(self):
        d=scenario("attribution")
        self.assertEqual(d["observed"]["attribution_state"],"observed")
        self.assertEqual(d["inferred"]["attribution_state"],"inferred")
        self.assertEqual(d["unknown"]["attribution_state"],"unknown")
        self.assertEqual(d["unknown"]["result_refs"],[])
        self.assertIsNone(d["unknown"]["source_ref"])
        self.assertIsNone(d["unknown"]["observed_at"])
        self.assertTrue(d["unknown_with_result"] and d["unknown_with_source"] and d["observed_without_result"])

    def test_cross_venture_duplicates_extra_fields_pii_secrets_credentials_and_urls_fail_closed(self):
        d=scenario("invalid")
        self.assertTrue(all(d.values()))

    def test_representation_is_deterministic_without_provider_ids_or_free_copy(self):
        d=scenario("deterministic")
        self.assertEqual(d["a"],d["b"])
        self.assertEqual(d["a"]["creative_variant_refs"],sorted(d["a"]["creative_variant_refs"]))
        self.assertEqual(d["a"]["result_refs"],sorted(d["a"]["result_refs"]))
        encoded=json.dumps(d["a"],sort_keys=True).lower()
        for forbidden in ("title","body","copy","meta_","tiktok","google","facebook","instagram"):
            self.assertNotIn(forbidden,encoded)

    def test_contract_has_no_external_io_delivery_spend_crm_factory_or_side_effects(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["normalize"])
        source=d["source"].lower()
        for forbidden in ("curl_init(","curl_exec(","http://","https://","new pdo","mysqli_connect(","file_put_contents(","fopen(","shell_exec(","proc_open(","factoryrunner"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
