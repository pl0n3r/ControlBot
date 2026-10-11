"""Strict acceptance bridge for ControlBot readiness scenario fixtures."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tests/product_readiness_checklist_scenarios.php"


def scenario(mode):
    result = subprocess.run(
        ["php", str(SCRIPT), mode], cwd=ROOT, text=True,
        capture_output=True, check=True, timeout=20,
    )
    return result.stdout.strip()


class ProductReadinessChecklistTests(unittest.TestCase):
    def test_macro_criteria_have_issue_or_explicit_blocker(self):
        report = json.loads(scenario("macro"))
        self.assertEqual({r["id"] for r in report["rows"]}, {"SECURITY", "LAUNCH"})
        for row in report["rows"]:
            self.assertTrue(row["issue"] is not None or row["has_blocker"])
        self.assertEqual(report["decision"], "NO_GO")
        self.assertFalse(report["authorizes_live"])

    def test_missing_evidence_is_unknown_with_reason(self):
        for mode, reason in (("missing", "evidence_missing"), ("stale", "evidence_stale")):
            with self.subTest(mode=mode):
                report = json.loads(scenario(mode))
                self.assertEqual(report["rows"][0]["status"], "UNKNOWN")
                self.assertEqual(report["rows"][0]["reason"], reason)
                self.assertTrue(report["no_go"])
                self.assertNotIn("GREEN", json.dumps(report))

    def test_p0_p1_without_issue_blocks_go(self):
        report = json.loads(scenario("high_missing"))
        self.assertTrue(report["no_go"])
        self.assertEqual(report["decision"], "NO_GO")
        self.assertEqual({r["priority"] for r in report["rows"]}, {"P0", "P1"})
        self.assertTrue(all(r["reason"] == "critical_issue_missing" for r in report["rows"]))
        eligible = json.loads(scenario("candidate"))
        self.assertEqual(eligible["decision"], "CANDIDATE_ONLY")
        self.assertFalse(eligible["authorizes_live"])
        self.assertEqual(scenario("hostile"), "blocked")
        self.assertNotIn("NEVER_ECHO", scenario("hostile"))


if __name__ == "__main__":
    unittest.main()
