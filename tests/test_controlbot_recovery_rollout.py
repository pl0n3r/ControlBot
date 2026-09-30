import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "controlbot_recovery_rollout_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class ControlBotRecoveryRolloutTests(unittest.TestCase):
    def test_profile_matches_canonical_recovery_contract(self):
        data = scenario("profile")
        profile = data["profile"]
        self.assertEqual(data["effective_status"], "configured")
        self.assertEqual(profile["project_ref"], "controlbot:project/project-controlbot")
        self.assertEqual(profile["manifest_ref"], "controlbot:recovery-manifest/project-controlbot-v1")
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

    def test_sources_are_explicit_for_controlbot(self):
        profile = scenario("profile")["profile"]
        self.assertEqual(
            profile["sources"],
            {
                "database": "required",
                "media": "not_applicable",
                "repository": "required",
                "configuration": "required",
            },
        )

    def test_rollout_requires_construction_attestation_until_operational_evidence_exists(self):
        attestation = scenario("attestation")
        self.assertEqual(attestation["phase"], "construccion")
        self.assertTrue(attestation["nothing_live"])
        self.assertTrue(attestation["no_real_customer_data"])
        self.assertTrue(scenario("attestation-live")["blocked"])
        self.assertTrue(scenario("attestation-real-data")["blocked"])

    def test_missing_operational_evidence_remains_unknown(self):
        status = scenario("operational-status")
        self.assertEqual(status["profile_status"], "configured")
        self.assertEqual(status["backup_evidence"], "unknown")
        self.assertEqual(status["offsite_copy"], "unknown")
        self.assertEqual(status["immutable_copy"], "unknown")
        self.assertEqual(status["restore_drill"], "unknown")
        self.assertEqual(status["dr_status"], "UNKNOWN")
        self.assertFalse(status["restorable"])
        self.assertFalse(status["execution"])

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        profile = json.loads(
            (ROOT / "config" / "recovery" / "controlbot.json").read_text(encoding="utf-8")
        )
        serialized = json.dumps(profile, sort_keys=True).lower()
        for key in (
            "storage_ref",
            "backup_id",
            "restore_id",
            "checksum",
            "credential",
            "password",
            "api_key",
            "private_key",
        ):
            self.assertNotIn(key, serialized)
        urls = re.findall(r'https?://[^"\s]+', serialized)
        self.assertEqual(urls, ["https://github.com/pl0n3r/controlbot/issues/457"])

    def test_rollout_has_no_external_io_or_execution(self):
        scenario_source = (
            ROOT / "tests" / "controlbot_recovery_rollout_scenarios.php"
        ).read_text(encoding="utf-8")
        docs = (ROOT / "docs" / "controlbot-recovery-rollout.md").read_text(encoding="utf-8")
        for forbidden in (
            "curl_",
            "fsockopen",
            "stream_socket",
            "new pdo",
            "mysqli",
            "shell_exec",
            "proc_open",
            "exec(",
            "backupreceipt(",
            "restorereceipt(",
        ):
            self.assertNotIn(forbidden, scenario_source.lower())
        self.assertIn("read-only", docs.lower())
        self.assertIn("unknown", docs.lower())
        self.assertIn("no ejecuta backups ni restores", docs.lower())


if __name__ == "__main__":
    unittest.main()
