import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/hostinger_connection_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class HostingerConnectionTests(unittest.TestCase):
    def test_new_session_can_use_connection_profile_without_secret_reentry(self):
        data = scenario("reuse")
        self.assertEqual("connected", data["status"])
        self.assertTrue(data["read_allowed"])
        self.assertTrue(data["same_profile"])
        self.assertEqual(1, data["probe_count"])

    def test_agent_never_receives_profile_secret(self):
        data = scenario("secret")
        self.assertFalse(data["public_contains_secret_ref"])
        self.assertFalse(data["public_contains_secret_key"])
        self.assertTrue(data["server_record_has_opaque_ref"])

    def test_unknown_or_changed_host_key_fails_closed(self):
        data = scenario("fingerprint")
        self.assertEqual("unavailable", data["mismatch_status"])
        self.assertFalse(data["mismatch_healthy"])
        self.assertEqual("identity-mismatch", data["mismatch_code"])
        self.assertEqual("unavailable", data["missing_status"])
        self.assertFalse(data["missing_healthy"])
        self.assertEqual(2, data["probe_count"])

    def test_health_probe_is_read_only_and_identifies_destination(self):
        data = scenario("readonly")
        self.assertEqual("ssh.identity.probe", data["operation"])
        self.assertEqual("read", data["effect"])
        self.assertTrue(data["identity_confirmed"])
        self.assertEqual("srv123.hostinger.com:22", data["destination"])
        self.assertEqual("connected", data["status"])
        self.assertNotIn("secret_ref", data["request_keys"])

    def test_revoked_profile_blocks_new_execution(self):
        data = scenario("revoked")
        self.assertEqual("revoked", data["status"])
        self.assertFalse(data["read_allowed"])
        self.assertEqual(0, data["probe_calls"])
        self.assertIn("no disponible", data["error"].lower())

    def test_rotation_does_not_change_capability_or_runbook_contract(self):
        data = scenario("rotation")
        self.assertTrue(data["scope_stable"])
        self.assertTrue(data["profile_stable"])
        self.assertTrue(data["rotation_requires_verify"])
        self.assertTrue(data["reconnected"])
        self.assertTrue(data["server_ref_rotated"])
        self.assertFalse(data["public_has_secret_ref"])


if __name__ == "__main__":
    unittest.main()
