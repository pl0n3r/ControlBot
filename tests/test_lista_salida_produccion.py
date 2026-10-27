"""Aceptación ejecutable y fail-closed de lista de salida #941."""
from __future__ import annotations

import re
import unittest
from collections import Counter
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "lista-salida-produccion.md"
ESTADOS = ("LISTO", "EN_CURSO", "SIN_COBERTURA", "DEL_DUEÑO", "NO_APLICA")
AREAS = {
    "Respaldos y restauración", "Monitoreo y alertas", "Dominios, DNS y TLS",
    "Secretos y accesos", "Costos y límites", "Privacidad y legal",
    "Soporte y operación diaria", "Migración de datos", "Reversión y apagado",
    "Seguridad, revisión y pruebas", "Accesibilidad y rendimiento",
    "Smoke de producción", "Autenticación del propietario",
    "Transporte GitHub", "Transporte Hostinger", "Persistencia y sesión",
    "Monitoreo del propio ControlBot", "Recuperación de desastres",
    "Auditoría de salida",
}
ISSUE = re.compile(
    r"^\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)$"
)
EPICS_SIN_HOJA = {1, 45, 55, 182, 625, 750, 751}


def parse_rows(text: str) -> list[tuple[str, str, str, str, str]]:
    if text.count("## Matriz") != 1 or text.count("## Resumen final") != 1:
        raise ValueError("Matriz y resumen únicos son obligatorios")
    content = text.split("## Matriz", 1)[1].split("## Resumen final", 1)[0]
    rows = []
    seen = set()
    header = "| Área | Requisito | Estado | Evidencia | Issue |"
    if header not in content:
        raise ValueError("Cabecera ausente")
    for line in content.splitlines():
        if not line.startswith("|"):
            continue
        cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
        if line == header or all(set(c) <= {"-", ":", " "} for c in cells):
            continue
        if len(cells) != 5 or any(not cell for cell in cells):
            raise ValueError("Fila incompleta")
        if cells[2] not in ESTADOS:
            raise ValueError("Estado no permitido")
        issue = ISSUE.fullmatch(cells[4])
        if not issue:
            raise ValueError("Issue sin URL exacta")
        id_num = int(issue.group("id"))
        if cells[2] == "SIN_COBERTURA":
            if id_num in EPICS_SIN_HOJA or "Hoja ejecutable" not in cells[3]:
                raise ValueError("SIN_COBERTURA requiere hoja canónica ejecutable")
        if cells[2] == "EN_CURSO":
            if id_num in EPICS_SIN_HOJA or "Hoja ejecutable" not in cells[3]:
                raise ValueError("EN_CURSO requiere hoja ejecutable, no épico")
        if cells[2] == "DEL_DUEÑO":
            if not any(s in cells[3].lower() for s in
                       ("owner", "operador", "cuenta", "consentimiento")):
                raise ValueError("DEL_DUEÑO debe explicar autoridad faltante")
        if cells[2] == "LISTO" and not any(s in cells[3] for s in
                                             ("VALIDATED_IN_PRODUCTION", "recibo real")):
            raise ValueError("LISTO sin evidencia productiva")
        key = (cells[0], cells[1])
        if key in seen:
            raise ValueError("Requisito duplicado")
        seen.add(key)
        rows.append(tuple(cells))
    if not rows:
        raise ValueError("Matriz vacía")
    return rows


def parse_summary(text: str) -> dict[str, int]:
    section = text.split("## Resumen final", 1)[1]
    result = {}
    for line in section.splitlines():
        m = re.fullmatch(r"\|\s*(LISTO|EN_CURSO|SIN_COBERTURA|DEL_DUEÑO|NO_APLICA|TOTAL)\s*\|\s*(\d+)\s*\|", line.strip())
        if m:
            if m.group(1) in result:
                raise ValueError("Estado de resumen duplicado")
            result[m.group(1)] = int(m.group(2))
    if set(result) != set(ESTADOS) | {"TOTAL"}:
        raise ValueError("Resumen incompleto")
    return result


class ListaSalidaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = parse_rows(cls.text)

    def test_cubre_todo_el_alcance(self):
        self.assertGreaterEqual(len(self.rows), 30)
        self.assertEqual({r[0] for r in self.rows}, AREAS)
        must = ("D-059", "GitHub", "Hostinger", "Basic", "snapshot",
                "rollback", "TLS", "DNS", "consentimiento", "AC-04",
                "VALIDATED_IN_PRODUCTION", "pending=[]", "RPO/RTO", "2FA")
        content = self.text + " ".join(" ".join(r) for r in self.rows)
        for keyword in must:
            with self.subTest(keyword=keyword):
                self.assertIn(keyword.lower(), content.lower())
        self.assertIn("NO-GO", self.text)
        self.assertIn("40454554886", self.text)

    def test_sin_cobertura_tiene_issue(self):
        for row in self.rows:
            if row[2] == "SIN_COBERTURA":
                self.assertIsNotNone(ISSUE.fullmatch(row[4]))
                self.assertIn("Hoja ejecutable", row[3])
        bad = self.text.replace(
            "| Auditoría de salida | Checklist de go-live con test y enlace de evidencias | EN_CURSO | Hoja ejecutable #941",
            "| Auditoría de salida | Checklist de go-live con test y enlace de evidencias | SIN_COBERTURA | Sin hoja #941",
            1,
        )
        with self.assertRaises(ValueError):
            parse_rows(bad)

    def test_formato_y_estados(self):
        self.assertTrue(all(ISSUE.fullmatch(r[4]) for r in self.rows))
        self.assertEqual(len(self.rows), len({(r[0], r[1]) for r in self.rows}))
        bad = self.text.replace("| DEL_DUEÑO |", "| LISTO_POR_CI |", 1)
        with self.assertRaises(ValueError):
            parse_rows(bad)
        bad_link = self.text.replace("/issues/625)", "/issues/0)", 1)
        with self.assertRaises(ValueError):
            parse_rows(bad_link)

    def test_resumen_final(self):
        totals = Counter(r[2] for r in self.rows)
        summary = parse_summary(self.text)
        self.assertEqual(summary["TOTAL"], len(self.rows))
        for s in ESTADOS:
            self.assertEqual(summary[s], totals[s])
        self.assertIn("Hojas nuevas creadas por esta auditoría", self.text)
        wrong = self.text.replace("| TOTAL | " + str(len(self.rows)) + " |",
                                  "| TOTAL | 999 |", 1)
        self.assertNotEqual(parse_summary(wrong)["TOTAL"], len(self.rows))


if __name__ == "__main__":
    unittest.main()
