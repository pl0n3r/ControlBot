"""AC-01/02 #949: filas trazables 02/04, sin coberturas ficticias."""
from collections import Counter
from pathlib import Path
import re
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs/matriz-cobertura-parte-02.md"
STATES = ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE")
SOURCE = re.compile(
    r"\[(?P<path>docs/[-\w./]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<target>blob/[0-9a-f]{40}/[-\w./]+#L\d+|issues/\d+)\)"
)
ISSUE = re.compile(r"\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)")


def validate_parent_stage(part_ids, parent_ids):
    """Prohíbe solapamientos parciales; exige cobertura total tras integración."""
    part_ids, parent_ids = set(part_ids), set(parent_ids)
    if len(parent_ids) == 215:
        if part_ids & parent_ids:
            raise ValueError("Partición duplicada con el corte parcial #940")
        return "partial"
    if len(parent_ids) == 1194:
        expected = {f"CB-{n:04d}" for n in range(1, 1195)}
        if parent_ids != expected or not part_ids <= parent_ids:
            raise ValueError("Integración global incompleta o con IDs ajenos")
        return "integrated"
    raise ValueError("Etapa de matriz padre desconocida: fail-closed")


def read_rows(text):
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise ValueError("Matriz y resumen únicos requeridos")
    table = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    rows = [tuple(c.strip() for c in ln.strip("|").split("|"))
            for ln in table.splitlines() if ln.startswith("| CB-")]
    if len(rows) != 245 or any(len(r) != 6 or not all(r) for r in rows):
        raise ValueError("Se esperan 245 filas de seis columnas")
    ids = [r[0] for r in rows]
    if len(ids) != len(set(ids)):
        raise ValueError("ID duplicado")
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


class MatrizParte02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = read_rows(cls.text)

    def test_filas_y_origen(self):
        self.assertIn("104c0155fe636e287698fce3fd4a91b65411ad37", self.text)
        ids = [r[0] for r in self.rows]
        self.assertEqual(len(ids), 245)
        self.assertEqual(ids, sorted(ids))
        self.assertEqual(sum(int(r[3:]) for r in ids), 110403)
        parent = (DOC.parent / "matriz-cobertura.md").read_text(encoding="utf-8")
        parent_ids = set(re.findall(r"^\| (CB-\d{4}) \|", parent, re.M))
        self.assertIn("CB-0001", parent_ids)
        self.assertIn(validate_parent_stage(ids, parent_ids), ("partial", "integrated"))
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

    def test_padre_parcial_y_final_sin_falsos_positivos(self):
        own = {r[0] for r in self.rows}
        partial = {f"CB-{n:04d}" for n in range(1, 216)}
        integrated = {f"CB-{n:04d}" for n in range(1, 1195)}
        self.assertEqual(validate_parent_stage(own, partial), "partial")
        self.assertEqual(validate_parent_stage(own, integrated), "integrated")
        with self.assertRaises(ValueError):
            validate_parent_stage(own, (partial - {"CB-0002"}) | {"CB-0301"})
        with self.assertRaises(ValueError):
            validate_parent_stage(own, integrated - {"CB-1194"})
        with self.assertRaises(ValueError):
            validate_parent_stage(own, integrated | {"CB-1195"})

    def test_estados_y_conteo(self):
        counts = Counter(r[3] for r in self.rows)
        # #1255 promovera filas auditadas sin cambiar los 245 IDs originales.
        self.assertEqual(sum(counts.values()), 245)
        for state in STATES:
            self.assertIn(f"| {state} | {counts[state]} |", self.text)
        self.assertIn("| TOTAL | 245 |", self.text)
        with self.assertRaises(ValueError):
            read_rows(self.text.replace("| NO_VERIFICABLE |", "| CUBIERTO |", 1))
        with self.assertRaises(ValueError):
            read_rows(self.text.replace("| CB-0301 |", "| CB-0301 | EXTRA |", 1))


if __name__ == "__main__":
    unittest.main()
