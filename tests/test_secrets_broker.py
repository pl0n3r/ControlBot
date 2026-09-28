import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "secrets_broker_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class SecretsBrokerTests(unittest.TestCase):
    def test_agent_surface_never_contains_secret_value(self):
        data = scenario("surface")
        self.assertFalse(data["contains_secret"])
        self.assertIsNone(data["surface"]["secret"])
        self.assertFalse(data["surface"]["resolvable"])

    def test_logs_and_errors_are_redacted(self):
        data = scenario("redaction")
        self.assertFalse(data["contains_secret"])
        self.assertEqual(data["result"]["result"]["token"], "[REDACTED]")
        self.assertEqual(data["result"]["result"]["nested"]["dsn"], "[REDACTED]")
        self.assertIn("[REDACTED]", data["error"]["error"])

    def test_secret_reference_resolves_only_for_executor_context(self):
        data = scenario("context")
        self.assertEqual(data["cases"]["executor"]["reason"], "executor_not_authorized")
        for key in ("capability", "project", "environment", "generation"):
            self.assertEqual(data["cases"][key]["reason"], "secret_scope_mismatch")
        self.assertTrue(data["allowed"]["ok"])
        self.assertNotIn("fixture-value", json.dumps(data["allowed"]))

    def test_rotation_invalidates_new_resolutions_without_changing_capability(self):
        data = scenario("lifecycle")
        self.assertEqual(data["revoked"]["reason"], "secret_reference_revoked")
        self.assertEqual(data["old_after_rotation"]["reason"], "secret_reference_revoked")
        self.assertTrue(data["new_after_rotation"]["ok"])
        self.assertTrue(data["capability_stable"])


if __name__ == "__main__":
    unittest.main()
