import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "aegis_evidence_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class AegisEvidenceTests(unittest.TestCase):
    def test_compliance_states_require_explicit_evidence(self):
        data = scenario("compliance")
        by_id = {row["obligation_id"]: row for row in data["compliance"]}
        self.assertEqual(by_id["privacy-register"]["state"], "compliant")
        self.assertEqual(by_id["backup-policy"]["state"], "gap")
        self.assertTrue(by_id["privacy-register"]["evidence_refs"])
        self.assertTrue(by_id["backup-policy"]["evidence_refs"])
        self.assertNotIn("payload", by_id["privacy-register"])

    def test_missing_or_stale_evidence_never_claims_compliance(self):
        data = scenario("stale")
        row = data["compliance"][0]
        self.assertEqual(row["reported_state"], "compliant")
        self.assertEqual(row["freshness"], "stale")
        self.assertEqual(row["state"], "unknown")
        self.assertNotEqual(row["state"], "compliant")

    def test_backup_health_and_restore_verification_remain_distinct(self):
        data = scenario("continuity")
        self.assertEqual(data["backup_evidence"]["state"], "healthy")
        self.assertEqual(data["restore_evidence"]["state"], "unknown")
        self.assertEqual(data["restore_evidence"]["freshness"], "unknown")

    def test_incident_impact_links_technical_and_business_scopes(self):
        data = scenario("impact")
        row = data["incidents"][0]
        self.assertEqual(row["capabilities"], ["checkout", "inventory"])
        self.assertEqual(
            row["affected_scopes"],
            ["institution:pl0n3r", "project:storefront", "venture:condor"],
        )
        self.assertNotIn("cause", row)
        self.assertNotIn("causality", row)

    def test_obligations_preserve_version_owner_and_review_date(self):
        data = scenario("obligation")
        row = data["compliance"][0]
        self.assertEqual(row["version"], "resolution-001-v3")
        self.assertEqual(row["owner_ref"], "controlbot:aegis/owner-legal")
        self.assertEqual(row["review_at"], 5000)
        self.assertEqual(row["expires_at"], 7000)

    def test_sensitive_payloads_are_rejected(self):
        data = scenario("sensitive")
        self.assertIn("invalid", data["ref_rejected"])
        self.assertIn("fields invalid", data["payload_rejected"])

    def test_evidence_normalization_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            [row["obligation_id"] for row in data["first"]["compliance"]],
            ["access-review", "backup-policy", "privacy-register"],
        )
        self.assertEqual(
            [row["incident_id"] for row in data["first"]["incidents"]],
            ["incident-api", "incident-worker"],
        )


if __name__ == "__main__":
    unittest.main()
