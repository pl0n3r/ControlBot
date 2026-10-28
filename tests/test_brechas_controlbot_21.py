"""ControlBot #1028: 15 synthetic offline regressions; no live verification.

A successful fixture proves only the local behavior, never deployment,
permission, real alert delivery, external probes or owner authorization.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "matriz-cobertura-parte-02.md"
UNKNOWN = "UNKNOWN"
IDS = (
    "CB-0302", "CB-0303", "CB-0304", "CB-0306", "CB-0307",
    "CB-0308", "CB-0309", "CB-0311", "CB-0312", "CB-0313",
    "CB-0314", "CB-0315", "CB-0316", "CB-0317", "CB-0318",
)
FIXTURES = (
    "external_monitor_scenarios.php",
    "factory_agent_activity_scenarios.php",
    "factory_blockers_panel_scenarios.php",
)


@lru_cache(maxsize=None)
def scenario(file: str, mode: str) -> dict:
    if file not in FIXTURES:
        raise AssertionError("Fixture fuera de la allowlist offline")
    completed = subprocess.run(
        ["php", str(ROOT / "tests" / file), mode], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=40,
    )
    if completed.returncode:
        raise AssertionError(f"Escenario {file}/{mode}: {completed.stderr[:300]}")
    return json.loads(completed.stdout)


def matrix_states(content: str) -> dict[str, str]:
    """Never infer coverage from absent, duplicated or promoted evidence."""
    results = {clause: UNKNOWN for clause in IDS}
    for clause in IDS:
        rows = [row for row in content.splitlines()
                if row.startswith(f"| {clause} |")]
        if len(rows) == 1:
            cells = [cell.strip() for cell in rows[0].strip("|").split("|")]
            if (len(cells) == 6 and cells[0] == clause
                    and cells[3] == "NO_VERIFICABLE"
                    and "Offline=UNKNOWN" in cells[4]):
                results[clause] = "NO_VERIFICABLE"
    return results


def operational_state(_clause: str, _offline: object = None) -> str:
    """No local fixture can certify live/production behavior."""
    return UNKNOWN


class BrechasControlbot21Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one independently asserted behavior per specified ID."""
        external = "external_monitor_scenarios.php"
        activity = "factory_agent_activity_scenarios.php"
        blockers = "factory_blockers_panel_scenarios.php"
        incident = scenario(external, "incident78")
        alerts = scenario(external, "alert")
        full = scenario(activity, "full")["view"]
        queue = scenario(activity, "queue")
        safety = scenario(activity, "safety")
        grounded = scenario(blockers, "grounded")
        topk = scenario(blockers, "topk-late")
        reversed_topk = scenario(blockers, "topk-reversed")
        stop = scenario(blockers, "topk-stop")
        unknown = scenario(blockers, "no-provenance")
        panel = grounded["html"].split('data-section="blockers_explained"', 1)[1]
        panel = panel.split("</section>", 1)[0].lower()
        sources = (ROOT / "src" / "FactoryOrchestratorEvidenceCollector.php").read_text()
        activity_ui = (ROOT / "src" / "FactoryAgentActivityUi.php").read_text()
        workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text()
        ranked = [row["issue_ref"] for row in topk["blockers"]]
        sentinel = next((row for row in stop["blockers"]
                         if row["issue_ref"] == "github:pl0n3r/Factory#767"), None)
        grounded_rows = grounded["blockers"]
        bad_row = next((row for row in unknown["blockers"]
                        if row["issue_ref"] == "github:pl0n3r/Factory#10"), None)
        verdicts = {
            "CB-0302": (incident["application_state"] == "unknown"
                        and incident["owner_capacity_state"] == "exhausted"
                        and incident["private_workflow_state"] == "blocked"
                        and incident["billing_mechanism_state"] == "unknown"),
            "CB-0303": (all(alerts[k]["alert_intent"]["required"] is True
                                and alerts[k]["alert_intent"]["external_channel_required"] is True
                                for k in ("capacity", "capacity_without_workflow",
                                          "critical_capacity", "outage"))
                        and alerts["secret_rejected"] is True),
            # This core has no evidence of real HTTP, delivery, cron or backups.
            "CB-0304": (incident["application_state"] == "unknown"
                        and operational_state("CB-0304", incident) == UNKNOWN
                        and "external_delivery" not in incident),
            "CB-0306": (len(full["projects"]) == 7 and full["read_only"] is True
                        and all(p["activity_state"] == "OBSERVED" for p in full["projects"])
                        and safety["partial"]["projects"][0]["activity_state"] == UNKNOWN),
            "CB-0307": (queue["valid"]["queue_empty"] is True
                        and queue["unknown"]["queue_empty"] is None
                        and queue["stale"]["queue_empty"] is None),
            "CB-0308": ('data-section="agent_activity"' in safety["html"]
                        and "htmlspecialchars(" in activity_ui
                        and "ENT_QUOTES" in activity_ui
                        and "<script" not in safety["html"].lower()
                        and "<form" not in safety["html"].lower()),
            # A YAML contract does not replace the exact-HEAD CI gate.
            "CB-0309": ("name: Validar" in workflow
                        and "needs: [factory, contrato]" in workflow
                        and operational_state("CB-0309", {"ci": "success"}) == UNKNOWN),
            "CB-0311": (grounded["failed"] is False
                        and scenario(blockers, "malformed-labels")["failed"] is True
                        and bad_row is not None
                        and bad_row["evidence_status"] == UNKNOWN),
            "CB-0312": (bool(grounded_rows)
                        and grounded_rows[0]["evidence_status"] == "DECLARED"
                        and grounded_rows[0]["mvp_impact"] is True
                        and bad_row is not None and bad_row["age_seconds"] is None
                        and "verified_in_production" not in panel),
            "CB-0313": (all(call["method"] == "GET" for call in grounded["calls"])
                        and len(topk["blockers"]) == 50
                        and "<script" not in panel and "<button" not in panel
                        and "<form" not in panel),
            "CB-0314": (len(grounded_rows) == 21
                        and len({r["issue_ref"] for r in grounded_rows}) == 21
                        and all(not r["issue_ref"].endswith(("#12", "#13"))
                                for r in grounded_rows)
                        and safety["duplicate"] is True),
            "CB-0315": (ranked == [r["issue_ref"] for r in reversed_topk["blockers"]]
                        and len(ranked) == 50
                        and all(r["mvp_impact"] is True for r in topk["blockers"][:7])
                        and topk["summary"]["truncated"] is True),
            "CB-0316": (bad_row is not None and bad_row["mvp_impact"] is None
                        and bad_row["blocked_at"] is None
                        and bad_row["cause"] == UNKNOWN),
            "CB-0317": ("private const MAX_REQUESTS=40;" in sources
                        and "private const MAX_DOWNLOAD_BYTES=8_000_000;" in sources
                        and "private const MAX_EVIDENCE_BYTES=2_000_000;" in sources
                        and all(c["method"] == "GET" for c in topk["calls"])),
            "CB-0318": (sentinel is not None
                        and sentinel["evidence_status"] == UNKNOWN
                        and sentinel["mvp_impact"] is None
                        and stop["source"][0]["id"] == "blocker:factory-767"
                        and not any(row["id"] == "blocker:factory-767"
                                    for row in topk["source"])),
        }
        self.assertEqual(set(verdicts), set(IDS))
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(states), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(verdicts[clause], f"No hay prueba ejecutable para {clause}")
                self.assertEqual(states[clause], "NO_VERIFICABLE")
                self.assertEqual(operational_state(clause, verdicts[clause]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: incomplete/hostile evidence cannot be promoted to success."""
        source = MATRIX.read_text(encoding="utf-8")
        for clause in IDS:
            row = next(line for line in source.splitlines()
                       if line.startswith(f"| {clause} |"))
            for corruption in (source.replace(row + "\n", "", 1),
                               source + "\n" + row,
                               source.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(corruption)[clause], UNKNOWN)
        blockers = "factory_blockers_panel_scenarios.php"
        for mode in ("no-provenance", "invalid-time", "unsafe", "duplicate"):
            row = next(r for r in scenario(blockers, mode)["blockers"]
                       if r["issue_ref"] == "github:pl0n3r/Factory#10")
            self.assertEqual(row["evidence_status"], UNKNOWN)
            self.assertIsNone(row["mvp_impact"])
            self.assertIsNone(row["age_seconds"])
        self.assertIsNone(scenario("factory_agent_activity_scenarios.php", "queue")
                          ["unknown"]["queue_empty"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: local PHP fixtures; secret-free and nonmutating HTML views."""
        for name in FIXTURES:
            self.assertTrue((ROOT / "tests" / name).is_file())
        html = scenario("factory_blockers_panel_scenarios.php", "grounded")["html"]
        html = html.split('data-section="blockers_explained"', 1)[1].split("</section>", 1)[0]
        activity = scenario("factory_agent_activity_scenarios.php", "safety")["html"]
        for view in (html, activity):
            lowered = view.lower()
            for forbidden in ("<script", "<form", "<button", "onclick=", "token="):
                self.assertNotIn(forbidden, lowered)
        self.assertTrue(all(call["method"] == "GET" for call in
                            scenario("factory_blockers_panel_scenarios.php", "grounded")["calls"]))
        self.assertNotIn("secret", json.dumps(
            scenario("external_monitor_scenarios.php", "alert")["capacity"]).lower())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local tests and even CI success are not production evidence."""
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertNotEqual(states[clause], "CUBIERTO")
                for claim in (None, {}, True, "success", "green", {"ci": "success"},
                              {"approval": "granted"}, {"production": "claimed"},
                              {"external_delivery": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)
        lost = scenario("external_monitor_scenarios.php", "freshness")
        self.assertEqual(lost["missing"]["application_state"], "unknown")
        self.assertEqual(lost["unknown"]["application_state"], "unknown")
        self.assertIsNone(scenario("factory_agent_activity_scenarios.php", "safety")
                          ["partial"]["projects"][0]["last_signal_at"])


if __name__ == "__main__":
    unittest.main()
