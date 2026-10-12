"""Contract tests for planned state adoption; PHP scenarios use fake GET only."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / "tests/factory_planned_state_scenarios.php"


def scenario(mode):
    result = subprocess.run(
        ["php", str(SCENARIO), mode],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=30,
    )
    return json.loads(result.stdout)


class PlannedStateAdoptionTests(unittest.TestCase):
    def test_planned_is_separate_from_blocked_and_not_dispatchable(self):
        result = scenario("valid")
        self.assertFalse(result["failed"])
        self.assertTrue(result["view"]["read_only"])
        self.assertEqual(len(result["view"]["projects"]), 7)
        self.assertIsNone(result["view"]["queue_empty"])
        for project in result["view"]["projects"]:
            self.assertEqual(project["freshness"], "current")
            self.assertEqual(project["activity_state"], "OBSERVED")
            self.assertEqual(project["planned"], 2)
            self.assertEqual(project["blocked"], 1)
            self.assertEqual(project["available"], 1)
            self.assertEqual(project["reserved"], 1)
            self.assertIsNone(project["planned_unlockable"])
        self.assertEqual(len(result["evidence"]["blockers"]), 7)
        self.assertEqual(len(result["evidence"]["work"]), 14)
        self.assertFalse(any(
            entry["id"].endswith("-1") or entry["id"].endswith("-5")
            for entry in result["evidence"]["work"]
        ))
        self.assertIn("Planificados (no despachables): 2", result["html"])
        self.assertIn("Bloqueados: 1", result["html"])

    def test_conflicting_workflow_states_fail_closed(self):
        for name in ("available-planned", "available-blocked", "blocked-planned"):
            with self.subTest(name=name):
                result = scenario(name)
                self.assertFalse(result["failed"])
                first = result["view"]["projects"][0]
                self.assertEqual(first["freshness"], "unknown")
                self.assertEqual(first["activity_state"], "UNKNOWN")
                self.assertIsNone(first["available"])
                self.assertIsNone(first["planned"])
                self.assertIsNone(first["blocked"])
                self.assertIsNone(result["view"]["queue_empty"])
                self.assertFalse(any(
                    e["id"] == "work:factory-3"
                    for e in result["evidence"]["work"]
                ))
        # Equivalent ES/EN aliases describe one planned state, not a conflict.
        self.assertEqual(scenario("valid")["view"]["projects"][0]["planned"], 2)

    def test_unknown_pagination_or_label_provenance_cannot_infer_ready(self):
        for mode in ("bad-page", "bad-label"):
            with self.subTest(mode=mode):
                result = scenario(mode)
                self.assertTrue(result["failed"])
                self.assertFalse(result["artifact_written"])
        invalid_time = scenario("bad-clock")
        self.assertFalse(invalid_time["failed"])
        first = invalid_time["view"]["projects"][0]
        self.assertEqual(first["activity_state"], "UNKNOWN")
        self.assertIsNone(first["available"])
        self.assertIsNone(first["planned"])
        self.assertIsNone(invalid_time["view"]["queue_empty"])

    def test_legacy_states_and_private_read_only_ui_stay_compatible(self):
        result = scenario("legacy")
        self.assertFalse(result["failed"])
        project = result["view"]["projects"][0]
        self.assertEqual(
            (project["available"], project["reserved"], project["blocked"], project["planned"]),
            (1, 2, 1, 2),
        )
        html = result["html"]
        for forbidden in ("<script", "<form", "<button", "onclick=", "token="):
            self.assertNotIn(forbidden, html.lower())
        self.assertIn('data-section="agent_activity"', html)
        self.assertIn("Planificados (no despachables): 2", html)
        self.assertTrue(all(c["method"] == "GET" for c in result["calls"]))
        self.assertIsNone(result["view"]["queue_empty"])


if __name__ == "__main__":
    unittest.main()
