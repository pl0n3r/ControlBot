import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "hostinger_executor_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class HostingerExecutorTests(unittest.TestCase):
    def test_transport_is_not_opened_without_valid_grant(self):
        data = scenario("missing_grant")
        self.assertFalse(data["result"]["accepted"])
        self.assertEqual(data["result"]["reason"], "grant_required")
        self.assertEqual(data["transport_calls"], 0)

    def test_grant_must_match_operation(self):
        data = scenario("grant_mismatch")
        self.assertFalse(data["result"]["accepted"])
        self.assertIn(data["result"]["reason"], {"capability_mismatch", "operation_mismatch"})
        self.assertEqual(data["transport_calls"], 0)

    def test_output_is_bounded_and_redacted(self):
        data = scenario("redaction")
        self.assertTrue(data["result"]["accepted"])
        self.assertEqual(data["result"]["result"]["status"], "success")
        self.assertEqual(data["transport_calls"], 1)
        self.assertFalse(data["contains_secret"])
        self.assertFalse(data["contains_email"])
        self.assertFalse(data["contains_sql"])
        self.assertLessEqual(data["summary_length"], 500)

    def test_timeout_or_partial_failure_never_reports_success(self):
        data = scenario("terminal_states")
        self.assertEqual(data["timeout"]["result"]["status"], "timed_out")
        self.assertNotEqual(data["timeout"]["result"]["status"], "success")
        self.assertEqual(data["partial"]["result"]["status"], "partial")
        self.assertNotEqual(data["partial"]["result"]["status"], "success")

    def test_non_idempotent_write_is_not_replayed(self):
        data = scenario("replay")
        self.assertTrue(data["first"]["accepted"])
        self.assertFalse(data["second"]["accepted"])
        self.assertEqual(data["second"]["reason"], "non_idempotent_replay")
        self.assertEqual(data["transport_calls"], 1)

    def test_transport_receives_safe_connection_context(self):
        data = scenario("safe_connection_context")
        descriptor = data["descriptor"]

        self.assertTrue(data["result"]["accepted"])
        self.assertEqual(data["transport_calls"], 1)
        self.assertEqual(descriptor["username_ref"], "vault:user:brvtal")
        self.assertEqual(
            descriptor["expected_fingerprint"],
            "SHA256:abcdefghijklmnop",
        )
        self.assertEqual(descriptor["secret_kind"], "private_key")
        self.assertEqual(descriptor["project"], "brvtal")
        self.assertEqual(descriptor["environment"], "production")

    def test_transport_descriptor_never_contains_secret_material(self):
        data = scenario("safe_connection_context")
        descriptor = data["descriptor"]

        self.assertFalse(data["contains_secret_value"])
        for forbidden_key in (
            "secret_ref",
            "reference_id",
            "secret",
            "secret_value",
            "password",
            "private_key",
            "api_token",
            "token",
        ):
            self.assertNotIn(forbidden_key, descriptor)

        serialized = json.dumps(descriptor).lower()
        self.assertNotIn("fixture-hostinger-secret", serialized)
        self.assertNotIn("11111111-2222-4333-8444-555555555555", serialized)

    def test_transport_context_matches_validated_profile_and_secret_kind(self):
        data = scenario("safe_connection_context")
        descriptor = data["descriptor"]

        self.assertEqual(descriptor["project"], "brvtal")
        self.assertEqual(descriptor["environment"], "production")
        self.assertEqual(descriptor["username_ref"], "vault:user:brvtal")
        self.assertEqual(
            descriptor["expected_fingerprint"],
            "SHA256:abcdefghijklmnop",
        )

        for key in (
            "profile_project_mismatch",
            "profile_environment_mismatch",
            "secret_project_mismatch",
            "secret_environment_mismatch",
        ):
            self.assertFalse(data[key]["accepted"])

        for key in (
            "profile_project_calls",
            "profile_environment_calls",
            "secret_project_calls",
            "secret_environment_calls",
        ):
            self.assertEqual(data[key], 0)

        self.assertTrue(data["validated_kind_result"]["accepted"])
        self.assertEqual(data["validated_kind_calls"], 1)
        self.assertEqual(
            data["validated_kind_descriptor"]["secret_kind"],
            "private_key",
        )

    def test_fake_transport_requires_no_real_credentials(self):
        data = scenario("fake_transport")
        self.assertTrue(data["result"]["accepted"])
        self.assertEqual(data["transport_kind"], "fake")
        self.assertFalse(data["network_used"])
        self.assertEqual(data["transport_calls"], 1)

        source = (ROOT / "src" / "HostingerExecutor.php").read_text()
        for forbidden in ("curl_", "ssh2_", "shell_exec(", "proc_open(", "system(", "exec("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
