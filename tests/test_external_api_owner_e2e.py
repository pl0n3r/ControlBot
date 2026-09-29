import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_owner_e2e_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class ExternalApiOwnerE2ETests(unittest.TestCase):
    def test_owner_mobile_flow_preserves_traceability_end_to_end(self):
        d=scenario("flow")
        ids=d["request_ids"]
        self.assertTrue(d["approval_push"]["requires_authenticated_detail"])
        self.assertEqual(d["approval_push"]["detail_operation_id"],"owner_inbox.read")
        self.assertEqual(d["inbox"]["meta"]["request_id"],ids["request_id"])
        self.assertEqual(d["inbox"]["meta"]["correlation_id"],ids["correlation_id"])
        self.assertEqual(d["approved"]["audit"]["factory_handoff"]["request_id"],ids["request_id"])
        self.assertEqual(d["approved"]["audit"]["factory_handoff"]["correlation_id"],ids["correlation_id"])
        self.assertEqual(d["verification"]["request_id"],ids["request_id"])
        self.assertEqual(d["verification"]["correlation_id"],ids["correlation_id"])
        self.assertEqual(d["verification"]["work_item_ref"],d["approved"]["work_item_ref"])
        self.assertEqual(d["result_push"]["category"],"decision_result")

    def test_approve_uses_directed_work_origin_contract(self):
        d=scenario("origin")
        self.assertEqual(d["work_item"]["origin_mode"],"directed")
        self.assertEqual(d["work_item"]["origin_system"],"human")
        self.assertEqual(d["handoff"]["outcome"],"factory_handoff")
        self.assertEqual(d["handoff"]["work_item_ref"],d["work_item_ref"])
        self.assertTrue(d["work_item_ref"].startswith("controlbot:work/"))

    def test_reject_never_materializes_work_item(self):
        d=scenario("reject")
        self.assertEqual(d["decision"]["outcome"],"reject")
        self.assertIsNone(d["work_item"])
        self.assertIsNone(d["work_item_ref"])
        self.assertEqual(d["audit"]["mutation"]["outcome"],"mutation_rejected")
        self.assertIsNone(d["audit"]["factory_handoff"])

    def test_invalid_mobile_state_fails_closed_before_work_origin(self):
        d=scenario("invalid_state")
        self.assertEqual(d["stale"]["freshness_gate"],"fail")
        self.assertEqual(d["unknown"]["freshness_gate"],"fail")
        self.assertFalse(d["stale"]["queueable"])
        self.assertFalse(d["unknown"]["queueable"])
        self.assertFalse(d["work_origin_called_for_stale"])
        self.assertFalse(d["work_origin_called_for_unknown"])
        self.assertTrue(d["no_step"])
        self.assertTrue(d["revoked"])

    def test_decision_result_push_remains_minimal_and_authenticated(self):
        d=scenario("result_push")
        self.assertEqual(d["category"],"decision_result")
        self.assertEqual(d["detail_operation_id"],"owner_decision.read")
        self.assertTrue(d["requires_authenticated_detail"])
        for forbidden in ("title","body","amount","email","device_token","provider_credential","url"):
            self.assertNotIn(forbidden,d)
        self.assertFalse(any(str(v).startswith(("http://","https://")) for v in d.values()))

    def test_e2e_harness_has_no_external_io_or_parallel_execution(self):
        source=scenario("pure")["source"].lower()
        for forbidden in (
            "curl_init(","mysqli_connect(","new pdo","factoryrunner","shell_exec(",
            "exec(","proc_open(","http://","https://","apns","firebase","enqueue(",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()
