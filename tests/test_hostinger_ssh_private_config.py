import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "hostinger_ssh_private_config_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=30,
    )
    return json.loads(run.stdout)


class HostingerSshPrivateConfigTests(unittest.TestCase):
    def test_valid_private_config_builds_reusable_readonly_runtime(self):
        data = scenario("valid")
        self.assertIsNone(data["error"])
        self.assertTrue(data["first"]["accepted"])
        self.assertTrue(data["second"]["accepted"])
        self.assertEqual(data["first"]["result"]["evidence"]["code"], "ssh_readonly_probe_ok")
        self.assertEqual(data["runner_calls"], 4)

    def test_cross_scope_or_reference_mismatch_fails_before_runner(self):
        for case in scenario("scope").values():
            self.assertFalse(case["built"])
            self.assertEqual(case["runner_calls"], 0)
            self.assertTrue(case["error"])

    def test_only_readonly_private_key_configuration_is_supported(self):
        for case in scenario("unsupported").values():
            self.assertFalse(case["built"])
            self.assertEqual(case["runner_calls"], 0)
            self.assertTrue(case["error"])

    def test_safe_surfaces_never_expose_private_material_or_secret_reference(self):
        data = scenario("surface")
        visible = json.dumps(data)
        self.assertNotIn("BEGIN OPENSSH PRIVATE KEY", visible)
        self.assertNotIn("deploy_user_543", visible)
        self.assertNotIn("11111111-2222-4333-8444-555555555555", visible)
        self.assertNotIn("secret_ref", visible)
        self.assertFalse(data["snapshot"]["material_exposed"])
        self.assertTrue(data["result"]["accepted"])

    def test_noncanonical_or_invalid_private_config_fails_closed(self):
        for case in scenario("invalid").values():
            self.assertFalse(case["built"])
            self.assertEqual(case["runner_calls"], 0)
            self.assertTrue(case["error"])

    def test_suite_uses_fixtures_and_fake_runner_without_environment_or_network(self):
        source = (
            (ROOT / "src" / "HostingerSshPrivateConfig.php").read_text()
            + (ROOT / "tests" / "hostinger_ssh_private_config_scenarios.php").read_text()
        )
        for forbidden in (
            "getenv(", "$_ENV", "$_SERVER", "curl_", "fsockopen",
            "stream_socket_client", "proc_open(", "shell_exec(", "system(",
        ):
            self.assertNotIn(forbidden, source)

        subprocess.run(
            [sys.executable, str(ROOT / "tests" / "test_hostinger_ssh_runtime.py")],
            cwd=ROOT, check=True, text=True, capture_output=True, timeout=90,
        )


if __name__ == "__main__":
    unittest.main()
