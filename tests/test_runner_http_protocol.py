import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def protocol(envelope: dict) -> dict:
    encoded = base64.b64encode(
        json.dumps(envelope, separators=(",", ":")).encode()
    ).decode()
    script = f"""
require getcwd() . '/src/RunnerGateway.php';
require getcwd() . '/src/RunnerHttpProtocol.php';
$input = json_decode(base64_decode('{encoded}'), true, 512, JSON_THROW_ON_ERROR);
try {{
    $result = \\ControlBot\\Runner\\RunnerHttpProtocol::request($input);
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


class RunnerHttpProtocolTests(unittest.TestCase):
    runner_id = "11111111-1111-7111-8111-111111111111"
    order_id = "22222222-2222-4222-8222-222222222222"
    attempt_id = "44444444-4444-7444-8444-444444444444"

    def envelope(self, path: str, payload: dict) -> dict:
        return {
            "version": 1,
            "method": "POST",
            "path": path,
            "payload": payload,
        }

    def test_heartbeat_poll_ack_and_event_envelopes_are_closed_versioned_and_secret_free(self):
        cases = [
            self.envelope(
                "/v1/runner/heartbeat",
                {
                    "version": 1,
                    "runner_id": self.runner_id,
                    "sequence": 1,
                    "observed_at": 1000,
                    "status": "ready",
                    "capacity": {"max": 2, "active": 0},
                    "active_sessions": [],
                },
            ),
            self.envelope(
                "/v1/runner/poll",
                {
                    "version": 1,
                    "runner_id": self.runner_id,
                    "session_id": "session_001",
                    "generation": 1,
                    "requested_at": 1001,
                },
            ),
            self.envelope(
                "/v1/runner/ack",
                {
                    "version": 1,
                    "order_id": self.order_id,
                    "attempt_id": self.attempt_id,
                    "runner_id": self.runner_id,
                    "generation": 1,
                    "acknowledged_at": 1002,
                },
            ),
            self.envelope(
                "/v1/runner/event",
                {
                    "version": 1,
                    "event_id": "33333333-3333-4333-8333-333333333333",
                    "order_id": self.order_id,
                    "attempt_id": self.attempt_id,
                    "runner_id": self.runner_id,
                    "generation": 1,
                    "sequence": 1,
                    "state": "accepted",
                    "occurred_at": 1003,
                    "evidence": {
                        "code": "ok",
                        "summary": "accepted by runner",
                        "ref": None,
                    },
                },
            ),
        ]

        for envelope in cases:
            with self.subTest(path=envelope["path"]):
                data = protocol(envelope)
                self.assertTrue(data["ok"], data)
                result = data["result"]
                self.assertEqual(result["version"], 1)
                self.assertEqual(result["method"], "POST")
                self.assertEqual(result["path"], envelope["path"])
                self.assertFalse(result["execution"])
                self.assertEqual(result["payload"]["version"], 1)
                serialized = json.dumps(result).lower()
                for forbidden in [
                    "password=",
                    "token=",
                    "secret=",
                    "authorization=",
                    "cookie=",
                    "private_key=",
                    "api_key=",
                ]:
                    self.assertNotIn(forbidden, serialized)

    def test_invalid_method_path_payload_secret_or_unknown_field_fails_closed_without_side_effects(self):
        base_poll = self.envelope(
            "/v1/runner/poll",
            {
                "version": 1,
                "runner_id": self.runner_id,
                "session_id": "session_001",
                "generation": 1,
                "requested_at": 1001,
            },
        )
        invalid = {
            "method": {**base_poll, "method": "GET"},
            "path": {**base_poll, "path": "/v1/runner/unknown"},
            "version": {**base_poll, "version": 2},
            "top_unknown": {**base_poll, "debug": True},
            "payload_unknown": {
                **base_poll,
                "payload": {**base_poll["payload"], "debug": True},
            },
            "secret_key": {
                **base_poll,
                "payload": {**base_poll["payload"], "token": "opaque-value"},
            },
            "secret_value": {
                **base_poll,
                "payload": {**base_poll["payload"], "session_id": "token=supersecret"},
            },
        }

        for name, envelope in invalid.items():
            with self.subTest(case=name):
                data = protocol(envelope)
                self.assertFalse(data["ok"], data)
                self.assertEqual(data["class"], "InvalidArgumentException")
                self.assertNotIn("result", data)

        source = (ROOT / "src" / "RunnerHttpProtocol.php").read_text()
        for forbidden_call in [
            "curl_",
            "file_put_contents",
            "fopen(",
            "fsockopen",
            "stream_socket_client",
            "new PDO",
            "mysqli_",
        ]:
            self.assertNotIn(forbidden_call, source)
        self.assertIn("'execution' => false", source)


if __name__ == "__main__":
    unittest.main()
