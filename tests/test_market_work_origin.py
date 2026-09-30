import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"market_work_origin_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

REQUIRED={"work_id","origin_mode","origin_system","group_id","work_type","requested_capabilities","required_roles","authority_level","priority_class","depends_on","claims","policy_ref","evidence_refs","idempotency_key"}
ALLOWED=REQUIRED|{"producer_ref","requested_by","venture_id","project_id","repository_ref","severity","budget_ref","approval_ref","observed_at"}
OPAQUE_EVIDENCE=r"^controlbot:[a-z][a-z0-9-]{1,31}/[a-f0-9]{32}$"

class MarketWorkOriginTests(unittest.TestCase):
    def test_fresh_blocked_gap_materializes_factory_work_item_v1(self):
        d=scenario("fresh")
        self.assertEqual(d["status"],"materialized")
        self.assertEqual(d["unresolved_domains"],[])
        self.assertFalse(d["execution"])
        self.assertEqual(len(d["work_items"]),1)
        item=d["work_items"][0]
        self.assertEqual(item["origin_mode"],"automatic")
        self.assertEqual(item["origin_system"],"controlbot")
        self.assertEqual(item["producer_ref"],"controlbot:market-work-origin")
        self.assertTrue(any(x.endswith("/CO/payments") for x in item["claims"]))

    def test_unknown_stale_invalid_scope_profiles_and_extra_fields_fail_closed(self):
        d=scenario("closed")
        for key in ("unknown","stale"):
            self.assertEqual(d[key]["status"],"blocked")
            self.assertEqual(d[key]["work_items"],[])
            self.assertTrue(d[key]["unresolved_domains"])
        for key in ("scope","profile","extra"):
            self.assertTrue(d[key],key)

    def test_context_evidence_refs_require_canonical_opaque_refs(self):
        item=scenario("context_evidence")["valid"]["work_items"][0]
        expected={
            "controlbot:market-evidence/"+"a"*32,
            "controlbot:market-evidence/"+"b"*32,
        }
        self.assertTrue(expected.issubset(item["evidence_refs"]))
        self.assertEqual(item["evidence_refs"],sorted(set(item["evidence_refs"])))
        for ref in item["evidence_refs"]:
            self.assertRegex(ref,OPAQUE_EVIDENCE)

    def test_context_evidence_refs_reject_pii_secrets_free_text_malformed_and_duplicates(self):
        d=scenario("context_evidence")
        for key in ("phone","email","free_text","secret","malformed","duplicate"):
            self.assertTrue(d[key],key)

    def test_work_item_matches_factory_fields_without_freshness(self):
        item=scenario("fresh")["work_items"][0]
        self.assertTrue(REQUIRED.issubset(item))
        self.assertTrue(set(item).issubset(ALLOWED))
        self.assertNotIn("freshness",item)
        self.assertRegex(item["observed_at"],r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")

    def test_authority_budget_policy_and_approval_are_pass_through_only(self):
        item=scenario("governance")["work_items"][0]
        self.assertEqual(item["authority_level"],"owner_required")
        self.assertEqual(item["policy_ref"],"controlbot:policy/business-os-v1")
        self.assertEqual(item["approval_ref"],"controlbot:approval/market-co")
        self.assertEqual(item["budget_ref"],"controlbot:budget/market-co")

    def test_idempotency_claims_and_evidence_are_deterministic_per_gap(self):
        d=scenario("deterministic")
        self.assertEqual(d["first"],d["second"])
        items=d["first"]["work_items"]
        self.assertEqual(len(items),2)
        self.assertEqual(len({x["work_id"] for x in items}),2)
        self.assertEqual(len({x["idempotency_key"] for x in items}),2)
        for item in items:
            self.assertEqual(item["claims"],sorted(set(item["claims"])))
            self.assertEqual(item["evidence_refs"],sorted(set(item["evidence_refs"])))

    def test_e2e_uses_public_market_scope_and_readiness_contracts(self):
        d=scenario("e2e")
        self.assertEqual(d["market"]["market_id"],"market-condor-co")
        self.assertEqual(d["mixed"]["status"],"materialized")
        self.assertEqual(d["mixed"]["unresolved_domains"],["support"])
        self.assertEqual(len(d["mixed"]["work_items"]),1)
        self.assertEqual(d["ready"]["status"],"no_work")
        self.assertEqual(d["ready"]["work_items"],[])

    def test_no_parallel_queue_dispatch_execution_secrets_or_pii(self):
        d=scenario("fresh")
        self.assertFalse(d["execution"])
        source=(ROOT/"src"/"MarketWorkOrigin.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","mysqli","pdo(","factoryrunner","scheduler","dispatcher","file_put_contents","shell_exec","exec("):
            self.assertNotIn(forbidden,source)
        self.assertNotIn("@",json.dumps(d))
        rejected=scenario("context_evidence")
        for key in ("phone","email","free_text","secret","malformed","duplicate"):
            self.assertTrue(rejected[key],key)

if __name__=="__main__": unittest.main()
