import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUN_ID = "123e4567-e89b-42d3-a456-426614174000"
SHA = "a" * 40

PHP = r"""
require 'src/CapabilityPolicy.php';
require 'src/BackupReceipt.php';
require 'src/BackupGate.php';
require 'src/BrvtalMigrationReconcileRunbook.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$out=ControlBot\Production\BrvtalMigrationReconcileRunbook::project($input);
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


def receipt(status="ready"):
    return {
        "version": 1,
        "receipt_id": "123e4567-e89b-42d3-a456-426614174001",
        "project": "brvtal",
        "environment": "production",
        "resource": "database:migrations",
        "backup_type": "database_dump",
        "run_id": RUN_ID,
        "issue": "pl0n3r/brvtal#681",
        "created_at": "2026-09-30T05:00:00Z",
        "verified_at": "2026-09-30T05:01:00Z",
        "status": status,
        "artifact_ref": "backup:brvtal:681",
        "integrity": {"algorithm": "sha256", "digest": "a" * 64},
        "restore_capability": "manual",
        "expires_at": "2026-09-30T07:00:00Z",
    }


def project(**overrides):
    data = {
        "run_id": RUN_ID,
        "sha": SHA,
        "now": 1790748000,
        "schema_state": "reconcile_needed",
        "backup_receipt": None,
        "completed_steps": [],
        "failed_step": None,
    }
    data.update(overrides)
    proc = subprocess.run(
        ["php", "-r", PHP],
        cwd=ROOT,
        input=json.dumps(data),
        text=True,
        capture_output=True,
        check=True,
    )
    return json.loads(proc.stdout)


class BrvtalReconcileRunbookTests(unittest.TestCase):
    def test_agent_never_receives_ssh_or_database_secret(self):
        out = project()
        serialized = json.dumps(out).lower()
        self.assertNotIn("password", serialized)
        self.assertNotIn("secret", serialized)
        self.assertNotIn("ssh", serialized)
        self.assertEqual(out["next_intent"]["operation"], "migration.status")

    def test_no_write_occurs_before_ready_backup(self):
        out = project(
            completed_steps=["migration.status", "database.backup"],
            backup_receipt=None,
        )
        self.assertEqual(out["status"], "blocked")
        self.assertIsNone(out["next_intent"])
        self.assertEqual(out["reason"], "backup_receipt_required")

        invalid = project(
            completed_steps=["migration.status", "database.backup"],
            backup_receipt=receipt("pending"),
        )
        self.assertEqual(invalid["status"], "blocked")
        self.assertIsNone(invalid["next_intent"])

        allowed = project(
            completed_steps=["migration.status", "database.backup"],
            backup_receipt=receipt(),
        )
        self.assertEqual(
            allowed["next_intent"]["operation"],
            "migration.registry.reconcile",
        )
        self.assertEqual(allowed["next_intent"]["effect"], "write")

    def test_ambiguous_schema_aborts_without_baselining(self):
        out = project(
            schema_state="ambiguous",
            completed_steps=["migration.status"],
        )
        self.assertEqual(out["status"], "blocked")
        self.assertEqual(out["reason"], "ambiguous_schema")
        self.assertFalse(out["evidence"]["baseline_allowed"])
        self.assertIsNone(out["next_intent"])

    def test_verify_health_and_smoke_follow_reconcile(self):
        base = ["migration.status", "database.backup", "migration.registry.reconcile"]
        verify = project(completed_steps=base, backup_receipt=receipt())
        self.assertEqual(verify["next_intent"]["operation"], "migration.verify")
        self.assertEqual(verify["next_intent"]["expect"]["pending_migrations"], [])

        health = project(
            completed_steps=base + ["migration.verify"],
            backup_receipt=receipt(),
        )
        self.assertEqual(health["next_intent"]["operation"], "health.check")
        self.assertTrue(health["next_intent"]["expect"]["schema_up_to_date"])

        smoke = project(
            completed_steps=base + ["migration.verify", "health.check"],
            backup_receipt=receipt(),
        )
        self.assertEqual(smoke["next_intent"]["operation"], "smoke.run")
        self.assertEqual(smoke["next_intent"]["expect"]["sha"], SHA)
        self.assertEqual(smoke["next_intent"]["expect"]["run_id"], RUN_ID)

    def test_grants_are_revoked_after_terminal_state(self):
        steps = [
            "migration.status", "database.backup", "migration.registry.reconcile",
            "migration.verify", "health.check", "smoke.run",
        ]
        success = project(completed_steps=steps, backup_receipt=receipt())
        self.assertEqual(success["status"], "success")
        self.assertTrue(success["revoke_grant"])

        failed = project(failed_step="health.check")
        self.assertTrue(failed["revoke_grant"])

    def test_failure_exposes_recoverable_continuation_state(self):
        out = project(
            completed_steps=["migration.status", "database.backup"],
            failed_step="migration.registry.reconcile",
        )
        self.assertEqual(out["status"], "recoverable")
        self.assertEqual(out["reason"], "step_failed")
        self.assertEqual(
            out["continuation"]["resume_from"],
            "migration.registry.reconcile",
        )
        self.assertIsNone(out["next_intent"])


if __name__ == "__main__":
    unittest.main()
