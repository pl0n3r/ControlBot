import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "factory_recovery_rollout_scenarios.php"


def scenario(name: str):
    output = subprocess.check_output(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        text=True,
    )
    return json.loads(output)


class FactoryRecoveryRolloutTests(unittest.TestCase):
    def test_profile_matches_canonical_recovery_contract(self):
        data = scenario("profile")
        expected = {
            "project_ref": "controlbot:project/project-factory",
            "manifest_ref": "controlbot:recovery-manifest/project-factory-v1",
            "targets": {"rpo_minutes": 15, "rto_minutes": 60},
            "retention": {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12},
            "restore_drill_cadence_hours": 168,
            "encryption_required": True,
            "strategy": {
                "copies_required": 3,
                "media_types_required": 2,
                "offsite_required": True,
                "immutable_required": True,
                "undetected_restore_failures_target": 0,
            },
        }
        self.assertEqual(data["effective_status"], "configured")
        self.assertEqual(
            {key: data["profile"][key] for key in expected},
            expected,
        )

    def test_sources_are_explicit_for_factory(self):
        self.assertEqual(
            scenario("profile")["profile"]["sources"],
            {
                "database": "not_applicable",
                "media": "not_applicable",
                "repository": "required",
                "configuration": "required",
            },
        )

    def test_legacy_external_restore_is_provenance_not_canonical_receipt(self):
        self.assertEqual(
            scenario("legacy"),
            {
                "evidence_ref": "https://github.com/pl0n3r/Factory/issues/36#issuecomment-5813381932",
                "source_sha": "19580a32fe504e74f4941bd64cefcc5b5a77d07a",
                "restored_from_external_copy": True,
                "manifest_files_verified": 155,
                "manifest_files_total": 155,
                "canonical_backup_receipt": False,
                "canonical_recovery_evidence": False,
                "canonical_restore_drill": False,
                "encryption": "unknown",
                "immutability": "unknown",
                "demonstrated_rto_minutes": None,
            },
        )

    def test_missing_canonical_evidence_remains_unknown(self):
        self.assertEqual(
            scenario("operational-status"),
            {
                "profile_status": "configured",
                "legacy_external_restore": "verified",
                "canonical_backup_receipt": "unknown",
                "canonical_recovery_evidence": "unknown",
                "canonical_restore_drill": "unknown",
                "encryption": "unknown",
                "immutability": "unknown",
                "demonstrated_rto_minutes": None,
                "dr_status": "UNKNOWN",
                "restorable": False,
                "execution": False,
            },
        )

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        config = json.loads(
            (ROOT / "config" / "recovery" / "factory.json").read_text(encoding="utf-8")
        )
        payload = json.dumps(
            {"config": config, "legacy": scenario("legacy")},
            sort_keys=True,
        ).lower()
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
        self.assertEqual([token for token in forbidden if token in payload], [])

    def test_rollout_has_no_external_io_or_execution(self):
        source = SCENARIOS.read_text(encoding="utf-8").lower()
        blocked = (
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
        self.assertEqual([token for token in blocked if token in source], [])
        docs = (ROOT / "docs" / "factory-recovery-rollout.md").read_text(
            encoding="utf-8"
        ).lower()
        self.assertIn("read-only", docs)
        self.assertIn("dr status: `unknown`", docs)
        self.assertIn("no ejecuta backups ni restores", docs)


if __name__ == "__main__":
    unittest.main()
