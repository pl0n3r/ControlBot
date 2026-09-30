import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "factory_recovery_rollout_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class FactoryRecoveryRolloutTests(unittest.TestCase):
    def test_profile_matches_canonical_recovery_contract(self):
        data = scenario("profile")
        profile = data["profile"]
        self.assertEqual(data["effective_status"], "configured")
        self.assertEqual(profile["project_ref"], "controlbot:project/project-factory")
        self.assertEqual(
            profile["manifest_ref"],
            "controlbot:recovery-manifest/project-factory-v1",
        )
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(
            profile["retention"],
            {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12},
        )
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)
        self.assertTrue(profile["encryption_required"])
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
        profile = scenario("profile")["profile"]
        self.assertEqual(
            profile["sources"],
            {
                "database": "not_applicable",
                "media": "not_applicable",
                "repository": "required",
                "configuration": "required",
            },
        )

    def test_legacy_external_restore_is_provenance_not_canonical_receipt(self):
        evidence = scenario("legacy")
        self.assertTrue(evidence["restored_from_external_copy"])
        self.assertEqual(
            evidence["source_sha"],
            "19580a32fe504e74f4941bd64cefcc5b5a77d07a",
        )
        self.assertEqual(evidence["manifest_files_verified"], 155)
        self.assertEqual(evidence["manifest_files_total"], 155)
        self.assertFalse(evidence["canonical_backup_receipt"])
        self.assertFalse(evidence["canonical_recovery_evidence"])
        self.assertFalse(evidence["canonical_restore_drill"])
        self.assertEqual(evidence["encryption"], "unknown")
        self.assertEqual(evidence["immutability"], "unknown")
        self.assertIsNone(evidence["demonstrated_rto_minutes"])

    def test_missing_canonical_evidence_remains_unknown(self):
        status = scenario("operational-status")
        self.assertEqual(status["profile_status"], "configured")
        self.assertEqual(status["legacy_external_restore"], "verified")
        for key in (
            "canonical_backup_receipt",
            "canonical_recovery_evidence",
            "canonical_restore_drill",
            "encryption",
            "immutability",
        ):
            self.assertEqual(status[key], "unknown")
        self.assertIsNone(status["demonstrated_rto_minutes"])
        self.assertEqual(status["dr_status"], "UNKNOWN")
        self.assertFalse(status["restorable"])
        self.assertFalse(status["execution"])

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        config = json.loads(
            (ROOT / "config" / "recovery" / "factory.json").read_text(encoding="utf-8")
        )
        legacy = scenario("legacy")
        serialized = json.dumps({"config": config, "legacy": legacy}, sort_keys=True).lower()
        for forbidden in (
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
        ):
            self.assertNotIn(forbidden, serialized)
        self.assertFalse(legacy["canonical_backup_receipt"])
        self.assertFalse(legacy["canonical_recovery_evidence"])

    def test_rollout_has_no_external_io_or_execution(self):
        source = (
            ROOT / "tests" / "factory_recovery_rollout_scenarios.php"
        ).read_text(encoding="utf-8").lower()
        docs = (
            ROOT / "docs" / "factory-recovery-rollout.md"
        ).read_text(encoding="utf-8").lower()
        for forbidden in (
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
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("read-only", docs)
        self.assertIn("dr status: `unknown`", docs)
        self.assertIn("no ejecuta backups ni restores", docs)


if __name__ == "__main__":
    unittest.main()
