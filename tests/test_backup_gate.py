import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "backup_gate_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class BackupGateTests(unittest.TestCase):
    def test_protected_write_without_receipt_is_denied(self):
        data = scenario("missing")
        self.assertFalse(data["allowed"])
        self.assertTrue(data["backup_required"])
        self.assertEqual(data["reason"], "backup_receipt_required")

    def test_receipt_scope_must_match_project_resource_and_run(self):
        data = scenario("scope")
        for key in ("project", "environment", "resource", "run_id", "issue"):
            self.assertFalse(data[key]["allowed"])
            self.assertEqual(data[key]["reason"], "backup_scope_mismatch")

    def test_receipt_must_precede_write_and_be_ready(self):
        data = scenario("causality")
        self.assertEqual(data["not_ready"]["reason"], "backup_receipt_invalid")
        self.assertEqual(data["verified_after_write"]["reason"], "backup_not_usable_for_write")
        self.assertEqual(data["expired"]["reason"], "backup_not_usable_for_write")
        self.assertTrue(data["ready"]["allowed"])
        self.assertEqual(data["ready"]["reason"], "backup_verified")

    def test_read_only_operation_does_not_require_backup(self):
        data = scenario("readonly")
        for result in data.values():
            self.assertTrue(result["allowed"])
            self.assertFalse(result["backup_required"])
            self.assertEqual(result["reason"], "backup_not_required")

    def test_evidence_never_contains_backup_payload(self):
        data = scenario("evidence")
        self.assertTrue(data["allowed"])
        self.assertFalse(data["contains_artifact_ref"])
        self.assertFalse(data["contains_digest"])
        self.assertFalse(data["contains_payload_key"])
        self.assertEqual(data["evidence"]["status"], "ready")


if __name__ == "__main__":
    unittest.main()
