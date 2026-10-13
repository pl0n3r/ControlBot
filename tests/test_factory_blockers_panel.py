"""Read-only real-PHP contract regression for grounded blockers."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / "tests/factory_blockers_panel_scenarios.php"


def scenario(name):
    p = subprocess.run(
        ["php", str(SCENARIO), name], cwd=ROOT,
        check=True, capture_output=True, text=True, timeout=30,
    )
    return json.loads(p.stdout)


class FactoryBlockersPanelTests(unittest.TestCase):
    def test_real_blockers_show_grounded_cause_owner_age_and_next_action(self):
        view = scenario("grounded")
        self.assertFalse(view["failed"])
        rows = view["blockers"]
        self.assertEqual(len(rows), 21)  # 3 blocked x 7 repos, never planned.
        first = rows[0]
        self.assertTrue(first["mvp_impact"])
        self.assertEqual(first["actor"], "agent")
        self.assertEqual(first["evidence_status"], "DECLARED")
        self.assertEqual(first["cause"], "Pruebas CI sin terminar")
        self.assertEqual(first["next_action"], "Reparar verificacion exacta")
        self.assertIsInstance(first["age_seconds"], int)
        self.assertGreater(first["age_seconds"], 0)
        self.assertIn("Causa: Pruebas CI sin terminar", view["html"])
        self.assertIn("Responsable declarado: agent", view["html"])
        self.assertIn("Fuente: github:pl0n3r/", view["html"])
        self.assertTrue(all(c["method"] == "GET" for c in view["calls"]))

    def test_planned_and_conflicting_states_never_count_as_real_blockers(self):
        view = scenario("grounded")
        refs = [row["issue_ref"] for row in view["blockers"]]
        self.assertFalse(any(ref.endswith("#12") or ref.endswith("#13") for ref in refs))
        self.assertEqual(len(refs), len(set(refs)))
        self.assertFalse(any(
            e["id"].endswith("-12") or e["id"].endswith("-13")
            for e in view["source"]
        ))
        self.assertIn("Bloqueos explicados", view["html"])
        self.assertNotIn("No es bloqueo real", view["html"])
        self.assertNotIn("Estado contradictorio", view["html"])

    def test_legacy_blocked_signal_without_labels_does_not_break_other_panels(self):
        # Historical canonical snapshots predate the opt-in label evidence of #753.
        legacy = ROOT / "tests/factory_owner_decisions_inbox_scenarios.php"
        for kind in ("structured", "hostile"):
            with self.subTest(kind=kind):
                p = subprocess.run(["php", str(legacy), kind], cwd=ROOT,
                                   capture_output=True, text=True, timeout=30)
                self.assertEqual(p.returncode, 0, p.stderr)
                result = json.loads(p.stdout)
                self.assertTrue(result["view"]["owner_decisions"])
                self.assertEqual(result["view"]["blocker_explanations"], [])

    def test_explicit_malformed_blocker_labels_still_fail_closed(self):
        sample = scenario("malformed-labels")
        self.assertTrue(sample["failed"])
        self.assertFalse(sample["artifact_written"] is False)  # original collector evidence remains

    def test_missing_provenance_and_invalid_time_fail_closed(self):
        for mode in ("no-provenance", "invalid-time", "unsafe", "duplicate"):
            with self.subTest(mode=mode):
                view = scenario(mode)
                self.assertFalse(view["failed"])
                target = [row for row in view["blockers"] if row["issue_ref"] == "github:pl0n3r/Factory#10"][0]
                self.assertEqual(target["evidence_status"], "UNKNOWN")
                self.assertEqual(target["cause"], "UNKNOWN")
                self.assertEqual(target["actor"], "UNKNOWN")
                self.assertIsNone(target["blocked_at"])
                self.assertIsNone(target["age_seconds"])
                self.assertIsNone(target["mvp_impact"])
                self.assertNotIn("<script>injection</script>", view["html"])
        bad = scenario("invalid-page")
        self.assertTrue(bad["failed"])
        self.assertFalse(bad["artifact_written"])

    def test_declared_blocker_cannot_postdate_source_observation(self):
        valid = scenario("grounded")
        self.assertFalse(valid["failed"])
        self.assertTrue(any(row["evidence_status"] == "DECLARED" for row in valid["blockers"]))
        forged = scenario("after-source-observation")
        self.assertTrue(forged["failed"])
        self.assertTrue(forged["artifact_written"])

    def test_private_html_escaping_and_deterministic_ranking(self):
        a, b = scenario("grounded"), scenario("grounded")
        refs_a = [row["issue_ref"] for row in a["blockers"]]
        self.assertEqual(refs_a, [row["issue_ref"] for row in b["blockers"]])
        self.assertTrue(all(row["mvp_impact"] is True for row in a["blockers"][:7]))
        self.assertTrue(all(row["mvp_impact"] is False for row in a["blockers"][7:14]))
        self.assertTrue(all(row["evidence_status"] == "UNKNOWN" for row in a["blockers"][14:]))
        html = a["html"]
        panel = html.split('data-section="blockers_explained"', 1)[1].split("</section>", 1)[0]
        for forbidden in ("<script", "<form", "<button", "onclick=", "token="):
            self.assertNotIn(forbidden, panel.lower())
        self.assertIn("UNKNOWN", panel)
        self.assertIn("DECLARED", panel)
        self.assertIn("read-only", panel)


if __name__ == "__main__":
    unittest.main()
