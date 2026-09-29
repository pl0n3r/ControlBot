import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_lifecycle_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexLifecycleTests(unittest.TestCase):
    def test_construction_pending_review_is_documented_not_legally_approved_without_blocking_safe_reversible_work(self):
        data = scenario("construction")
        self.assertEqual(
            data["construction_legal_status"],
            "documented_not_legally_approved",
        )
        self.assertTrue(data["construction_work_allowed"])
        self.assertTrue(data["transition_allowed"])
        self.assertEqual(data["live_legal_gate"]["state"], "blocked")
        self.assertFalse(data["live_legal_gate"]["required_now"])
        self.assertNotEqual(data["legal_state"], "compliant")

    def test_live_or_real_data_requires_approved_human_review_and_compatible_legal_state(self):
        data = scenario("live")
        self.assertFalse(data["pending"]["transition_allowed"])
        self.assertFalse(data["real_data"]["transition_allowed"])
        self.assertFalse(data["gap"]["transition_allowed"])
        self.assertTrue(data["approved"]["transition_allowed"])
        self.assertEqual(data["approved"]["live_legal_gate"]["state"], "pass")
        self.assertEqual(
            data["approved"]["live_legal_gate"]["evidence_refs"],
            ["controlbot:lex/evidence/legal-review"],
        )
        self.assertTrue(data["approved_without_evidence_blocked"])

    def test_material_legal_risk_blocks_affected_scope_without_expanding_authority(self):
        data = scenario("risk")
        for candidate in (data["material"], data["rejected"]):
            self.assertFalse(candidate["construction_work_allowed"])
            self.assertFalse(candidate["transition_allowed"])
            self.assertEqual(candidate["live_legal_gate"]["state"], "blocked")
            self.assertEqual(candidate["authority_effect"], "none")
            self.assertFalse(candidate["auto_execute"])
        self.assertEqual(
            data["material"]["construction_legal_status"],
            "blocked_legal_risk",
        )

    def test_sensitive_or_ambiguous_review_evidence_fails_closed(self):
        data = scenario("invalid-evidence")
        self.assertTrue(data["session"])
        self.assertTrue(data["traversal"])

    def test_docs_separate_construction_from_live_and_unknown_is_never_approval(self):
        text = (ROOT / "docs" / "lex-lifecycle.md").read_text(encoding="utf-8")
        for marker in (
            "construction_legal_status",
            "documented_not_legally_approved",
            "live_legal_gate",
            "UNKNOWN nunca es aprobación legal",
            "datos reales",
            "authority_effect=none",
        ):
            self.assertIn(marker, text)


if __name__ == "__main__":
    unittest.main()
