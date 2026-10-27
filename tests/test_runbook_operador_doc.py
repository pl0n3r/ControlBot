"""Contrato documental #959. Ninguna prueba atesta producción."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "operator-runbook.md"
LISTA = ROOT / "docs" / "lista-salida-produccion.md"
FILA = "Runbook operador de arranque, fallos y mantenimiento"
SECCIONES = (
    "## Autoridad y precondiciones (NO ENSAYADO)",
    "## Arranque (NO ENSAYADO)",
    "## Parada y reversión (NO ENSAYADO)",
    "## Lectura de health (NO ENSAYADO)",
    "## Fallos conocidos y respuesta (NO ENSAYADO)",
    "## Mantenimiento semanal (PROPUESTA · NO ENSAYADO)",
)
PATRONES_SENSIBLES = (
    r"ghp_[A-Za-z0-9]{12,}",
    r"github_pat_[A-Za-z0-9_]{12,}",
    r"sk-[A-Za-z0-9]{12,}",
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
    r"(?i)\b(?:password|passwd|api_key|auth_key)\s*[:=]\s*[^\s<>]{8,}",
    r"(?i)\bauthorization\s*:\s*(?:bearer|basic)\s+\S+",
    r"(?i)\b(?:cookie|set-cookie)\s*:\s*\S+",
    r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
    r"/home/u\d{6,}/",
)


def estado_honesto(texto: str) -> bool:
    esperado = ("NO ENSAYADO", "UNKNOWN", "PROPUESTA", "NO-GO",
                "DEL_DUEÑO", "SYNTHETIC_ONLY", "VALIDATED_IN_PRODUCTION")
    if any(item not in texto for item in esperado):
        return False
    if re.search(r"(?im)^\s*(?:estado|resultado productivo)\s*:\s*(?:ENSAYADO|GO|GREEN|VALIDATED_IN_PRODUCTION)\s*$", texto):
        return False
    return True


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.texto = DOC.read_text(encoding="utf-8")
        cls.lista = LISTA.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        self.assertTrue(DOC.is_file())
        for seccion in SECCIONES:
            with self.subTest(seccion=seccion):
                self.assertEqual(self.texto.count(seccion), 1)
        for termino in ("python3 -m unittest", "gh run list", "Salida esperada",
                        "503", "403", "signal_budget_exceeded", "backup",
                        "rollback", "health", "semanal"):
            with self.subTest(termino=termino):
                self.assertIn(termino.lower(), self.texto.lower())
        self.assertGreaterEqual(self.texto.count("~~~sh"), 4)

    def test_sin_datos_sensibles(self) -> None:
        for patron in PATRONES_SENSIBLES:
            with self.subTest(patron=patron):
                self.assertIsNone(re.search(patron, self.texto))
        for peligro in ("DEPLOY_ENABLED=true", "DOMAIN=https://", "ssh -i ", "curl -u "):
            self.assertNotIn(peligro, self.texto)

    def test_estado_honesto(self) -> None:
        self.assertTrue(estado_honesto(self.texto))
        self.assertFalse(estado_honesto(self.texto.replace("NO ENSAYADO", "ENSAYADO")))
        self.assertFalse(estado_honesto(self.texto + "\nEstado: VALIDATED_IN_PRODUCTION\n"))
        self.assertIn("evidencia externa", self.texto)
        self.assertIn("no acredita", self.texto.lower())

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertIn("](lista-salida-produccion.md)", self.texto)
        self.assertIn(FILA, self.texto)
        rows = [line for line in self.lista.splitlines()
                if line.startswith("|") and f"| {FILA} |" in line]
        self.assertEqual(len(rows), 1)
        cells = [v.strip() for v in rows[0].strip().strip("|").split("|")]
        self.assertGreaterEqual(len(cells), 5)
        self.assertEqual(cells[2], "DEL_DUEÑO")
        self.assertIn("DEL_DUEÑO", self.texto)
        self.assertRegex(self.texto, r"(?i)no (?:se )?(?:altera|cambiar) la fila")


if __name__ == "__main__":
    unittest.main()
