"""Contrato de documentación estrictamente offline para el plan DR de #960."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLAN = ROOT / "docs" / "dr-drill-plan.md"
EXIT = ROOT / "docs" / "lista-salida-produccion.md"
REQUIREMENT = "RPO/RTO medidos y simulacro de recuperación independiente"


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.plan = PLAN.read_text(encoding="utf-8")
        cls.exit = EXIT.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self):
        self.assertTrue(PLAN.is_file())
        required_sections = (
            "## Fuentes, alcance y autoridad",
            "## Objetivos RPO/RTO (PROPUESTA)",
            "## Roles y responsabilidades",
            "## Condiciones de entrada y parada segura",
            "## Procedimiento paso a paso (NO EJECUTADO)",
            "## Hoja de resultados (plantilla vacía; NO ENSAYADO)",
            "## Criterios de aprobado (PROPUESTA; NO APLICADOS)",
            "## Reversión de esta entrega documental",
        )
        for title in required_sections:
            with self.subTest(section=title):
                self.assertEqual(self.plan.count(title), 1)
        for term in ("RPO", "RTO", "OWNER", "Operador", "QA", "checksum",
                     "UTC", "rollback", "medición", "APROBADO", "NO INICIAR"):
            with self.subTest(term=term):
                self.assertIn(term.casefold(), self.plan.casefold())
        self.assertGreaterEqual(len(re.findall(r"^\d+\. \*\*", self.plan, re.M)), 7)

    def test_sin_datos_sensibles(self):
        forbidden = (
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
            r"\b(?:ghp|gho|ghu|ghs|ghr)_[a-zA-Z0-9]{20,}\b",
            r"\bgithub_pat_[a-zA-Z0-9_]{20,}\b",
            r"\bsk-[a-zA-Z0-9]{20,}\b",
            r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
            r"(?im)^\s*(?:password|api_key|secret|token)\s*[:=]\s*\S+",
        )
        for pattern in forbidden:
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, self.plan))
        self.assertNotIn("-----BEGIN CERTIFICATE-----", self.plan)
        self.assertNotIn("https://private-user-images", self.plan)

    def test_estado_honesto(self):
        for marker in ("Estado del simulacro: NO ENSAYADO", "Resultado actual: UNKNOWN",
                       "PROPUESTA", "NO ENSAYADO", "NO EJECUTADO", "DEL_DUEÑO",
                       "no se ha ejecutado operación alguna"):
            with self.subTest(marker=marker):
                self.assertIn(marker.casefold(), self.plan.casefold())
        for bad in ("Simulacro ejecutado con éxito", "RPO medido: 15", "RTO medido: 60",
                    "Estado del simulacro: APROBADO", "Resultado actual: VALIDATED_IN_PRODUCTION"):
            self.assertNotIn(bad.casefold(), self.plan.casefold())
        self.assertIn("RPO medido (minutos) | UNKNOWN", self.plan)
        self.assertIn("RTO medido (minutos) | UNKNOWN", self.plan)
        self.assertIn("Veredicto final | NO ENSAYADO", self.plan)

    def test_enlaza_fila_de_salida(self):
        self.assertIn("[Lista de salida](lista-salida-produccion.md)", self.plan)
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/182", self.plan)
        self.assertIn(REQUIREMENT, self.plan)
        row = next((line for line in self.exit.splitlines()
                    if line.startswith("| Recuperación de desastres | " + REQUIREMENT + " |")), None)
        self.assertIsNotNone(row)
        self.assertIn("| DEL_DUEÑO |", row)
        self.assertIn("| DEL_DUEÑO", self.plan)
        self.assertNotIn("| LISTO |", self.plan)


if __name__ == "__main__":
    unittest.main()
