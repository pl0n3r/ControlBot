"""Aceptación #951: partición 04 normativa con evidencia y conteos fail-closed."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "matriz-cobertura-parte-04.md"
ALLOWED = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}
EXPECTED_SMALL = {290, 291, 293, 294, 295, 298, 299, 300}
SOURCE = re.compile(
    r"^\[(?P<path>docs/[A-Za-z0-9_./-]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<ref>blob/[0-9a-f]{40}/[^)]+|issues/\d+)\)$"
)
ISSUE = re.compile(r"^\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)$")
CODE = re.compile(r"Código [-A-Za-z0-9_./]+; prueba [-A-Za-z0-9_./]+; PR #\d+ MERGED")


def parse_rows(text: str) -> list[tuple[str, ...]]:
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise ValueError("Matriz y resumen obligatorios")
    section = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    rows, seen = [], set()
    for line in section.splitlines():
        if not line.startswith("|") or line.startswith("| ID |") or line.startswith("| --- |"):
            continue
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(cells) != 6 or any(not item for item in cells):
            raise ValueError("Se requieren seis columnas completas")
        rid, source, requirement, status, evidence, issue = cells
        if not re.fullmatch(r"CB-\d{4}", rid) or rid in seen:
            raise ValueError("ID inválido o duplicado")
        seen.add(rid)
        src, linked_issue = SOURCE.fullmatch(source), ISSUE.fullmatch(issue)
        if src is None or linked_issue is None or len(requirement) < 20:
            raise ValueError("Fuente, requisito o issue no verificable")
        if src["path"].startswith("issues/"):
            if src["ref"] != src["path"]:
                raise ValueError("Identidad de issue fuente distinta")
        elif not src["ref"].endswith(f"/{src['path']}#L{src['line']}"):
            raise ValueError("Fuente no apunta a la línea exacta")
        if status not in ALLOWED:
            raise ValueError("Estado desconocido")
        if status == "CUBIERTO" and (not CODE.search(evidence) or "live=UNKNOWN" not in evidence):
            raise ValueError("Cobertura sin código, prueba y PR fusionado")
        if status in {"EN_CURSO", "SIN_COBERTURA"} and "Hoja ejecutable" not in evidence:
            raise ValueError("Un epic no acredita una hoja ejecutable")
        if status == "DESCARTADO" and "razón" not in evidence.lower():
            raise ValueError("Descarte sin autoridad")
        if status == "NO_VERIFICABLE" and "UNKNOWN" not in evidence:
            raise ValueError("La incertidumbre debe declararse")
        rows.append(cells)
    return rows


def parse_summary(text: str) -> dict[str, int]:
    chunk = text.split("\n## Resumen\n", 1)[1]
    vals = re.findall(
        r"^\|\s*(CUBIERTO|EN_CURSO|SIN_COBERTURA|DESCARTADO|NO_VERIFICABLE|TOTAL)\s*\|\s*(\d+)\s*\|$",
        chunk, re.M
    )
    if len(vals) != 6 or len({key for key, _ in vals}) != 6:
        raise ValueError("Resumen ausente o duplicado")
    return {key: int(value) for key, value in vals}


class MatrizParte04Tests(unittest.TestCase):
    def setUp(self):
        self.text = DOC.read_text(encoding="utf-8")

    def test_filas_y_origen(self):
        rows = parse_rows(self.text)
        self.assertEqual(len(rows), 239)
        ids = {int(r[0][3:]) for r in rows}
        self.assertEqual({n for n in ids if n < 301}, EXPECTED_SMALL)
        self.assertEqual(len({n for n in ids if n > 896}), 231)
        self.assertEqual(sum(ids), 242219)
        self.assertIn("104c0155fe636e287698fce3fd4a91b65411ad37", self.text)
        # Rechazar también colisiones con particiones ya integradas en main.
        for name in ("matriz-cobertura.md", "matriz-cobertura-parte-01.md",
                     "matriz-cobertura-parte-02.md", "matriz-cobertura-parte-03.md"):
            path = ROOT / "docs" / name
            if path.exists():
                others = {int(s) for s in re.findall(r"^\|\s*CB-(\d{4})\s*\|", path.read_text(), re.M)}
                self.assertFalse(ids & others, name)
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace("CB-0898", "CB-0899", 1))
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace("https://github.com/pl0n3r/ControlBot/issues/",
                                         "https://github.com/otro/repo/issues/", 1))

    def test_estados_y_conteo(self):
        rows = parse_rows(self.text)
        actual = Counter(row[3] for row in rows)
        self.assertEqual(dict(actual), {"NO_VERIFICABLE": 239})
        found = parse_summary(self.text)
        self.assertEqual(found["TOTAL"], len(rows))
        for state in ALLOWED:
            self.assertEqual(found[state], actual[state])
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace("| NO_VERIFICABLE |", "| CUBIERTO |", 1))
        with self.assertRaises(ValueError):
            parse_summary(self.text.replace("| TOTAL |", "| OTRO |", 1))


if __name__ == "__main__":
    unittest.main()
