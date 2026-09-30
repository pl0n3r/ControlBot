import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "ssh_transport_adapter_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=30,
    )
    return json.loads(run.stdout)

class SshTransportAdapterTests(unittest.TestCase):
    def test_valid_descriptor_builds_bounded_pinned_request(self):
        data=scenario("valid"); req=data["request"]
        self.assertEqual(data["calls"],1); self.assertTrue(data["secret_separate"])
        self.assertEqual((req["host"],req["port"],req["username"]),("example.internal",22,"deploy_user"))
        self.assertEqual(req["expected_fingerprint"],"SHA256:abcdefghijklmnop")
        self.assertEqual(req["operation_id"],"ssh.readonly")
        self.assertEqual(req["secret_kind"],"private_key")
        self.assertNotIn("command",req)

    def test_invalid_connection_context_fails_before_client(self):
        data=scenario("invalid")
        for case in data["cases"]:
            self.assertEqual(case["calls"],0)
            self.assertEqual(case["result"]["status"],"failed")
        self.assertEqual(data["resolver_calls"],1)
        self.assertEqual(data["resolver_client_calls"],0)
        self.assertEqual(data["resolved_username"]["status"],"failed")

    def test_shell_fields_and_unknown_operation_fail_closed(self):
        data=scenario("shell")
        for key in ("command","unknown"):
            self.assertEqual(data[key]["calls"],0)
            self.assertEqual(data[key]["result"]["code"],"ssh_descriptor_invalid")

    def test_secret_crosses_only_as_separate_client_argument(self):
        data=scenario("secret")
        self.assertEqual(data["calls"],1); self.assertTrue(data["secret_separate"])
        self.assertFalse(data["request_contains_secret"]); self.assertFalse(data["visible_contains_secret"])
        self.assertIn("[REDACTED]",data["result"]["summary"])
        self.assertEqual(data["artifact_result"]["status"],"failed")
        self.assertEqual(data["artifact_result"]["code"],"ssh_client_result_invalid")
        self.assertEqual(data["code_result"]["status"],"failed")
        self.assertEqual(data["code_result"]["code"],"ssh_client_result_invalid")
        self.assertFalse(data["code_visible_contains_secret"])

    def test_client_failures_never_normalize_to_success(self):
        data=scenario("failures")
        expected={"host_key_mismatch":"failed","auth_failure":"failed","timeout":"timed_out","partial":"partial","cancelled":"cancelled","exception":"failed"}
        for key,status in expected.items():
            self.assertEqual(data[key]["status"],status)
            self.assertNotEqual(data[key]["status"],"success")

    def test_adapter_uses_fake_client_without_network_or_process_execution(self):
        self.assertEqual(scenario("valid")["calls"],1)
        source=(ROOT/"src"/"SshTransportAdapter.php").read_text(encoding="utf-8")
        for forbidden in ("ssh2_","curl_","fsockopen","stream_socket_client","proc_open(","shell_exec(","exec(","system("):
            self.assertNotIn(forbidden,source)

if __name__ == "__main__":
    unittest.main()
