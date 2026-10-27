"""Aceptación #962: un plan documental no acredita el apagado real."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "emergency-shutdown-plan.md"
CHECKLIST = ROOT / "docs" / "lista-salida-produccion.md"
TARGET = "Apagado de emergencia del runtime y revocación de accesos"
SECTIONS = (
    "Alcance, autoridad y fuentes",
    "Condiciones previas y disparadores",
    "Secuencia propuesta para detener el servicio",
    "Revocación de tokens, grants y sesiones",
    "Verificación de apagado y criterios de rechazo",
    "Tiempos objetivo",
    "Plantilla de recibo saneado",
    "Enlace a la lista de salida y deuda pendiente",
)


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.checklist = CHECKLIST.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self):
        self.assertTrue(DOC.is_file())
        for title in SECTIONS:
            with self.subTest(title=title):
                self.assertEqual(
                    len(re.findall(r"^## " + re.escape(title) + r"(?:\s|$)", self.text, re.M)), 1
                )
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/962", self.text)
        self.assertIn("cron", self.text)
        self.assertIn("T+15 min", self.text)
        self.assertIn("Estado del recibo: NO ENSAYADO", self.text)

    def test_sin_datos_sensibles(self):
        for pattern in (
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
            r"(?i)\bgh[pousr]_[a-z0-9]{20,}\b",
            r"(?i)\bgithub_pat_[a-z0-9_]{20,}\b",
            r"(?i)\b(?:password|secret|api[_-]?key|access[_-]?token)\s*[:=]\s*['\"]?[^\s'\"]{12,}",
            r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}",
            r"\b3\d{9}\b",
        ):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, self.text))
        self.assertIn("No se obtienen ni muestran credenciales", self.text)
        self.assertIn("no imprimir", self.text)

    def test_estado_honesto(self):
        self.assertIn("**Estado: NO ENSAYADO", self.text)
        self.assertIn("**UNKNOWN", self.text)
        self.assertIn("**PROPUESTA", self.text)
        self.assertIn("**NO-GO**", self.text)
        self.assertIn("Factory #767", self.text)
        self.assertIn("**no** apaga PHP/cron", self.text)
        self.assertIn("solo revoca grants sintéticos en memoria", self.text)
        self.assertIn("no son mediciones reales", self.text)
        self.assertIn("Estado del recibo: NO ENSAYADO", self.text)
        self.assertNotRegex(self.text, r"(?m)^\s*(?:Estado|Resultado)\s*:\s*(?:LISTO|VALIDATED_IN_PRODUCTION|VERDE)\s*$")

    def test_enlaza_fila_de_salida(self):
        rows = [line for line in self.checklist.splitlines()
                if line.startswith("|") and TARGET in line]
        self.assertEqual(len(rows), 1)
        cols = [c.strip() for c in rows[0].strip("|").split("|")]
        self.assertEqual(cols[0], "Reversión y apagado")
        self.assertEqual(cols[1], TARGET)
        self.assertEqual(cols[2], "DEL_DUEÑO")
        self.assertIn("[docs/lista-salida-produccion.md](lista-salida-produccion.md)", self.text)
        self.assertIn(TARGET, self.text)
        self.assertIn("estado **DEL_DUEÑO**", self.text)
        self.assertIn("no cambia esa fila", self.text)


if __name__ == "__main__":
    unittest.main()
