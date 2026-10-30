"""Contrato ejecutable #948: partición 01, sin duplicados ni cobertura inventada."""
from collections import Counter
from pathlib import Path
import re
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs" / "matriz-cobertura-parte-01.md"
STATES = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}
SOURCE = re.compile(r"^\[(?P<path>docs/[A-Za-z0-9_./-]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]\(https://github\.com/pl0n3r/ControlBot/(?P<ref>blob/[0-9a-f]{40}/[^)]+|issues/\d+)\)$")
ISSUE = re.compile(r"^\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)$")

def read_rows(text):
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise ValueError("La matriz y el resumen deben ser únicos")
    table = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    lines = [line for line in table.splitlines() if line.startswith("| CB-")]
    rows = [tuple(v.strip() for v in line.strip("|").split("|")) for line in lines]
    if len(rows) != 245:
        raise ValueError("La partición debe contener 245 requisitos")
    seen = set()
    for row in rows:
        if len(row) != 6 or not all(row):
            raise ValueError("Se requieren seis columnas no vacías")
        rid, source, requirement, state, evidence, issue = row
        if not re.fullmatch(r"CB-\d{4}", rid) or rid in seen:
            raise ValueError("ID inexistente o duplicado")
        seen.add(rid)
        src = SOURCE.fullmatch(source)
        if src is None or ISSUE.fullmatch(issue) is None:
            raise ValueError("Fuente e Issue exigen URL canónica")
        if src["path"].startswith("issues/"):
            if src["ref"] != src["path"]:
                raise ValueError("Issue de origen incongruente")
        elif not src["ref"].endswith(f"/{src['path']}#L{src['line']}"):
            raise ValueError("Ruta/línea de fuente incongruente")
        if len(requirement) < 20 or state not in STATES:
            raise ValueError("Requisito o estado incorrecto")
        if state == "CUBIERTO" and not re.search(r"Código [\w./-]+; prueba [\w./-]+; PR #\d+ MERGED;", evidence):
            raise ValueError("CUBIERTO sin prueba y PR")
        if state in {"EN_CURSO", "SIN_COBERTURA"} and "Hoja ejecutable" not in evidence:
            raise ValueError("No se acepta epic como cobertura")
        if state == "DESCARTADO" and "razón" not in evidence.lower():
            raise ValueError("Descartado sin razón")
        if state == "NO_VERIFICABLE" and not all(k in evidence for k in ("Offline=UNKNOWN", "live=UNKNOWN", "operando=UNKNOWN")):
            raise ValueError("Falta evidencia de incertidumbre")
    ids = [int(row[0][3:]) for row in rows]
    if ids != sorted(ids) or ids[0] != 2 or ids[-1] != 289:
        raise ValueError("Secuencia original alterada")
    return rows

def read_totals(text):
    tail = text.split("\n## Resumen\n", 1)[1]
    pairs = re.findall(r"^\| (CUBIERTO|EN_CURSO|SIN_COBERTURA|DESCARTADO|NO_VERIFICABLE|TOTAL) \| (\d+) \|$", tail, re.M)
    if len(pairs) != 6 or len({k for k, _ in pairs}) != 6:
        raise ValueError("Resumen ambiguo")
    return {k: int(v) for k, v in pairs}

class MatrizParte01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = read_rows(cls.text)

    def test_filas_y_origen(self):
        self.assertEqual(len(self.rows), 245)
        self.assertEqual(sum(int(r[0][3:]) for r in self.rows), 35170)
        self.assertEqual(len({r[0] for r in self.rows}), 245)
        self.assertIn("104c0155fe636e287698fce3fd4a91b65411ad37", self.text)
        duplicated = self.text.replace("| CB-0003 |", "| CB-0002 |", 1)
        with self.assertRaises(ValueError):
            read_rows(duplicated)
        broken_source = self.text.replace("docs/aegis-evidence.md#L9", "docs/incorrecto.md#L9", 1)
        with self.assertRaises(ValueError):
            read_rows(broken_source)

    def test_estados_y_conteo(self):
        totals = read_totals(self.text)
        counts = Counter(r[3] for r in self.rows)
        self.assertEqual(totals["TOTAL"], 245)
        for state in STATES:
            self.assertEqual(totals[state], counts[state])
        self.assertEqual(counts, {"CUBIERTO": 20, "NO_VERIFICABLE": 225})
        with self.assertRaises(ValueError):
            read_rows(self.text.replace("| NO_VERIFICABLE |", "| CUBIERTO |", 1))
        with self.assertRaises(ValueError):
            read_totals(self.text.replace("| TOTAL | 245 |", "| TOTAL | 244 |", 1).replace("| TOTAL | 244 |", "| TOTAL_FAKE | 244 |", 1))

if __name__ == "__main__":
    unittest.main()
