import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "recovery_profile_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class RecoveryProfileTests(unittest.TestCase):
    def test_profile_v1_schema_requires_targets_retention_and_manifest_ref(self):
        data = scenario("valid")
        profile = data["profile"]
        self.assertEqual(profile["version"], 1)
        self.assertEqual(profile["project_ref"], "controlbot:project/project-controlbot")
        self.assertEqual(
            profile["manifest_ref"],
            "controlbot:recovery-manifest/project-controlbot-v1",
        )
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(
            profile["retention"],
            {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12},
        )
        self.assertEqual(data["effective_status"], "configured")
        self.assertTrue(scenario("bad-rpo")["blocked"])
        self.assertTrue(scenario("empty-retention")["blocked"])
        self.assertTrue(scenario("unknown-field")["blocked"])

    def test_sources_require_explicit_required_or_not_applicable(self):
        profile = scenario("explicit-not-applicable")
        self.assertEqual(
            profile["sources"],
            {
                "database": "not_applicable",
                "media": "required",
                "repository": "required",
                "configuration": "not_applicable",
            },
        )
        self.assertTrue(scenario("unknown-source-state")["blocked"])
        self.assertTrue(scenario("all-not-applicable")["blocked"])

    def test_32110_policy_is_provider_neutral_and_explicit(self):
        profile = scenario("valid")["profile"]
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
        self.assertTrue(profile["encryption_required"])
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)
        serialized = json.dumps(profile).lower()
        for vendor in ("hostinger", "aws", "google_drive", "s3"):
            self.assertNotIn(vendor, serialized)
        self.assertTrue(scenario("bad-strategy")["blocked"])

    def test_invalid_sensitive_or_incoherent_profile_fails_closed(self):
        for case in (
            "sensitive-manifest-ref",
            "encryption-disabled",
            "unknown-with-provenance",
            "stale-without-provenance",
        ):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_stale_or_unknown_profile_never_becomes_configured(self):
        self.assertEqual(scenario("stale")["effective_status"], "unknown")
        self.assertEqual(scenario("unknown")["effective_status"], "unknown")
        self.assertEqual(scenario("missing-profile")["effective_status"], "unknown")
        self.assertEqual(scenario("valid")["effective_status"], "configured")

    def test_control_plane_projection_reuses_factory_and_backup_receipt_boundaries(self):
        docs = (ROOT / "docs" / "disaster-recovery-contract.md").read_text(
            encoding="utf-8"
        )
        source = (ROOT / "src" / "RecoveryProfile.php").read_text(encoding="utf-8")
        self.assertIn("Factory #305", docs)
        self.assertIn("BackupReceipt", docs)
        self.assertIn("BackupGate", docs)
        self.assertIn("no valida un backup real", docs)
        self.assertNotIn("class BackupReceipt", source)
        self.assertNotIn("class BackupGate", source)
        self.assertNotIn("isUsableBefore", source)


if __name__ == "__main__":
    unittest.main()
