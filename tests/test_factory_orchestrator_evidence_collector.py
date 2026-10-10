"""Regresiones fake de saturación del colector, sin red."""
import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/factory_orchestrator_evidence_collector_scenarios.php"
COLLECTOR = ROOT / "src/FactoryOrchestratorEvidenceCollector.php"
def scenario(name):
    run = subprocess.run(["php", str(SCENARIOS), name], cwd=ROOT, check=True, text=True, capture_output=True, timeout=30)
    return json.loads(run.stdout)
class FactoryOrchestratorEvidenceCollectorTests(unittest.TestCase):
    def test_over_budget_truncates_and_counts_without_abort(self):
        r = scenario("over-400")
        self.assertEqual((r["run"]["executed"], r["run"]["state"]), (True, "written"))
        self.assertEqual((r["blockers"], r["work"], r["fronts"]), (50, 24, 24))
        self.assertEqual(r["summary"], r["view_summary"])
        self.assertEqual(r["summary"]["reason"], "bounded_signal_budget")
        self.assertTrue(r["summary"]["truncated"])
        self.assertEqual(r["summary"]["omitted"], {
            "blockers": 230, "owner_decisions": 0, "work": 256,
        })
    def test_running_marker_requires_closed_767_issue(self):
        # A reopened or malformed kill-switch source must fail closed.
        self.assertEqual(scenario('kill-switch-state'), {
            'closed': False, 'open': True, 'missing': True,
        })

    def test_bounded_volume_over_400_signals_is_explicit(self):
        r = scenario("over-400")
        total = r["blockers"] + r["work"] + sum(r["summary"]["omitted"].values())
        self.assertEqual(total, 560)
        self.assertGreater(total, 400)
        self.assertLessEqual(r["run"]["requests"], 40)
        src = COLLECTOR.read_text(encoding="utf-8")
        for invariant in ("private const MAX_REQUESTS=40;", "private const MAX_DOWNLOAD_BYTES=8_000_000;", "private const MAX_EVIDENCE_BYTES=2_000_000;"):
            self.assertIn(invariant, src)
if __name__ == "__main__":
    unittest.main()
