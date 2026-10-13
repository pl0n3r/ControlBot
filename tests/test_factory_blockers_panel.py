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
    def test_topk_keeps_late_mvp_blocker(self):
        view = scenario("topk-late")
        self.assertFalse(view["failed"], view)
        blockers = view["blockers"]
        self.assertEqual(len(blockers), 50)
        # Cada repositorio entrega 50 UNKNOWN antes del MVP tardío (#999).
        mvp = [row for row in blockers if row["mvp_impact"] is True]
        self.assertEqual(len(mvp), 7)
        self.assertEqual(len({row["issue_ref"] for row in mvp}), 7)
        self.assertTrue(all(row["issue_ref"].endswith("#999") for row in mvp))
        self.assertTrue(all(row["evidence_status"] == "DECLARED" for row in mvp))
        self.assertIn("Bloqueo critico MVP", view["html"])
        self.assertTrue(all(call["method"] == "GET" for call in view["calls"]))

    def test_topk_remains_bounded_and_reports_omission(self):
        view = scenario("topk-late")
        self.assertFalse(view["failed"], view)
        self.assertEqual(len(view["source"]), 50)
        self.assertEqual(len(view["blockers"]), 50)
        summary = view["summary"]
        # Por repo: 50 UNKNOWN + 1 impacto true + 1 false + 1 futuro
        # (53 señales); la etiqueta contradictoria no es un bloqueo real.
        self.assertTrue(summary["truncated"])
        self.assertEqual(summary["omitted"]["blockers"], 7 * 53 - 50)
        self.assertEqual(summary["omitted"]["owner_decisions"], 0)
        self.assertEqual(summary["omitted"]["work"], 0)
        self.assertEqual(summary["reason"], "bounded_signal_budget")
        self.assertIn("Omitidas: bloqueos=321", view["html"])

    def test_topk_is_order_independent_and_fail_closed(self):
        baseline = scenario("topk-late")
        self.assertFalse(baseline["failed"], baseline)
        expected = [row["issue_ref"] for row in baseline["blockers"]]
        for mode in ("topk-reversed", "topk-hostile"):
            with self.subTest(mode=mode):
                reordered = scenario(mode)
                self.assertFalse(reordered["failed"], reordered)
                self.assertEqual(
                    [row["issue_ref"] for row in reordered["blockers"]],
                    expected,
                )
                self.assertEqual(reordered["summary"], baseline["summary"])
                self.assertFalse(any(
                    row["issue_ref"].endswith("#996")  # etiquetas contradictorias
                    for row in reordered["blockers"]
                ))
                self.assertFalse(any(
                    row["issue_ref"].endswith("#997") and row["mvp_impact"] is True
                    for row in reordered["blockers"]
                ))

    def test_topk_keeps_global_kill_switch_blocker(self):
        # A stopped global guard is not ordinary declarative MVP evidence.
        # This only exercises the fake GitHub transport, not Factory #767.
        normal = scenario("topk-late")
        stopped = scenario("topk-stop")
        self.assertFalse(normal["failed"], normal)
        self.assertFalse(stopped["failed"], stopped)
        ref = "github:pl0n3r/Factory#767"
        self.assertFalse(any(r["data"]["issue_ref"] == ref for r in normal["source"]))
        self.assertEqual(len(stopped["source"]), 50)
        self.assertEqual(len(stopped["blockers"]), 50)
        self.assertEqual(stopped["source"][0]["id"], "blocker:factory-767")
        self.assertEqual(stopped["blockers"][0]["issue_ref"], ref)
        self.assertEqual(stopped["blockers"][0]["evidence_status"], "UNKNOWN")
        self.assertIsNone(stopped["blockers"][0]["mvp_impact"])
        self.assertEqual(
            stopped["summary"]["omitted"]["blockers"],
            normal["summary"]["omitted"]["blockers"] + 1,
        )
        self.assertTrue(stopped["summary"]["truncated"])

    def test_factory_stop_sentinel_is_unique_with_blocked_issue_767(self):
        # Synthetic Factory #767 is open, blocked, and declares MVP impact;
        # it must not compete with its own canonical stop-sentinel.
        stopped = scenario("topk-stop")
        duplicate = scenario("topk-stop-duplicate")
        self.assertFalse(stopped["failed"], stopped)
        self.assertFalse(duplicate["failed"], duplicate)
        expected_ref = "github:pl0n3r/Factory#767"
        rows = duplicate["source"]
        view = duplicate["blockers"]
        self.assertEqual(len(rows), 50)
        self.assertEqual(len(view), 50)
        self.assertEqual(
            sum(row["id"] == "blocker:factory-767" for row in rows), 1
        )
        self.assertEqual(
            sum(row["issue_ref"] == expected_ref for row in view), 1
        )
        self.assertEqual(rows[0]["id"], "blocker:factory-767")
        self.assertEqual(view[0]["issue_ref"], expected_ref)
        self.assertEqual(view[0]["evidence_status"], "UNKNOWN")
        self.assertIsNone(view[0]["mvp_impact"])
        # The duplicate in Issues never consumes a second top-K slot or
        # changes the total count of omitted *distinct* blockers.
        self.assertEqual(duplicate["summary"], stopped["summary"])
        self.assertEqual(
            [row["id"] for row in rows],
            [row["id"] for row in stopped["source"]],
        )
        self.assertEqual(
            duplicate["summary"]["omitted"]["blockers"], 7 * 53 + 1 - 50
        )
        self.assertEqual(
            duplicate["summary"]["omitted"]["work"],
            stopped["summary"]["omitted"]["work"],
        )

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
                # Legacy blockers lack new labels; only the fail-closed
                # global-stop sentinel is projected, without inferred cause.
                projected = result["view"]["blocker_explanations"]
                self.assertEqual(len(projected), 1)
                self.assertEqual(projected[0]["issue_ref"],
                                 "github:pl0n3r/Factory#767")
                self.assertEqual(projected[0]["evidence_status"], "UNKNOWN")
                self.assertIsNone(projected[0]["mvp_impact"])

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
