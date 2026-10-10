"""Regresiones deterministas de saturación del colector de Orquestador."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "factory_orchestrator_evidence_collector_scenarios.php"
COLLECTOR = ROOT / "src" / "FactoryOrchestratorEvidenceCollector.php"


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(SCENARIOS), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=30,
    )
    return json.loads(result.stdout)


class FactoryOrchestratorEvidenceCollectorTests(unittest.TestCase):
    def test_over_budget_truncates_and_counts_without_abort(self):
        result = scenario("over-400")
        self.assertTrue(result["run"]["executed"])
        self.assertEqual(result["run"]["state"], "written")
        self.assertTrue(result["summary"]["truncated"])
        self.assertEqual(result["summary"]["reason"], "bounded_signal_budget")
        self.assertEqual(result["blockers"], 50)
        self.assertEqual(result["work"], 24)
        self.assertEqual(result["fronts"], 24)
        self.assertEqual(result["summary"]["omitted"]["blockers"], 230)
        self.assertEqual(result["summary"]["omitted"]["work"], 256)
        self.assertEqual(result["summary"], result["view_summary"])

    def test_bounded_volume_over_400_signals_is_explicit(self):
        result = scenario("over-400")
        omitted = result["summary"]["omitted"]
        observed = result["blockers"] + result["work"] + sum(omitted.values())
        self.assertEqual(observed, 560, "all 560 fake signals must be accounted for")
        self.assertGreater(observed, 400)
        self.assertEqual(omitted["owner_decisions"], 0)
        self.assertLessEqual(result["run"]["requests"], 40)
        source = COLLECTOR.read_text(encoding="utf-8")
        for invariant in (
            "private const MAX_REQUESTS=40;",
            "private const MAX_DOWNLOAD_BYTES=8_000_000;",
            "private const MAX_EVIDENCE_BYTES=2_000_000;",
        ):
            self.assertIn(invariant, source, "real network/size budgets must remain")


if __name__ == "__main__":
    unittest.main()
