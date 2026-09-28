import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "aegis_posture_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class AegisPostureTests(unittest.TestCase):
    def test_findings_preserve_security_evidence_without_secrets(self):
        data = scenario("findings")
        finding = data["posture"]["findings"][0]
        self.assertEqual(finding["category"], "infrastructure_security")
        self.assertEqual(finding["severity"], "medium")
        self.assertEqual(finding["scope"], "venture:condor")
        self.assertEqual(finding["evidence_refs"], ["controlbot:aegis/evidence-config"])
        self.assertEqual(finding["freshness"], "fresh")
        self.assertIn("invalid", data["secret_rejected"])

    def test_posture_is_strictly_scoped(self):
        data = scenario("scope")
        self.assertEqual(data["condor"]["scope"], "venture:condor")
        self.assertIn("scope mismatch", data["mismatch"])

    def test_identity_signals_require_authoritative_fresh_evidence(self):
        data = scenario("identity")
        by_metric = {row["metric"]: row for row in data["identity_signals"]}
        self.assertEqual(by_metric["mfa_coverage"]["state"], "known")
        self.assertEqual(by_metric["mfa_coverage"]["value"], 90)
        self.assertEqual(by_metric["privileged_identities"]["state"], "unknown")
        self.assertIsNone(by_metric["privileged_identities"]["value"])
        self.assertEqual(by_metric["orphan_accounts"]["state"], "unknown")
        self.assertIsNone(by_metric["orphan_accounts"]["value"])
        self.assertEqual(data["posture_state"], "unknown")

    def test_vulnerability_unknown_never_becomes_healthy(self):
        data = scenario("vulnerability")
        by_severity = {row["severity"]: row for row in data["vulnerability_signals"]}
        self.assertEqual(by_severity["high"]["freshness"], "unknown")
        self.assertEqual(by_severity["high"]["state"], "unknown")
        self.assertNotEqual(by_severity["high"]["state"], "clear")
        self.assertEqual(data["posture_state"], "unknown")

    def test_unknown_critical_finding_remains_unknown(self):
        data = scenario("unknown_finding")
        self.assertEqual(data["findings"][0]["severity"], "critical")
        self.assertEqual(data["findings"][0]["state"], "unknown")
        self.assertEqual(data["posture_state"], "unknown")

    def test_missing_supported_signals_keep_posture_unknown(self):
        data = scenario("missing_certainty")
        self.assertEqual(data["identity_missing"]["posture_state"], "unknown")
        self.assertEqual(data["vulnerability_missing"]["posture_state"], "unknown")

    def test_backup_and_restore_verification_are_separate_signals(self):
        data = scenario("continuity")
        self.assertEqual(data["backup_signal"]["state"], "available")
        self.assertEqual(data["backup_signal"]["freshness"], "fresh")
        self.assertEqual(data["restore_signal"]["state"], "unknown")
        self.assertEqual(data["restore_signal"]["freshness"], "unknown")
        self.assertEqual(data["posture_state"], "unknown")

    def test_duplicate_findings_merge_evidence_deterministically(self):
        data = scenario("dedupe")
        self.assertEqual(len(data["findings"]), 1)
        finding = data["findings"][0]
        self.assertEqual(finding["finding_id"], "finding-shared")
        self.assertEqual(
            finding["evidence_refs"],
            ["controlbot:aegis/evidence-a", "controlbot:aegis/evidence-b"],
        )
        self.assertEqual(data["posture_state"], "attention_required")

    def test_posture_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            [row["finding_id"] for row in data["first"]["findings"]],
            ["finding-access", "finding-config"],
        )


if __name__ == "__main__":
    unittest.main()
