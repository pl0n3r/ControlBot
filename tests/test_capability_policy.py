import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "capability_policy_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class CapabilityPolicyTests(unittest.TestCase):
    def test_unknown_capability_fails_closed(self):
        data = scenario("unknown")
        self.assertEqual(data["unknown"]["decision"], "forbidden")
        self.assertFalse(data["unknown"]["known"])
        self.assertFalse(data["unknown"]["auto_grant_allowed"])
        self.assertEqual(data["restricted"]["decision"], "owner_required")

    def test_safe_read_capability_can_be_automatic(self):
        data = scenario("read")
        self.assertEqual(data["policy"]["decision"], "automatic")
        self.assertTrue(data["policy"]["auto_grant_allowed"])
        self.assertTrue(data["auth"]["authorized"])
        self.assertIsNone(data["backup"])
        self.assertIsNone(data["approval"])

    def test_reconcile_requires_backup_receipt(self):
        data = scenario("backup")
        self.assertIn("Backup receipt requerido", data["missing"])
        self.assertTrue(data["auth"]["authorized"])
        self.assertEqual(data["auth"]["decision"], "backup_required")

    def test_destructive_capability_requires_owner_gate(self):
        data = scenario("owner")
        self.assertIn("Aprobación del dueño requerida", data["missing"])
        self.assertTrue(data["auth"]["authorized"])
        self.assertEqual(data["auth"]["decision"], "owner_required")
        self.assertIn("no autorizable", data["forbidden"])

    def test_grant_scope_cannot_cross_project_or_environment(self):
        data = scenario("scope")
        for key in ("project", "environment", "resource", "operation", "issue", "run_id", "subject"):
            self.assertFalse(data[key]["authorized"])
            self.assertEqual(data[key]["reason"], f"{key}_mismatch")

    def test_expired_or_revoked_grant_fails_closed(self):
        data = scenario("lifecycle")
        self.assertEqual(data["expired"], {"authorized": False, "reason": "grant_expired"})
        self.assertEqual(data["revoked"], {"authorized": False, "reason": "grant_revoked"})


if __name__ == "__main__":
    unittest.main()
