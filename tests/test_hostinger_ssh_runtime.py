import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "hostinger_ssh_runtime_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class HostingerSshRuntimeTests(unittest.TestCase):
    def test_authorized_readonly_executes_full_privileged_chain(self):
        data = scenario("valid")
        self.assertTrue(data["result"]["accepted"])
        self.assertEqual(data["result"]["result"]["status"], "success")
        self.assertEqual(data["client_calls"], 1)
        self.assertTrue(data["secret_seen"])

    def test_grant_profile_or_secret_mismatch_stops_before_ssh_client(self):
        data = scenario("preclient")
        for case in data.values():
            self.assertFalse(case["result"]["accepted"])
            self.assertEqual(case["client_calls"], 0)

    def test_identity_failures_stop_before_client_without_username_leak(self):
        data = scenario("identity")
        for case in data.values():
            self.assertEqual(case["client_calls"], 0)
            encoded = json.dumps(case["result"])
            self.assertNotIn("deploy_user_539", encoded)
            self.assertNotIn("fixture-private-key-material-539", encoded)
            self.assertNotEqual(
                (case["result"].get("result") or {}).get("status"),
                "success",
            )

    def test_secret_stays_inside_privileged_callbacks_and_never_escapes(self):
        data = scenario("valid")
        self.assertTrue(data["secret_seen"])
        encoded = json.dumps(data["result"])
        self.assertNotIn("fixture-private-key-material-539", encoded)
        self.assertNotIn("deploy_user_539", encoded)
        self.assertIn("[REDACTED]", encoded)

    def test_destination_fingerprint_and_username_are_server_side_authoritative(self):
        data = scenario("authority")
        observed = data["valid"]["request"]
        self.assertEqual(observed["host"], "example.internal")
        self.assertEqual(observed["port"], 22)
        self.assertEqual(observed["expected_fingerprint"], "SHA256:abcdefghijklmnop")
        self.assertEqual(observed["username"], "deploy_user_539")
        self.assertTrue(data["override_rejected"])
        self.assertEqual(data["override_client_calls"], 0)

    def test_runtime_uses_fakes_and_preserves_existing_regressions(self):
        source = (
            (ROOT / "src" / "HostingerSshRuntime.php").read_text()
            + (ROOT / "tests" / "hostinger_ssh_runtime_scenarios.php").read_text()
        )
        for forbidden in (
            "curl_", "fsockopen", "stream_socket_client", "proc_open(",
            "shell_exec(", "exec(", "system(", "ssh2_",
        ):
            self.assertNotIn(forbidden, source)

        for test_file in (
            "test_hostinger_executor.py",
            "test_ssh_transport_adapter.py",
            "test_connection_identity_broker.py",
            "test_open_ssh_client.py",
            "test_ssh_identity_wiring.py",
        ):
            subprocess.run(
                [sys.executable, str(ROOT / "tests" / test_file)],
                cwd=ROOT,
                check=True,
                text=True,
                capture_output=True,
                timeout=90,
            )


if __name__ == "__main__":
    unittest.main()
