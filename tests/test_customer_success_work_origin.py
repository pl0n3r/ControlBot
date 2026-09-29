import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"customer_success_work_origin_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class CustomerSuccessWorkOriginTests(unittest.TestCase):
    def test_support_and_health_exception_build_factory_work_item_scope(self):
        d=scenario("scope")
        for key in ("support","health"):
            item=d[key]
            self.assertEqual((item["origin_mode"],item["origin_system"],item["producer_ref"]),("automatic","controlbot","controlbot:customer-success"))
            self.assertEqual(item["venture_id"],"venture-condor")
        self.assertEqual(d["support"]["severity"],"critical")
        self.assertTrue(d["cross"])

    def test_authority_priority_type_capabilities_roles_and_policy_are_explicit_inputs(self):
        item=scenario("explicit")
        self.assertEqual(item["severity"],"critical")
        self.assertEqual(item["priority_class"],"medium")
        self.assertEqual(item["authority_level"],"l2_team")
        self.assertEqual(item["work_type"],"knowledge_documentation")
        self.assertEqual(item["requested_capabilities"],["support_analysis"])
        self.assertEqual(item["required_roles"],["contenido"])
        self.assertEqual(item["policy_ref"],"factory:customer-success-v1")

    def test_evidence_and_idempotency_are_deterministic_and_pii_free(self):
        d=scenario("deterministic")
        self.assertEqual(d["first"]["idempotency_key"],d["second"]["idempotency_key"])
        self.assertEqual(d["first"]["evidence_refs"],d["second"]["evidence_refs"])
        self.assertNotEqual(d["first"]["idempotency_key"],d["changed"]["idempotency_key"])
        payload=json.dumps(d["first"]).lower()
        for forbidden in ("email","phone","transcript","message_body","ticket_body"):
            self.assertNotIn(forbidden,payload)

    def test_observed_at_is_required_and_limited_evidence_never_becomes_ready_or_authorized(self):
        d=scenario("limited")
        self.assertTrue(d["missing_observed"])
        for key in ("unknown","stale","churn"):
            item=d[key]
            self.assertEqual(item["observed_at"],"2026-09-29T19:30:00Z")
            for forbidden in ("ready","approved","authorized","executor","provider","model"):
                self.assertNotIn(forbidden,item)

    def test_factory_optional_refs_are_preserved_without_execution_fields(self):
        item=scenario("optional")
        self.assertEqual(item["project_id"],"controlbot")
        self.assertEqual(item["repository_ref"],"pl0n3r/ControlBot")
        self.assertEqual(item["budget_ref"],"capital:budget-1")
        self.assertEqual(item["approval_ref"],"owner:approval-1")
        for forbidden in ("provider","model","executor","dispatch"):
            self.assertNotIn(forbidden,item)

    def test_bridge_has_no_factory_calls_persistence_ranking_scheduler_notifications_or_parallel_queue(self):
        self.assertEqual(scenario("pure")["methods"],["fromHealthException","fromSupportSignal"])
        source=(ROOT/"src"/"CustomerSuccessWorkOrigin.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","mysqli","pdo(","factoryrunner","scheduler","notify","notification","rank(","dispatch(","work_queue"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()
