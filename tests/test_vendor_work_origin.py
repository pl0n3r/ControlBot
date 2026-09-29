import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"vendor_work_origin_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True
    )
    return json.loads(run.stdout)

class VendorWorkOriginTests(unittest.TestCase):
    def test_risk_and_lifecycle_build_factory_work_item_scope(self):
        data=scenario("scope")
        for item in data.values():
            self.assertEqual((item["origin_mode"],item["origin_system"],item["venture_id"]),
                             ("automatic","controlbot","venture-condor"))
            self.assertEqual(item["producer_ref"],"controlbot:vendor-governance")
            self.assertEqual(item["observed_at"],"2023-11-14T22:13:20Z")

    def test_authority_priority_type_capabilities_roles_and_policy_are_explicit_inputs(self):
        data=scenario("explicit")
        first,second=data["first"],data["second"]
        self.assertEqual((first["work_type"],first["priority_class"],first["authority_level"]),
                         ("operations","high","operational"))
        self.assertEqual((second["work_type"],second["priority_class"],second["authority_level"]),
                         ("compliance_review","medium","owner"))
        self.assertEqual(second["requested_capabilities"],["vendor_review"])
        self.assertEqual(second["required_roles"],["legal-privacidad"])
        self.assertEqual(second["policy_ref"],"factory:vendor-policy")

    def test_evidence_and_idempotency_are_deterministic_without_sensitive_payloads(self):
        data=scenario("idempotency")
        a,b=data["a"],data["b"]
        self.assertEqual(a["idempotency_key"],b["idempotency_key"])
        self.assertEqual(a["evidence_refs"],sorted(a["evidence_refs"]))
        serialized=json.dumps(a,sort_keys=True)
        self.assertNotIn("credential:",serialized)
        self.assertNotIn("password",serialized.lower())
        self.assertNotIn("token",serialized.lower())
        self.assertTrue(any(ref.startswith("vendor:") for ref in a["evidence_refs"]))
        self.assertTrue(any(ref.startswith("evidence:") for ref in a["evidence_refs"]))

    def test_observed_at_and_limited_evidence_fail_closed_without_readiness(self):
        data=scenario("limited")
        self.assertTrue(data["unknown_rejected"])
        stale=data["stale"]
        self.assertEqual(stale["observed_at"],"2023-11-14T22:13:20Z")
        for forbidden in ("ready","approved","authorized","readiness"):
            self.assertNotIn(forbidden,stale)

    def test_factory_optional_refs_preserve_economic_authority_boundaries(self):
        data=scenario("optional")
        item=data["good"]
        self.assertEqual((item["project_id"],item["repository_ref"]),
                         ("controlbot","pl0n3r/ControlBot"))
        self.assertEqual((item["budget_ref"],item["approval_ref"]),
                         ("capital:vendor-budget","owner-decision:275"))
        self.assertTrue(data["bad_execution_field"])
        for forbidden in ("provider","model","executor","dispatcher","ready","authorized"):
            self.assertNotIn(forbidden,item)

    def test_bridge_has_no_factory_calls_procurement_persistence_scheduler_or_parallel_queue(self):
        data=scenario("pure")
        self.assertEqual(data["methods"],["fromLifecycle","fromRisk"])
        self.assertTrue(data["active_lifecycle_rejected"])
        source=(ROOT/"src"/"VendorWorkOrigin.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_","http://","https://","mysqli","pdo(","scheduler","dispatcher","ranking",
            "purchase","payment","renewprovider","factoryrunner"
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
