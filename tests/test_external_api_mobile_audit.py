import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"external_api_mobile_audit_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if run.returncode:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed")
    return json.loads(run.stdout)

class ExternalApiMobileAuditTests(unittest.TestCase):
    def test_event_derives_security_context_only_from_verified_inputs(self):
        d=scenario("derived")
        self.assertEqual(d["identity_id"],"identity-owner")
        self.assertEqual(d["authority_level"],"L4_OWNER")
        self.assertEqual(d["policy_refs"],["controlbot:policy/external-owner-v1"])
        self.assertEqual(d["capability"],"owner.cockpit.read")
        self.assertEqual(d["scope"],"venture:alpha")
        self.assertRegex(d["device_ref"],r"^device:[a-f0-9]{32}$")
        self.assertRegex(d["session_ref"],r"^session:[a-f0-9]{32}$")

    def test_request_ids_operation_authorization_timestamp_and_outcome_are_traced(self):
        d=scenario("trace")
        self.assertEqual(d["request_id"],"a"*32)
        self.assertEqual(d["correlation_id"],"b"*32)
        self.assertEqual(d["occurred_at"],9000)
        self.assertEqual(d["operation_id"],"owner_inbox.read")
        self.assertEqual(d["authorization_decision"],"allow")
        self.assertEqual(d["authorization_reasons"],["authorized"])
        self.assertEqual(d["outcome"],"read_served")

    def test_horizontal_and_vertical_authorization_fail_closed(self):
        d=scenario("authz")
        self.assertTrue(d["horizontal"])
        self.assertTrue(d["vertical"])

    def test_outcome_refs_are_required_without_executing_or_inventing_success(self):
        d=scenario("refs")
        self.assertEqual(d["accepted"]["decision_ref"],"controlbot:decision/abc")
        self.assertEqual(d["handoff"]["work_item_ref"],"controlbot:work/item-abc")
        self.assertEqual(d["verified"]["result_ref"],"controlbot:result/abc")
        self.assertTrue(d["missing_decision"] and d["missing_work"] and d["missing_result"])
        self.assertEqual(d["accepted"]["operation_id"],"owner_decision.decide")

    def test_event_has_no_secrets_payload_ip_user_agent_fingerprint_or_extra_fields(self):
        d=scenario("sensitive")
        payload=json.dumps(d["event"],sort_keys=True).lower()
        for forbidden in ("password","secret","token","cookie","ip_address","user_agent","fingerprint","request_payload","response_payload"):
            self.assertNotIn(forbidden,payload)
        self.assertTrue(d["extra"] and d["secret_ref"])

    def test_threat_model_is_mapped_and_contract_has_no_storage_network_provider_or_execution(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["event"])
        source=d["source"].lower()
        for forbidden in ("pdo(","mysqli","curl_","http://","https://","factoryrunner","scheduler","siem","syslog"):
            self.assertNotIn(forbidden,source)
        threat=(ROOT/"docs"/"external-api-mobile-threat-model-v1.md").read_text(encoding="utf-8").lower()
        for required in ("assets","trust boundaries","horizontal authorization","vertical authorization","replay","stale/offline","push leakage","test mapping","residual risk"):
            self.assertIn(required,threat)

if __name__=="__main__":
    unittest.main()
