import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def data():
    r=subprocess.run(
        ["php",str(ROOT/"tests"/"guardrail_integration_scenarios.php")],
        cwd=ROOT,check=True,text=True,capture_output=True,timeout=60
    )
    return json.loads(r.stdout)

class GuardrailIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.d=data()

    def test_stuck_work_requests_pause_only_at_safe_point(self):
        waiting=self.d["waiting"]
        safe=self.d["safe"]
        self.assertEqual(waiting["execution_status"],"waiting_safe_point")
        self.assertEqual(waiting["pause_intent"]["action"],"wait_safe_point")
        self.assertEqual(waiting["pause_intent"]["type"],"runtime.session.pause")
        self.assertIsNone(waiting["checkpoint"])
        self.assertIsNone(waiting["requeue_intent"])
        self.assertEqual(safe["execution_status"],"pending_execution")
        self.assertEqual(safe["pause_intent"]["action"],"request_activation")
        self.assertEqual(safe["pause_intent"]["target_state"],"active")
        self.assertTrue(safe["pause_intent"]["safe_point_observed"])
        self.assertEqual(safe["pause_intent"]["state"]["state"],"unknown")
        self.assertEqual(safe["pause_intent"]["state"]["preemptibility"],"safe_point")
        self.assertIsNone(safe["pause_intent"]["state"]["activated_at"])
        self.assertIsNone(safe["pause_intent"]["state"]["safe_point_at"])

    def test_non_preemptible_escalates_without_automatic_pause(self):
        d=self.d["non_preemptible"]
        self.assertEqual(d["execution_status"],"waiting_owner")
        self.assertIsNone(d["pause_intent"])
        self.assertIsNone(d["requeue_intent"])
        self.assertEqual(d["escalation_intent"]["type"],"owner.guardrail_escalation")
        self.assertEqual(d["escalation_intent"]["reason"],"non_preemptible")

    def test_safe_pause_requeues_with_new_generation_and_handoff(self):
        d=self.d["safe"]
        nxt=d["requeue_intent"]["work_item"]
        self.assertEqual(d["requeue_intent"]["type"],"scheduler.work_requeue")
        self.assertEqual(d["requeue_intent"]["requires_receipts"],["pause","checkpoint"])
        self.assertEqual((nxt["generation"],nxt["attempt"]),(5,3))
        self.assertEqual(nxt["state"],"queued")
        self.assertIsNone(nxt["reservation_id"])
        self.assertIsNone(nxt["assigned_session_id"])
        self.assertEqual(d["checkpoint"],d["requeue_intent"]["handoff"])
        self.assertEqual(d["checkpoint"]["issue_ref"],"pl0n3r/ControlBot#111")
        self.assertEqual(d["requeue_intent"]["release_reservation"]["active"],False)
        self.assertEqual(
            (d["requeue_intent"]["fence"]["previous_generation"],d["requeue_intent"]["fence"]["next_generation"]),
            (4,5),
        )

    def test_stale_generation_events_cannot_complete_current_work(self):
        g=self.d["old_guard"]
        self.assertFalse(g["allowed"])
        self.assertIn("stale_generation",g["reasons"])
        self.assertIn("work_unowned",g["reasons"])

    def test_terminal_assignment_cannot_authorize_guardrail_requeue(self):
        self.assertTrue(self.d["stale_assignment"])

    def test_terminal_or_misdirected_session_cannot_authorize_guardrail_requeue(self):
        self.assertTrue(self.d["stale_session"])
        self.assertTrue(self.d["wrong_session_target"])

    def test_observability_deduplicates_and_resolves_fingerprint_alert(self):
        waiting=self.d["waiting"]
        self.assertEqual(waiting["alert_intent"]["type"],"observability.guardrail_alert")
        self.assertEqual(waiting["alert_intent"]["action"],"open")
        self.assertNotEqual(
            self.d["changed"]["alert_intent"]["previous_fingerprint"],
            self.d["changed"]["alert_intent"]["current_fingerprint"],
        )
        self.assertEqual(self.d["changed"]["alert_intent"]["action"],"replace")
        self.assertEqual(self.d["healthy"]["alert_intent"]["action"],"resolve")

    def test_partial_pause_checkpoint_requeue_failure_is_recoverable(self):
        partial=self.d["partial"]
        self.assertFalse(partial["complete"])
        self.assertFalse(partial["success"])
        self.assertTrue(partial["recoverable"])
        self.assertEqual(partial["phase"],"checkpoint_pending")
        self.assertTrue(self.d["done"]["complete"])
        self.assertTrue(self.d["done"]["success"])
        self.assertTrue(self.d["invalid_order"])
        source=(ROOT/"src"/"GuardrailIntegration.php").read_text(encoding="utf-8").lower()
        forbidden=("curl_","fsockopen","new pdo","mysqli","file_put_contents","shell_exec","proc_open","exec(","system(")
        self.assertFalse(any(x in source for x in forbidden))

if __name__=="__main__":
    unittest.main()
