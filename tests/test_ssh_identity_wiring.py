import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "ssh_identity_wiring_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class SshIdentityWiringTests(unittest.TestCase):
    def test_adapter_passes_exact_identity_context_without_secret(self):
        data = scenario("context")
        self.assertEqual(
            data["identity_context"],
            {
                "username_ref": "vault:user:brvtal",
                "provider": "hostinger",
                "project": "brvtal",
                "environment": "production",
            },
        )
        self.assertFalse(data["context_contains_secret"])
        self.assertTrue(data["secret_separate"])

    def test_resolver_uses_broker_generation_and_exact_scope(self):
        data = scenario("composed")
        self.assertEqual(data["client_calls"], 1)
        self.assertTrue(data["client_saw_username"])
        self.assertEqual(data["result"]["status"], "success")

    def test_unknown_revoked_or_mismatched_identity_stops_before_client(self):
        data = scenario("scope")
        for case in data.values():
            self.assertEqual(case["client_calls"], 0)
            self.assertEqual(case["result"]["status"], "failed")
            self.assertEqual(case["result"]["code"], "ssh_username_unavailable")

    def test_real_username_never_escapes_result_or_evidence(self):
        data = scenario("redaction")
        self.assertTrue(data["client_saw_username"])
        self.assertFalse(data["visible_contains_username"])
        self.assertFalse(data["visible_contains_secret"])
        self.assertIn("[REDACTED]", data["result"]["summary"])

    def test_composed_readonly_transport_uses_fakes_without_network(self):
        data = scenario("composed")
        self.assertEqual(data["client_calls"], 1)
        self.assertTrue(data["secret_separate"])
        self.assertEqual(data["result"]["code"], "ssh_readonly_probe_ok")
        source = (ROOT / "tests" / "ssh_identity_wiring_scenarios.php").read_text()
        for forbidden in ("curl_", "fsockopen", "stream_socket_client", "proc_open(", "shell_exec(", "exec(", "system("):
            self.assertNotIn(forbidden, source)

    def test_existing_production_authority_regressions_remain_compatible(self):
        for test_file in (
            "test_ssh_transport_adapter.py",
            "test_connection_identity_broker.py",
            "test_open_ssh_client.py",
        ):
            subprocess.run(
                [sys.executable, str(ROOT / "tests" / test_file)],
                cwd=ROOT,
                check=True,
                text=True,
                capture_output=True,
                timeout=60,
            )


if __name__ == "__main__":
    unittest.main()
