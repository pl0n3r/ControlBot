import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def bind(session, assignment, order, target, now=1_050, existing=None):
    payload = {
        "session": session,
        "assignment": assignment,
        "order": order,
        "target": target,
        "now": now,
        "existing": existing,
    }
    encoded = base64.b64encode(
        json.dumps(payload, separators=(",", ":")).encode()
    ).decode()
    script = f"""
require getcwd() . '/src/AgentRuntime.php';
require getcwd() . '/src/RunnerGateway.php';
require getcwd() . '/src/RunnerSessionDelivery.php';
$input = json_decode(base64_decode('{encoded}'), true, 512, JSON_THROW_ON_ERROR);
try {{
    $result = \\ControlBot\\Runner\\RunnerSessionDelivery::bind(
        $input['session'],
        $input['assignment'],
        $input['order'],
        $input['target'],
        $input['now'],
        $input['existing']
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


class RunnerSessionDeliveryTests(unittest.TestCase):
    runner_id = "11111111-1111-7111-8111-111111111111"
    order_id = "22222222-2222-4222-8222-222222222222"
    attempt_id = "33333333-3333-4333-8333-333333333333"

    def session(self, **overrides):
        value = {
            "version": 1,
            "session_id": "session_729",
            "agent_id": "agent_729",
            "account_id": "account_729",
            "profile_alias": "profile729",
            "tab_id": "tab_729",
            "status": "assigned",
            "assignment_id": "assignment_729",
            "last_heartbeat_at": 1_000,
            "mode": "work",
            "repository": "pl0n3r/ControlBot",
            "issue_number": 729,
        }
        value.update(overrides)
        return value

    def assignment(self, **overrides):
        value = {
            "version": 1,
            "assignment_id": "assignment_729",
            "session_id": "session_729",
            "project_id": "controlbot",
            "source_ref": "pl0n3r/ControlBot#729",
            "status": "assigned",
        }
        value.update(overrides)
        return value

    def order(self, **overrides):
        value = {
            "version": 1,
            "order_id": self.order_id,
            "attempt_id": self.attempt_id,
            "generation": 7,
            "work_item_id": "pl0n3r/ControlBot#729",
            "runner_id": self.runner_id,
            "capability": "github",
            "attempt": 1,
            "scope": "controlbot",
            "issued_at": 1_010,
            "expires_at": 2_000,
            "instruction_ref": "controlbot:issue/729",
        }
        value.update(overrides)
        return value

    def target(self, **overrides):
        value = {
            "version": 1,
            "session_id": "session_729",
            "assignment_id": "assignment_729",
            "runner_id": self.runner_id,
            "generation": 7,
        }
        value.update(overrides)
        return value

    def test_order_binds_exact_session_assignment_runner_generation_and_is_idempotent(self):
        first = bind(
            self.session(),
            self.assignment(),
            self.order(),
            self.target(),
        )
        self.assertTrue(first["ok"], first)
        result = first["result"]
        self.assertEqual(
            set(result),
            {
                "version",
                "session_id",
                "assignment_id",
                "runner_id",
                "generation",
                "order_id",
                "attempt_id",
                "work_item_id",
                "order_fingerprint",
                "execution",
            },
        )
        self.assertEqual(result["session_id"], "session_729")
        self.assertEqual(result["assignment_id"], "assignment_729")
        self.assertEqual(result["runner_id"], self.runner_id)
        self.assertEqual(result["generation"], 7)
        self.assertEqual(result["order_id"], self.order_id)
        self.assertEqual(result["attempt_id"], self.attempt_id)
        self.assertEqual(result["work_item_id"], "pl0n3r/ControlBot#729")
        self.assertRegex(result["order_fingerprint"], r"^[0-9a-f]{64}$")
        self.assertFalse(result["execution"])

        retry = bind(
            self.session(),
            self.assignment(),
            self.order(),
            self.target(),
            existing=result,
        )
        self.assertTrue(retry["ok"], retry)
        self.assertEqual(retry["result"], result)

    def test_stale_offline_mismatched_or_reassigned_session_fails_closed_without_duplicate_delivery(self):
        valid = bind(
            self.session(),
            self.assignment(),
            self.order(),
            self.target(),
        )["result"]

        invalid = {
            "stale": (
                self.session(last_heartbeat_at=800),
                self.assignment(),
                self.order(),
                self.target(),
                None,
            ),
            "offline": (
                self.session(status="offline"),
                self.assignment(),
                self.order(),
                self.target(),
                None,
            ),
            "runner_mismatch": (
                self.session(),
                self.assignment(),
                self.order(),
                self.target(runner_id="aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa"),
                None,
            ),
            "generation_mismatch": (
                self.session(),
                self.assignment(),
                self.order(),
                self.target(generation=8),
                None,
            ),
            "reassigned_session": (
                self.session(assignment_id="assignment_other"),
                self.assignment(),
                self.order(),
                self.target(),
                None,
            ),
            "assignment_session_mismatch": (
                self.session(),
                self.assignment(session_id="session_other"),
                self.order(),
                self.target(),
                None,
            ),
            "work_item_mismatch": (
                self.session(),
                self.assignment(),
                self.order(work_item_id="pl0n3r/ControlBot#730"),
                self.target(),
                None,
            ),
            "duplicate_conflict": (
                self.session(),
                self.assignment(),
                self.order(order_id="44444444-4444-4444-8444-444444444444"),
                self.target(),
                valid,
            ),
        }

        for name, (session, assignment, order, target, existing) in invalid.items():
            with self.subTest(case=name):
                data = bind(
                    session,
                    assignment,
                    order,
                    target,
                    existing=existing,
                )
                self.assertFalse(data["ok"], data)
                self.assertEqual(data["class"], "InvalidArgumentException")
                self.assertNotIn("result", data)

        source = (ROOT / "src" / "RunnerSessionDelivery.php").read_text()
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
