import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        [
            "php",
            str(ROOT / "tests" / "disaster_recovery_policy_scenarios.php"),
            name,
        ],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class DisasterRecoveryPolicyTests(unittest.TestCase):
    def test_canonical_policy_requires_rpo_rto_retention_and_separated_components(self):
        data = scenario("valid")
        policy = data["policy"]
        self.assertEqual(policy["version"], 1)
        self.assertEqual(policy["project_id"], "controlbot")
        self.assertEqual(policy["rpo_seconds"], 900)
        self.assertEqual(policy["rto_seconds"], 3600)
        self.assertEqual(
            policy["retention"],
            {"recent": 24, "daily": 7, "weekly": 8, "monthly": 12},
        )
        self.assertEqual(
            policy["strategies"],
            {
                "database": "consistent_backup",
                "media": "versioned_backup",
                "code": "repository_mirror",
                "secrets": "vault_reference_only",
            },
        )
        for case in ("bad-rpo", "bad-rto", "empty-retention", "missing-field"):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_missing_stale_or_incomplete_evidence_never_becomes_healthy(self):
        evidence = scenario("evidence")
        self.assertEqual(evidence["missing"], "unknown")
        self.assertEqual(evidence["stale_healthy"], "degraded")
        self.assertEqual(evidence["incomplete_healthy"], "degraded")
        self.assertEqual(evidence["unknown"], "unknown")
        self.assertEqual(evidence["blocked"], "blocked")
        self.assertEqual(evidence["healthy"], "healthy")

    def test_recovery_capabilities_are_explicit_and_reproducible(self):
        policy = scenario("valid")["policy"]
        self.assertEqual(
            policy["capabilities"],
            {
                "offsite": True,
                "versioned_or_immutable": True,
                "checksum_required": True,
                "freshness_required": True,
                "restore_drill_required": True,
            },
        )
        self.assertEqual(
            policy["destinations"],
            [
                {
                    "provider": "google_drive",
                    "role": "offsite_encrypted_copy",
                },
                {
                    "provider": "object_store",
                    "role": "versioned_or_immutable_copy",
                },
            ],
        )
        self.assertEqual(len(scenario("valid")["fingerprint"]), 64)

    def test_drive_is_only_encrypted_offsite_and_icloud_is_not_server_primary(self):
        for case in ("drive-primary", "drive-server", "icloud-primary", "icloud-server"):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])
        destinations = scenario("valid")["policy"]["destinations"]
        self.assertIn(
            {"provider": "google_drive", "role": "offsite_encrypted_copy"},
            destinations,
        )

    def test_extra_duplicate_invalid_and_sensitive_inputs_fail_closed(self):
        for case in (
            "extra-field",
            "duplicate-destination",
            "duplicate-provenance",
            "sensitive-ref",
            "bad-secret-strategy",
            "bad-policy-project",
        ):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_policy_and_fingerprint_are_order_independent(self):
        result = scenario("permuted")
        self.assertEqual(result["base"], result["permuted"])

    def test_policy_has_no_external_io_or_actions(self):
        source = (ROOT / "src" / "DisasterRecoveryPolicy.php").read_text(
            encoding="utf-8"
        )
        lowered = source.lower()
        for forbidden in (
            "curl_",
            "file_put_contents",
            "fopen(",
            "mysqli",
            "pdo(",
            "shell_exec",
            "exec(",
            "proc_open",
            "system(",
            "passthru(",
            "googleapis",
            "aws-sdk",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, lowered)
        self.assertNotIn("restore(", lowered)
        self.assertNotIn("backup(", lowered)


if __name__ == "__main__":
    unittest.main()
