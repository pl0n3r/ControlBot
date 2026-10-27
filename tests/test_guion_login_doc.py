"""ControlBot #961: aceptación documental, sin secretos ni conexión real."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "owner-login-acceptance-script.md"
EXIT = ROOT / "docs" / "lista-salida-produccion.md"
ROW_TITLE = "Prueba de autenticación, CSRF, TOTP y accesos restringidos"
CASE_NAMES = {
    "TC-01": "Login owner-only",
    "TC-02": "Segundo factor TOTP",
    "TC-03": "CSRF",
    "TC-04": "Caducidad de sesión",
    "TC-05": "Revocación",
    "TC-06": "Rutas restringidas",
    "TC-07": "Limitación de intentos",
    "TC-08": "Evidencia de identidad",
}


def sections(text: str) -> dict[str, str]:
    matches = list(re.finditer(r"(?m)^### (TC-\d{2}) — (.+)$", text))
    return {
        m.group(1): text[m.end():matches[i + 1].start() if i + 1 < len(matches) else text.find("\n## ", m.end()) if "\n## " in text[m.end():] else len(text)]
        for i, m in enumerate(matches)
    }


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.text = DOC.read_text(encoding="utf-8")
        cls.exit = EXIT.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        self.assertIn("Guion de aceptación owner-only", self.text)
        for heading in (
            "## Fuente y frontera de autoridad",
            "## Precondiciones del ensayo real",
            "## Casos numerados y resultados esperados",
            "## Plantilla de recibo seguro (no ejecutado)",
            "## Gate de salida y trazabilidad",
        ):
            self.assertEqual(self.text.count(heading), 1, heading)
        cases = sections(self.text)
        self.assertEqual(set(cases), set(CASE_NAMES))
        for key, name in CASE_NAMES.items():
            self.assertIn(name, self.text)
            self.assertIn("**Fuente:**", cases[key])
            self.assertIn("**Resultado esperado (PROPUESTA):**", cases[key])

    def test_sin_datos_sensibles(self) -> None:
        forbidden = (
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
            r"gh[pousr]_[A-Za-z0-9]{15,}",
            r"AKIA[0-9A-Z]{16}",
            r"(?i)\bbearer\s+[A-Za-z0-9._-]{12,}",
            r"(?i)\b(?:password|secret|token|cookie|otp|totp)\s*[:=]\s*['\\\"]?[^\s'\"]{8,}",
            r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
        )
        for expression in forbidden:
            with self.subTest(expression=expression):
                self.assertIsNone(re.search(expression, self.text))
        self.assertNotIn("-----BEGIN", self.text)

    def test_estado_honesto(self) -> None:
        self.assertIn("**Estado global: NO ENSAYADO**", self.text)
        self.assertIn("**UNKNOWN:** si el login", self.text)
        self.assertIn("NO-GO", self.text)
        self.assertIn("DEL_DUEÑO", self.text)
        self.assertNotIn("Estado: VALIDADO", self.text)
        for number, segment in sections(self.text).items():
            with self.subTest(case=number):
                self.assertIn("**Estado: NO ENSAYADO.**", segment)
                self.assertIn("**Resultado esperado (PROPUESTA):**", segment)
        self.assertIn("**Estado de ejecución:** `NO ENSAYADO`", self.text)
        self.assertIn("**Resultado observado:** `NO ENSAYADO` / `UNKNOWN`", self.text)

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertIn("`docs/lista-salida-produccion.md`", self.text)
        self.assertIn(ROW_TITLE, self.text)
        matches = [line for line in self.exit.splitlines() if line.startswith("| ") and ROW_TITLE in line]
        self.assertEqual(len(matches), 1)
        self.assertRegex(matches[0], r"\|\s*DEL_DUEÑO\s*\|")
        self.assertIn("docs/lista-salida-produccion.md", self.text)
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/625", self.text)


if __name__ == "__main__":
    unittest.main()
