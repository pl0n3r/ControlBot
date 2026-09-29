import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"vendor_registry_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class VendorRegistryTests(unittest.TestCase):
    def test_vendor_record_is_venture_scoped_and_cross_venture_refs_fail_closed(self):
        data=scenario("scope")
        self.assertEqual(data["valid"]["venture_id"],"venture-condor")
        self.assertTrue(data["cost_cross"])
        self.assertTrue(data["security_cross"])

    def test_cost_security_and_legal_use_authoritative_refs_without_copying_authority(self):
        data=scenario("authority")
        self.assertEqual(data["cost"]["capital_ref"],"capital:"+"4"*32)
        self.assertEqual(data["security"]["aegis_review_ref"],"aegis:"+"b"*32)
        self.assertEqual(data["legal"]["lex_review_ref"],"lex:"+"c"*32)
        serialized=json.dumps(data,sort_keys=True).lower()
        for forbidden in ("authority_level","decision","approved_by","budget_limit"):
            self.assertNotIn(forbidden,serialized)

    def test_sensitive_fields_use_opaque_refs_and_direct_pii_or_secrets_fail_closed(self):
        data=scenario("privacy")
        self.assertTrue(data["human_rejected"])
        self.assertTrue(data["credential_rejected"])
        self.assertRegex(data["valid"]["credentials_ref"],r"^credential:[a-f0-9]{32}$")

    def test_lifecycle_renewal_health_and_freshness_are_closed_and_deterministic(self):
        data=scenario("states")
        self.assertEqual(data["stale"]["health"],"degraded")
        self.assertEqual(data["stale"]["freshness"]["state"],"stale")
        self.assertEqual(data["unknown"]["health"],"unknown")
        self.assertTrue(data["false_healthy_rejected"])
        self.assertTrue(data["false_sla_rejected"])
        self.assertTrue(data["bad_dates_rejected"])

    def test_offboarding_is_a_requirements_contract_without_execution(self):
        data=scenario("offboarding"); row=data["valid"]
        self.assertEqual(row["lifecycle"],"offboarding")
        for field in ("export_ref","revoke_ref","retention_ref","continuity_ref","evidence_ref"):
            self.assertRegex(row["offboarding"][field],r"^[a-z]+:[a-f0-9]{32}$")
        self.assertTrue(data["empty_rejected"])
        self.assertTrue(data["incomplete_rejected"])

    def test_core_has_no_procurement_payment_provider_persistence_or_queue(self):
        data=scenario("surface")
        self.assertEqual(data["public"],["normalize"])
        source=data["source"].lower()
        for forbidden in ("curl_","factoryrunner","scheduler","purchase(","pay(","pdo","mysqli","file_put_contents"):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()
