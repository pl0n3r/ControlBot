import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
require getcwd() . '/src/RunnerGateway.php';
require getcwd() . '/src/RunnerOutcomeProjection.php';
$i = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
try {
    $r = \ControlBot\Runner\RunnerOutcomeProjection::project(
        $i['delivery'], $i['event'], $i['now'], $i['stale_after']
    );
    echo json_encode(['ok' => true, 'result' => $r], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
"""


def project(delivery, event, now=1_100, stale_after=300):
    payload = json.dumps(
        {"delivery": delivery, "event": event, "now": now, "stale_after": stale_after},
        separators=(",", ":"),
    )
    run = subprocess.run(
        ["php", "-r", PHP, payload],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=True,
    )
    return json.loads(run.stdout)


class RunnerOutcomeProjectionTests(unittest.TestCase):
    RUNNER = "11111111-1111-7111-8111-111111111111"
    ORDER = "22222222-2222-4222-8222-222222222222"
    ATTEMPT = "33333333-3333-4333-8333-333333333333"
    EVENT = "44444444-4444-4444-8444-444444444444"

    def fixtures(self, state="accepted"):
        delivery = {
            "version": 1,
            "session_id": "session_730",
            "assignment_id": "assignment_730",
            "runner_id": self.RUNNER,
            "generation": 7,
            "order_id": self.ORDER,
            "attempt_id": self.ATTEMPT,
            "work_item_id": "pl0n3r/ControlBot#730",
            "order_fingerprint": "a" * 64,
            "execution": False,
        }
        event = {
            "version": 1,
            "event_id": self.EVENT,
            "order_id": self.ORDER,
            "attempt_id": self.ATTEMPT,
            "runner_id": self.RUNNER,
            "generation": 7,
            "sequence": 1,
            "state": state,
            "occurred_at": 1_050,
            "evidence": {
                "code": "runner-event",
                "summary": f"runner state {state}",
                "ref": "controlbot:event/730",
            },
        }
        return delivery, event

    def test_terminal_and_nonterminal_events_project_delivery_ack_progress_and_outcome_with_provenance(self):
        states = {
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
        for state, expected in states.items():
            with self.subTest(state=state):
                delivery, event = self.fixtures(state)
                result = project(delivery, event)["result"]
                self.assertEqual((result["progress"], result["outcome"], result["terminal"]), expected)
                self.assertEqual(
                    (result["delivery"], result["ack"], result["freshness"], result["execution"]),
                    ("DELIVERED", "ACKNOWLEDGED", "fresh", False),
                )
                self.assertEqual(
                    (
                        result["session_id"], result["assignment_id"], result["order_id"],
                        result["attempt_id"], result["runner_id"], result["generation"],
                    ),
                    ("session_730", "assignment_730", self.ORDER, self.ATTEMPT, self.RUNNER, 7),
                )
                self.assertEqual(
                    (
                        result["provenance"]["event_id"], result["provenance"]["state"],
                        result["provenance"]["evidence_code"], result["provenance"]["evidence_ref"],
                    ),
                    (self.EVENT, state, "runner-event", "controlbot:event/730"),
                )

    def test_missing_stale_or_mismatched_event_remains_unknown_and_never_fabricates_success(self):
        delivery, event = self.fixtures("completed")
        variants = [
            ("missing", None),
            ("stale", {**event, "occurred_at": 700}),
            ("stale", {**event, "occurred_at": 1_200}),
            ("mismatched", {**event, "order_id": "55555555-5555-4555-8555-555555555555"}),
            ("mismatched", {**event, "attempt_id": "66666666-6666-4666-8666-666666666666"}),
            ("mismatched", {**event, "runner_id": "77777777-7777-4777-8777-777777777777"}),
            ("mismatched", {**event, "generation": 8}),
        ]
        for freshness, candidate in variants:
            with self.subTest(freshness=freshness, candidate=candidate):
                result = project(delivery, candidate)["result"]
                self.assertEqual(
                    (
                        result["delivery"], result["ack"], result["progress"],
                        result["outcome"], result["terminal"], result["freshness"],
                    ),
                    ("UNKNOWN", "UNKNOWN", "UNKNOWN", "UNKNOWN", False, freshness),
                )
                self.assertNotEqual(result["outcome"], "SUCCEEDED")

        source = (ROOT / "src" / "RunnerOutcomeProjection.php").read_text()
        for forbidden in (
            "curl_", "file_put_contents", "fopen(", "fsockopen",
            "stream_socket_client", "new PDO", "mysqli_",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
