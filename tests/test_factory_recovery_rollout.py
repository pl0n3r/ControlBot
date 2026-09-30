import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class FactoryRecoveryRolloutTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "factory_recovery_rollout_scenarios.php")],
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
            "project_ref": "controlbot:project/project-factory",
            "manifest_ref": "controlbot:recovery-manifest/project-factory-v1",
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

    def test_sources_are_explicit_for_factory(self):
        self.assertEqual(
            self.snapshot["profile"]["sources"],
            {
                "database": "not_applicable",
                "media": "not_applicable",
                "repository": "required",
                "configuration": "required",
            },
        )

    def test_legacy_external_restore_is_provenance_not_canonical_receipt(self):
        legacy = self.snapshot["legacy_restore"]
        self.assertEqual(
            legacy["evidence_ref"],
            "https://github.com/pl0n3r/Factory/issues/36#issuecomment-5813381932",
        )
        self.assertEqual(legacy["source_sha"], "19580a32fe504e74f4941bd64cefcc5b5a77d07a")
        self.assertTrue(legacy["restored_from_external_copy"])
        self.assertEqual(legacy["manifest"], {"verified": 155, "total": 155})
        self.assertEqual(
            legacy["canonical_contracts"],
            {"backup_receipt": False, "recovery_evidence": False, "restore_drill": False},
        )
        self.assertEqual(
            legacy["unproven"], ["encryption", "immutability", "demonstrated_rto"]
        )

    def test_missing_canonical_evidence_remains_unknown(self):
        state = self.snapshot["recovery"]
        unknown = (
            "canonical_backup_receipt",
            "canonical_recovery_evidence",
            "canonical_restore_drill",
            "encryption",
            "immutability",
        )
        self.assertEqual(state["legacy_external_restore"], "verified")
        self.assertTrue(all(state[field] == "unknown" for field in unknown))
        self.assertIsNone(state["demonstrated_rto_minutes"])
        self.assertEqual(state["dr_status"], "UNKNOWN")
        self.assertFalse(state["restorable"] or state["execution"])

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        serialized = json.dumps(self.snapshot, sort_keys=True).lower()
        forbidden = (
            "libfile_",
            "/factory backups/",
            "password",
            "api_key",
            "private_key",
            "access_token",
            "refresh_token",
            "storage_ref",
            "backup_id",
            "restore_id",
        )
        self.assertFalse(any(marker in serialized for marker in forbidden))
        self.assertFalse(any(self.snapshot["legacy_restore"]["canonical_contracts"].values()))

    def test_rollout_has_no_external_io_or_execution(self):
        source = (ROOT / "tests" / "factory_recovery_rollout_scenarios.php").read_text(
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
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))
        self.assertFalse(self.snapshot["recovery"]["execution"])


if __name__ == "__main__":
    unittest.main()
