import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def case(name):
    return json.loads(subprocess.run(
        ["php",str(ROOT/"tests"/"vendor_work_origin_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True).stdout)

class VendorWorkOriginTests(unittest.TestCase):
    def test_risk_and_lifecycle_build_factory_work_item_scope(self):
        rows=case("scope")
        actual={(v["origin_mode"],v["origin_system"],v["venture_id"],v["producer_ref"],v["observed_at"]) for v in rows.values()}
        self.assertEqual(actual,{("automatic","controlbot","venture-condor","controlbot:vendor-governance","2023-11-14T22:13:20Z")})

    def test_authority_priority_type_capabilities_roles_and_policy_are_explicit_inputs(self):
        a,b=case("explicit").values()
        self.assertEqual((a["work_type"],a["priority_class"],a["authority_level"]),("operations","high","operational"))
        self.assertEqual((b["work_type"],b["priority_class"],b["authority_level"],b["requested_capabilities"],b["required_roles"],b["policy_ref"]),
                         ("compliance_review","medium","owner",["vendor_review"],["legal-privacidad"],"factory:vendor-policy"))

    def test_evidence_and_idempotency_are_deterministic_without_sensitive_payloads(self):
        a,b=case("idempotency").values()
        self.assertEqual(a["idempotency_key"],b["idempotency_key"])
        self.assertEqual(a["evidence_refs"],sorted(a["evidence_refs"]))
        encoded=json.dumps(a,sort_keys=True).lower()
        self.assertFalse(any(secret in encoded for secret in ("credential:","password","token")))
        self.assertTrue(any(ref.startswith("vendor:") for ref in a["evidence_refs"]))

    def test_observed_at_and_limited_evidence_fail_closed_without_readiness(self):
        data=case("limited"); self.assertTrue(data["unknown_rejected"])
        stale=data["stale"]; self.assertEqual(stale["observed_at"],"2023-11-14T22:13:20Z")
        self.assertFalse({"ready","approved","authorized","readiness"} & stale.keys())

    def test_factory_optional_refs_preserve_economic_authority_boundaries(self):
        data=case("optional"); item=data["good"]
        self.assertEqual(tuple(item[k] for k in ("project_id","repository_ref","budget_ref","approval_ref")),
                         ("controlbot","pl0n3r/ControlBot","capital:vendor-budget","owner-decision:275"))
        self.assertTrue(data["bad_execution_field"])
        self.assertFalse({"provider","model","executor","dispatcher","ready","authorized"} & item.keys())

    def test_bridge_has_no_factory_calls_procurement_persistence_scheduler_or_parallel_queue(self):
        data=case("pure"); self.assertEqual(data["methods"],["fromLifecycle","fromRisk"])
        self.assertTrue(data["active_lifecycle_rejected"])
        source=(ROOT/"src"/"VendorWorkOrigin.php").read_text().lower()
        self.assertFalse(any(word in source for word in ("curl_","http://","https://","mysqli","pdo(","scheduler","dispatcher","ranking","purchase","payment","factoryrunner")))

if __name__=="__main__": unittest.main()
