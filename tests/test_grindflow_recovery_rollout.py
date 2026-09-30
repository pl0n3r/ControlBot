import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def projection():
    raw = subprocess.check_output(
        ["php", str(ROOT / "tests" / "grindflow_recovery_rollout_scenarios.php")],
        cwd=ROOT,
        text=True,
    )
    return json.loads(raw)


class GrindFlowRecoveryRolloutTests(unittest.TestCase):
    def test_profile_matches_canonical_recovery_contract(self):
        p = projection()["profile"]
        expected = {
            "project_ref": "controlbot:project/project-grindflow",
            "manifest_ref": "controlbot:recovery-manifest/project-grindflow-v1",
            "targets": {"rpo_minutes": 15, "rto_minutes": 60},
            "retention": {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12},
            "restore_drill_cadence_hours": 168,
            "encryption_required": True,
        }
        self.assertTrue(all(p[k] == v for k, v in expected.items()))
        self.assertEqual(
            p["strategy"],
            {"copies_required": 3, "media_types_required": 2, "offsite_required": True,
             "immutable_required": True, "undetected_restore_failures_target": 0},
        )
        self.assertEqual(projection()["profile_status"], "configured")

    def test_all_grindflow_sources_are_required(self):
        self.assertEqual(set(projection()["profile"]["sources"].values()), {"required"})

    def test_release_backup_is_not_treated_as_canonical_data_recovery_evidence(self):
        boundary = projection()["release_backup_boundary"]
        self.assertEqual(boundary["scope"], "release_artifact_only")
        self.assertFalse(any(v for k, v in boundary.items() if k.startswith("canonical_")))
        docs = (ROOT / "docs" / "grindflow-recovery-rollout.md").read_text(encoding="utf-8")
        self.assertIn("GrindFlow #175", docs)
        self.assertIn("no sustituye", docs)

    def test_missing_operational_evidence_remains_unknown(self):
        d = projection()
        self.assertTrue(all(v == "unknown" for v in d["signals"].values()))
        self.assertEqual(d["dr_status"], "UNKNOWN")
        self.assertFalse(d["restorable"])
        self.assertFalse(d["execution"])

    def test_rollout_contains_no_sensitive_or_fabricated_evidence(self):
        config = (ROOT / "config" / "recovery" / "grindflow.json").read_text(encoding="utf-8")
        lowered = config.lower()
        for marker in ("storage_ref", "backup_id", "restore_id", "checksum", "password",
                       "credential", "api_key", "private_key", "hostinger", "google_drive"):
            self.assertNotIn(marker, lowered)
        self.assertEqual(
            re.findall(r'https?://[^"\\s]+', lowered),
            ["https://github.com/pl0n3r/controlbot/issues/465"],
        )

    def test_rollout_has_no_external_io_or_execution(self):
        source = (ROOT / "tests" / "grindflow_recovery_rollout_scenarios.php").read_text(
            encoding="utf-8"
        ).lower()
        self.assertFalse(any(x in source for x in (
            "curl_", "fsockopen", "stream_socket", "new pdo", "mysqli",
            "shell_exec", "proc_open", "exec(", "file_put_contents",
        )))
        docs = (ROOT / "docs" / "grindflow-recovery-rollout.md").read_text(encoding="utf-8").lower()
        self.assertIn("read-only", docs)
        self.assertIn("dr status: `unknown`", docs)
        self.assertIn("no ejecuta", docs)


if __name__ == "__main__":
    unittest.main()
