import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class BrvtalRecoveryRolloutTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "brvtal_recovery_rollout_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        cls.snapshot = json.loads(completed.stdout)

    def test_profile_matches_canonical_recovery_contract(self):
        profile = self.snapshot["profile"]
        self.assertEqual(self.snapshot["profile_status"], "configured")
        expected = {
            "project_ref": "controlbot:project/project-brvtal",
            "manifest_ref": "controlbot:recovery-manifest/project-brvtal-v1",
            "targets": {"rpo_minutes": 15, "rto_minutes": 60},
            "retention": {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12},
            "restore_drill_cadence_hours": 168,
            "encryption_required": True,
        }
        for field, value in expected.items():
            self.assertEqual(profile[field], value)
        self.assertEqual(
            profile["strategy"],
            {
                "copies_required": 3,
                "media_types_required": 2,
                "offsite_required": True,
                "immutable_required": True,
                "undetected_restore_failures_target": 0,
            },
        )

    def test_all_brvtal_sources_are_required(self):
        self.assertEqual(
            self.snapshot["profile"]["sources"],
            {
                "database": "required",
                "media": "required",
                "repository": "required",
                "configuration": "required",
            },
        )

    def test_existing_backup_and_drive_capability_is_not_canonical_recovery_evidence(self):
        capability = self.snapshot["legacy_capability"]
        self.assertEqual(capability["source_issue"], 389)
        self.assertTrue(capability["scheduled_backup_capability"])
        self.assertTrue(capability["google_drive_optional"])
        self.assertFalse(capability["primary_immutable_verified"])
        self.assertFalse(any(capability["canonical_contracts"].values()))

    def test_provider_decision_and_missing_evidence_remain_unknown(self):
        decision = self.snapshot["provider_decision"]
        recovery = self.snapshot["recovery"]
        self.assertEqual(decision, {
            "issue": 470,
            "state": "pending",
            "primary_offsite_immutable": "unknown",
        })
        for field in (
            "canonical_backup_receipt",
            "offsite_copy",
            "immutable_copy",
            "canonical_restore_drill",
        ):
            self.assertEqual(recovery[field], "unknown")
        self.assertIsNone(recovery["observed_rpo_minutes"])
        self.assertIsNone(recovery["demonstrated_rto_minutes"])
        self.assertEqual(recovery["dr_status"], "UNKNOWN")
        self.assertFalse(recovery["restorable"] or recovery["execution"])

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        serialized = json.dumps(self.snapshot, sort_keys=True).lower()
        forbidden = (
            "password",
            "oauth_token",
            "access_token",
            "refresh_token",
            "api_key",
            "private_key",
            "client_secret",
            "storage_ref",
            "checksum",
            "backup_id",
            "restore_id",
            "drive.google.com",
            "storage.googleapis.com",
            "s3.amazonaws.com",
        )
        self.assertFalse(any(marker in serialized for marker in forbidden))
        self.assertFalse(any(self.snapshot["legacy_capability"]["canonical_contracts"].values()))

    def test_rollout_has_no_external_io_or_execution(self):
        source = (ROOT / "tests" / "brvtal_recovery_rollout_scenarios.php").read_text(
            encoding="utf-8"
        ).lower()
        forbidden = (
            "curl_",
            "fsockopen",
            "stream_socket",
            "new pdo",
            "mysqli",
            "shell_exec",
            "proc_open",
            "exec(",
            "backupreceipt::fromrecord",
            "recoveryevidence::normalize",
            "recoverydrillprojection::",
            "google_client",
            "drive_service",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))
        self.assertFalse(self.snapshot["recovery"]["execution"])


if __name__ == "__main__":
    unittest.main()
