"""AC-01/02 de #950: integridad de la partición documental 03/04."""
from collections import Counter
from pathlib import Path
import re
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs/matriz-cobertura-parte-03.md"
STATES = ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE")
SOURCE = re.compile(
    r"\[(?P<path>docs/[-\w./]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<target>blob/[0-9a-f]{40}/[-\w./]+#L\d+|issues/\d+)\)"
)
# Exclusiones autenticadas en el corte inicial de #940 (main@cf54959d).
# Este registro es estable aunque más tarde el integrador incluya todas las partes.
IN_MAIN_CUT = {int(n) for n in (
    "604 609 624 635 640 646 653 659 665 673 683 692 698 709 717 722 725 729 731 737 741 744 756 760 767 770 777 780 783 791 794 809 814 820 835 850 855 862 871 877 881 885 887 889 890 891 892 894"
).split()}

ISSUE = re.compile(r"\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)")


def load_rows():
    text = DOC.read_text(encoding="utf-8")
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise AssertionError("Secciones duplicadas o ausentes")
    section = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    lines = [line for line in section.splitlines() if line.startswith("| CB-")]
    rows = [tuple(cell.strip() for cell in line.strip("|").split("|")) for line in lines]
    if any(len(row) != 6 for row in rows):
        raise AssertionError("Fila de matriz incompleta")
    return text, rows


class MatrizParte03Tests(unittest.TestCase):
    def test_filas_y_origen(self):
        _, rows = load_rows()
        self.assertEqual([r[0] for r in rows],
                         [f"CB-{n:04d}" for n in range(599, 897) if n not in IN_MAIN_CUT])
        self.assertEqual(len(IN_MAIN_CUT), 48)
        for rid, source, requirement, status, evidence, issue in rows:
            with self.subTest(id=rid):
                src = SOURCE.fullmatch(source)
                self.assertIsNotNone(src)
                self.assertIsNotNone(ISSUE.fullmatch(issue))
                self.assertGreaterEqual(len(requirement), 20)
                if src is not None:
                    if src["path"].startswith("issues/"):
                        self.assertEqual(src["target"], src["path"])
                    else:
                        self.assertTrue(src["target"].endswith(
                            f"/{src['path']}#L{src['line']}"))
                self.assertTrue(evidence)

    def test_estados_y_conteo(self):
        text, rows = load_rows()
        counts = Counter(row[3] for row in rows)
        self.assertEqual(len(rows), 250)
        self.assertEqual(set(counts) - set(STATES), set())
        for state in STATES:
            self.assertIn(f"| {state} | {counts[state]} |", text)
        self.assertIn("| TOTAL | 250 |", text)
        # No congelar el estado de las filas cuando haya evidencia probada.
        self.assertEqual(sum(counts.values()), 250)
        for rid, source, requirement, state, evidence, issue in rows:
            with self.subTest(id=rid):
                if state == "CUBIERTO":
                    self.assertRegex(evidence, r"Código .+; prueba .+; PR #\d+ MERGED")
                elif state in ("EN_CURSO", "SIN_COBERTURA"):
                    self.assertIn("Hoja ejecutable", evidence)
                    self.assertNotIn("issues/1)", issue)
                elif state == "NO_VERIFICABLE":
                    self.assertIn("Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN", evidence)
