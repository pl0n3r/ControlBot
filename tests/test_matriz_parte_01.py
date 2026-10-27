"""AC-01/02 #948: filas trazables 01/04, sin coberturas ficticias."""
from collections import Counter
from pathlib import Path
import re
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs/matriz-cobertura-parte-01.md"
STATES = ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE")
SOURCE = re.compile(
    r"\[(?P<path>docs/[-\w./]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<target>blob/[0-9a-f]{40}/[-\w./]+#L\d+|issues/\d+)\)"
)
ISSUE = re.compile(r"\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)")


def read_rows(text):
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise ValueError("Matriz y resumen únicos requeridos")
    table = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    rows = [tuple(c.strip() for c in ln.strip("|").split("|"))
            for ln in table.splitlines() if ln.startswith("| CB-")]
    if len(rows) != 300 or any(len(r) != 6 or not all(r) for r in rows):
        raise ValueError("Se esperan 300 filas de seis columnas")
    for rid, src, req, state, ev, issue in rows:
        if state not in STATES:
            raise ValueError("Estado inválido")
        if state == "CUBIERTO" and not re.search(r"Código .+; prueba .+; PR #\d+ MERGED", ev):
            raise ValueError("Cobertura sin prueba y PR fusionado")
        if state in ("EN_CURSO", "SIN_COBERTURA") and "Hoja ejecutable" not in ev:
            raise ValueError("Trabajo sin hoja ejecutable")
        if state == "NO_VERIFICABLE" and "Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN" not in ev:
            raise ValueError("Evidencia no verificable alterada")
    return rows


class MatrizParte01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = read_rows(cls.text)

    def test_filas_y_origen(self):
        self.assertIn("104c0155fe636e287698fce3fd4a91b65411ad37", self.text)
        self.assertEqual([r[0] for r in self.rows],
                         [f"CB-{n:04d}" for n in range(1, 301)])
        for rid, src, req, state, ev, issue in self.rows:
            with self.subTest(id=rid):
                m = SOURCE.fullmatch(src)
                self.assertIsNotNone(m)
                self.assertIsNotNone(ISSUE.fullmatch(issue))
                self.assertGreaterEqual(len(req), 20)
                if m is not None:
                    if m["path"].startswith("issues/"):
                        self.assertEqual(m["target"], m["path"])
                    else:
                        self.assertTrue(m["target"].endswith(f"/{m['path']}#L{m['line']}"))

    def test_estados_y_conteo(self):
        counts = Counter(r[3] for r in self.rows)
        self.assertEqual(counts, {"NO_VERIFICABLE": 300})
        for state in STATES:
            self.assertIn(f"| {state} | {counts[state]} |", self.text)
        self.assertIn("| TOTAL | 300 |", self.text)
        with self.assertRaises(ValueError):
            read_rows(self.text.replace("| NO_VERIFICABLE |", "| CUBIERTO |", 1))
        with self.assertRaises(ValueError):
            read_rows(self.text.replace("| CB-0001 |", "| CB-0001 | EXTRA |", 1))


if __name__ == "__main__":
    unittest.main()
