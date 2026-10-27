"""ControlBot #964: acceptance test for synthetic signal load documentation."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "signal-budget-load-script.md"
LISTA = ROOT / "docs" / "lista-salida-produccion.md"
ROW = "Cola de más de 400 señales en collector, cron, snapshot y UI"


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self):
        self.assertTrue(DOC.is_file())
        sections = (
            "## Alcance, autoridad y fuentes",
            "## Generación local de 450 señales sintéticas",
            "## Ensayo offline reproducible del contrato existente",
            "## Qué observar: collector, cron, snapshot y UI",
            "## Plantilla de captura minimizada",
            "## Relación con la lista de salida y reversión",
        )
        for heading in sections:
            with self.subTest(heading=heading):
                self.assertEqual(self.text.count(heading), 1)
        for source in (
            "/issues/964", "/issues/760", "/issues/625",
            "tests/test_factory_orchestrator_evidence_collector.py",
            "tests/factory_orchestrator_evidence_collector_scenarios.php",
            "tests/test_factory_orchestrator_snapshot_refresh.py",
        ):
            with self.subTest(source=source):
                self.assertIn(source, self.text)
        for expected in ("range(1, 451)", "len(signals) == 450",
                         '"fixture_only": True', 'rm -f -- "$fixture"'):
            self.assertIn(expected, self.text)

    def test_sin_datos_sensibles(self):
        self.assertNotRegex(self.text, r"(?i)-----BEGIN\s+(?:RSA |EC |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(self.text, r"(?i)\b(?:gh[pousr]_[a-z0-9]{15,}|sk-[a-z0-9]{16,})\b")
        self.assertNotRegex(self.text, r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}")
        self.assertNotRegex(self.text, r"(?i)\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b")
        self.assertNotRegex(self.text, r"(?i)\b(?:password|token|secret|api[_-]?key)\s*[:=]\s*['\"][^'\"]{8,}['\"]")
        self.assertIn("sin credenciales", self.text.lower())
        self.assertIn("no hay datos reales", self.text.lower())

    def test_estado_honesto(self):
        for marker in (
            "PROPUESTA", "UNKNOWN", "NO ENSAYADO", "DEL_DUEÑO", "collector",
            "cron", "snapshot", "UI", "truncated", "omitted",
            "freshness", "stale", "last_success_at", "signal_budget_exceeded",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.text)
        self.assertIn("no son métricas productivas", self.text)
        self.assertIn("no ha recorrido collector", self.text)
        self.assertIn("NO ENSAYADO · DEL_DUEÑO", self.text)
        self.assertNotIn("**Estado: LISTO**", self.text)

    def test_enlaza_fila_de_salida(self):
        self.assertTrue(LISTA.is_file())
        rows = [line for line in LISTA.read_text(encoding="utf-8").splitlines()
                if line.startswith("|") and ROW in line]
        self.assertEqual(len(rows), 1)
        cols = [cell.strip() for cell in rows[0].strip("|").split("|")]
        self.assertEqual(cols[0], "Smoke de producción")
        self.assertEqual(cols[1], ROW)
        self.assertEqual(cols[2], "DEL_DUEÑO")
        self.assertIn("AC-04", cols[3])
        self.assertIn("/issues/760", cols[4])
        self.assertIn("[docs/lista-salida-produccion.md](lista-salida-produccion.md)", self.text)


if __name__ == "__main__":
    unittest.main()
