import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "aegis_remediation_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class AegisRemediationTests(unittest.TestCase):
    def test_only_preapproved_reversible_low_blast_radius_is_auto_eligible(self):
        data = scenario("auto")
        self.assertEqual(data["decision"], "auto_eligible")
        self.assertTrue(data["auto_eligible"])
        self.assertTrue(data["proposal"]["preauthorized"])
        self.assertTrue(data["proposal"]["reversible"])
        self.assertEqual(data["proposal"]["blast_radius"], "low")
        self.assertEqual(
            data["proposal"]["required_authority_level"],
            "L0_AI_AUTONOMOUS",
        )

    def test_unknown_or_insufficient_authority_fails_closed(self):
        data = scenario("authority")
        self.assertEqual(data["unknown_policy"]["decision"], "deny")
        self.assertFalse(data["unknown_policy"]["auto_eligible"])
        self.assertEqual(data["policy_mismatch"]["decision"], "deny")
        self.assertFalse(data["policy_mismatch"]["auto_eligible"])
        self.assertEqual(
            data["insufficient"]["decision"],
            "owner_decision_required",
        )

    def test_high_risk_categories_require_owner_decision(self):
        data = scenario("high_risk")
        for name, plan in data.items():
            with self.subTest(name=name):
                self.assertEqual(plan["decision"], "owner_decision_required")
                self.assertFalse(plan["auto_eligible"])

    def test_remediation_idempotency_is_stable(self):
        data = scenario("idempotency")
        self.assertEqual(
            data["first"]["idempotency_key"],
            data["second"]["idempotency_key"],
        )
        self.assertNotEqual(
            data["first"]["idempotency_key"],
            data["different"]["idempotency_key"],
        )
        self.assertEqual(len(data["batch"]), 2)
        self.assertEqual(
            len({plan["idempotency_key"] for plan in data["batch"]}),
            2,
        )

    def test_success_requires_verification_evidence(self):
        data = scenario("verification")
        self.assertEqual(data["pending"]["remediation_state"], "not_verified")
        self.assertEqual(data["verified"]["remediation_state"], "success")
        self.assertTrue(data["verified"]["verification"]["evidence_refs"])
        self.assertTrue(data["verified_without_evidence_rejected"])

    def test_policy_layer_has_no_provider_execution(self):
        data = scenario("no_provider")
        self.assertEqual(data["decision"], "auto_eligible")
        source = (ROOT / "src" / "AegisRemediation.php").read_text()
        for forbidden in (
            "curl_",
            "shell_exec",
            "exec(",
            "system(",
            "proc_open",
            "file_get_contents('http",
        ):
            self.assertNotIn(forbidden, source)

    def test_remediation_plan_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            data["first"]["proposal"]["risk_categories"],
            ["legal", "privacy"],
        )
        self.assertEqual(
            data["first"]["verification"]["evidence_refs"],
            ["controlbot:aegis/a-evidence", "controlbot:aegis/z-evidence"],
        )


if __name__ == "__main__":
    unittest.main()
