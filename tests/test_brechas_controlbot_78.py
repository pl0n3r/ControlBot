"""CB-1192: prueba offline de volumen, presupuesto y evidencia no productiva.

La regresión sintética no acredita #760 AC-04 ni D-043: esas puertas requieren
observación real de collector, cron, snapshot y UI.
"""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests"
MATRIX = ROOT / "docs/matriz-cobertura-parte-04.md"
LOAD_SCRIPT = ROOT / "docs/signal-budget-load-script.md"


def php_scenario(file_name: str, name: str, *, as_json: bool = True):
    """Ejecuta fixtures PHP existentes: sin conexiones ni credenciales reales."""
    result = subprocess.run(
        ["php", str(SCENARIOS / file_name), name],
        cwd=ROOT, capture_output=True, text=True, check=True, timeout=30,
    )
    return json.loads(result.stdout) if as_json else result.stdout


class BrechasControlbot78Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """CB-1192: 560 señales ficticias, resultado escrito y límites explícitos."""
        evidence = php_scenario("factory_orchestrator_evidence_collector_scenarios.php", "over-400")
        self.assertEqual((evidence["run"]["executed"], evidence["run"]["state"]), (True, "written"))
        self.assertEqual((evidence["blockers"], evidence["work"], evidence["fronts"]), (50, 24, 24))
        summary = evidence["summary"]
        self.assertEqual(summary, evidence["view_summary"])
        self.assertEqual((summary["truncated"], summary["reason"]), (True, "bounded_signal_budget"))
        self.assertEqual(summary["omitted"], {"blockers": 230, "owner_decisions": 0, "work": 256})
        total = evidence["blockers"] + evidence["work"] + sum(summary["omitted"].values())
        self.assertEqual(total, 560)
        self.assertGreater(total, 400)
        self.assertLessEqual(evidence["run"]["requests"], 40)

    def test_falla_cerrado(self):
        """Falla del colector: snapshot previo stale, no reusar work antiguo."""
        stale = php_scenario("factory_orchestrator_snapshot_refresh_scenarios.php", "stale_fallback")
        self.assertEqual((stale["status"], stale["reason"]), ("stale", "evidence_invalid"))
        self.assertTrue(stale["visible_stale"])
        self.assertTrue(stale["visible_last_success"])
        self.assertTrue(stale["no_old_work"])
        self.assertEqual((stale["old_view_state"], stale["old_view_fronts"]), ("UNKNOWN", 0))
        self.assertEqual(
            php_scenario("factory_orchestrator_live_ui_scenarios.php", "invalid_summary", as_json=False),
            "blocked",
        )

    def test_sin_datos_reales(self):
        """El fixture ejerce transporte inyectado y no expone credenciales."""
        fixture = (SCENARIOS / "factory_orchestrator_evidence_collector_scenarios.php").read_text(encoding="utf-8")
        self.assertIn("$many=static function(", fixture)
        self.assertIn("'sentinel-read-value'", fixture)
        self.assertNotIn("curl_exec(", fixture)
        payload = php_scenario("factory_orchestrator_evidence_collector_scenarios.php", "over-400")
        output = json.dumps(payload, ensure_ascii=False)
        self.assertNotIn("sentinel-read-value", output)
        self.assertNotRegex(output, r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}")
        doc = LOAD_SCRIPT.read_text(encoding="utf-8")
        self.assertIn("NO ENSAYADO", doc)
        self.assertIn("no hay datos reales", doc.lower())

    def test_estados_unknown_explicitos(self):
        """NO_VERIFICABLE en matriz hasta recibo productivo, incluso con CI verde."""
        rows = [line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                if line.startswith("| CB-1192 |")]
        self.assertEqual(len(rows), 1)
        cells = [cell.strip() for cell in rows[0].strip("| ").split("|")]
        self.assertEqual(cells[0], "CB-1192")
        self.assertEqual(cells[3], "NO_VERIFICABLE")
        self.assertIn("un fixture sintético no acredita producción", cells[2])
        for status in ("Offline=UNKNOWN", "live=UNKNOWN", "operando=UNKNOWN"):
            self.assertIn(status, cells[4])
        ui = php_scenario("factory_orchestrator_live_ui_scenarios.php", "stale_unknown", as_json=False)
        self.assertIn("central state-unknown", ui)
        self.assertNotIn("central state-current", ui)
        load_script = LOAD_SCRIPT.read_text(encoding="utf-8")
        self.assertIn("NO ENSAYADO · DEL_DUEÑO", load_script)
        self.assertIn("#760", load_script)


if __name__ == "__main__":
    unittest.main()
