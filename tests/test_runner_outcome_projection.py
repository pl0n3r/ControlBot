import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def project(delivery, event, now=1_100, stale_after=300):
    payload = {
        "delivery": delivery,
        "event": event,
        "now": now,
        "stale_after": stale_after,
    }
    encoded = base64.b64encode(
        json.dumps(payload, separators=(",", ":")).encode()
    ).decode()
    script = f"""
require getcwd() . '/src/RunnerGateway.php';
require getcwd() . '/src/RunnerOutcomeProjection.php';
$input = json_decode(base64_decode('{encoded}'), true, 512, JSON_THROW_ON_ERROR);
try {{
    $result = \\ControlBot\\Runner\\RunnerOutcomeProjection::project(
        $input['delivery'],
        $input['event'],
        $input['now'],
        $input['stale_after']
    );
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
}} catch (Throwable $error) {{
    echo json_encode([
        'ok' => false,
        'class' => get_class($error),
        'message' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR);
}}
"""
    completed = subprocess.run(
        ["php"],
        cwd=ROOT,
        input="<?php\n" + script,
        text=True,
        capture_output=True,
        check=True,
    )
    return json.loads(completed.stdout)


class RunnerOutcomeProjectionTests(unittest.TestCase):
    runner_id = "11111111-1111-7111-8111-111111111111"
    order_id = "22222222-2222-4222-8222-222222222222"
    attempt_id = "33333333-3333-4333-8333-333333333333"

    def delivery(self, **overrides):
        value = {
            "version": 1,
            "session_id": "session_730",
            "assignment_id": "assignment_730",
            "runner_id": self.runner_id,
            "generation": 7,
            "order_id": self.order_id,
            "attempt_id": self.attempt_id,
            "work_item_id": "pl0n3r/ControlBot#730",
            "order_fingerprint": "a" * 64,
            "execution": False,
        }
        value.update(overrides)
        return value

    def event(self, state="accepted", **overrides):
        value = {
            "version": 1,
            "event_id": "44444444-4444-4444-8444-444444444444",
            "order_id": self.order_id,
            "attempt_id": self.attempt_id,
            "runner_id": self.runner_id,
            "generation": 7,
            "sequence": 1,
            "state": state,
            "occurred_at": 1_050,
            "evidence": {
                "code": "runner_event",
                "summary": f"runner state {state}",
                "ref": "controlbot:event/730",
            },
        }
        value.update(overrides)
        return value

    def test_terminal_and_nonterminal_events_project_delivery_ack_progress_and_outcome_with_provenance(self):
        expected = {
            "accepted": ("accepted", "UNKNOWN", False),
            "started": ("running", "UNKNOWN", False),
            "heartbeat": ("running", "UNKNOWN", False),
            "progress": ("in_progress", "UNKNOWN", False),
            "checkpoint": ("in_progress", "UNKNOWN", False),
            "waiting_human": ("waiting_human", "UNKNOWN", False),
            "blocked": ("blocked", "UNKNOWN", False),
            "completed": ("complete", "SUCCEEDED", True),
            "failed": ("complete", "FAILED", True),
            "cancelled": ("complete", "CANCELLED", True),
        }

        for state, (progress, outcome, terminal) in expected.items():
            with self.subTest(state=state):
                data = project(self.delivery(), self.event(state))
                self.assertTrue(data["ok"], data)
                result = data["result"]
                self.assertEqual(result["delivery"], "DELIVERED")
                self.assertEqual(result["ack"], "ACKNOWLEDGED")
                self.assertEqual(result["progress"], progress)
                self.assertEqual(result["outcome"], outcome)
                self.assertEqual(result["terminal"], terminal)
                self.assertEqual(result["freshness"], "fresh")
                self.assertEqual(result["session_id"], "session_730")
                self.assertEqual(result["assignment_id"], "assignment_730")
                self.assertEqual(result["order_id"], self.order_id)
                self.assertEqual(result["attempt_id"], self.attempt_id)
                self.assertEqual(result["runner_id"], self.runner_id)
                self.assertEqual(result["generation"], 7)
                self.assertFalse(result["execution"])
                self.assertEqual(result["provenance"]["state"], state)
                self.assertEqual(result["provenance"]["event_id"], "44444444-4444-4444-8444-444444444444")
                self.assertEqual(result["provenance"]["sequence"], 1)
                self.assertEqual(result["provenance"]["occurred_at"], 1_050)
                self.assertEqual(result["provenance"]["evidence_code"], "runner_event")
                self.assertEqual(result["provenance"]["evidence_ref"], "controlbot:event/730")

    def test_missing_stale_or_mismatched_event_remains_unknown_and_never_fabricates_success(self):
        cases = {
            "missing": (None, "missing"),
            "stale": (self.event("completed", occurred_at=700), "stale"),
            "future": (self.event("completed", occurred_at=1_200), "stale"),
            "order_mismatch": (
                self.event(
                    "completed",
                    order_id="55555555-5555-4555-8555-555555555555",
                ),
                "mismatched",
            ),
            "attempt_mismatch": (
                self.event(
                    "completed",
                    attempt_id="66666666-6666-4666-8666-666666666666",
                ),
                "mismatched",
            ),
            "runner_mismatch": (
                self.event(
                    "completed",
                    runner_id="77777777-7777-4777-8777-777777777777",
                ),
                "mismatched",
            ),
            "generation_mismatch": (
                self.event("completed", generation=8),
                "mismatched",
            ),
        }

        for name, (event, freshness) in cases.items():
            with self.subTest(case=name):
                data = project(self.delivery(), event)
                self.assertTrue(data["ok"], data)
                result = data["result"]
                self.assertEqual(result["delivery"], "UNKNOWN")
                self.assertEqual(result["ack"], "UNKNOWN")
                self.assertEqual(result["progress"], "UNKNOWN")
                self.assertEqual(result["outcome"], "UNKNOWN")
                self.assertFalse(result["terminal"])
                self.assertEqual(result["freshness"], freshness)
                self.assertNotEqual(result["outcome"], "SUCCEEDED")

        source = (ROOT / "src" / "RunnerOutcomeProjection.php").read_text()
        for forbidden in [
            "curl_",
            "file_put_contents",
            "fopen(",
            "fsockopen",
            "stream_socket_client",
            "new PDO",
            "mysqli_",
        ]:
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
