"""AC-01..04 ControlBot #974: contraste de obligaciones CB-0901..1189, fail-closed."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-cb_0901_1189.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = frozenset("""CB-0907 CB-0912 CB-0916 CB-0920 CB-0926 CB-0929 CB-0935 CB-0939 CB-0941 CB-0947 CB-0956 CB-0959 CB-0962 CB-0975 CB-0979 CB-0994 CB-0997 CB-1001 CB-1006 CB-1013 CB-1018 CB-1035 CB-1040 CB-1044 CB-1046 CB-1050 CB-1054 CB-1059 CB-1064 CB-1067 CB-1073 CB-1084 CB-1090 CB-1094 CB-1098 CB-1103 CB-1108 CB-1112 CB-1113 CB-1117 CB-1122 CB-1124 CB-1130 CB-1145 CB-1152 CB-1153 CB-1154 CB-1155 CB-1156 CB-1157 CB-1158 CB-1159 CB-1160 CB-1161 CB-1162 CB-1163 CB-1164 CB-1170 CB-1171 CB-1177 CB-1181 CB-1182 CB-1188 CB-1189""".split())
ALLOWED = {"CUBIERTO", "SIN_COBERTURA", "NO_VERIFICABLE", "DESCARTADO", "NO_APLICA"}
CONTAINERS = {1, 45, 55, 182, 561, 567, 625, 750, 751, 760}


def rows(text: str, heading: str, width: int) -> dict[str, tuple[str, ...]]:
    if text.count(heading) != 1:
        raise AssertionError("Sección única obligatoria")
    region = text.split(heading, 1)[1].split("\n## ", 1)[0]
    records: dict[str, tuple[str, ...]] = {}
    for line in region.splitlines():
        if not re.match(r"^\| CB-\d{4} \|", line):
            continue
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if len(cells) != width:
            raise AssertionError("Ancho incorrecto de fila")
        if cells[0] in records:
            raise AssertionError("Fila duplicada")
        records[cells[0]] = cells
    return records


def claimed_coverage(row: tuple[str, ...]) -> None:
    if row[3] != "CUBIERTO":
        return
    code = re.fullmatch(r"\x60(src/[-A-Za-z0-9_./]+\.php)\x60", row[4])
    scenario = re.fullmatch(r"\x60(tests/[-A-Za-z0-9_./]+\.(?:py|php))\x60", row[5])
    pr = re.search(r"/pull/(\d+)\)", row[6])
    sha = re.search(r"\b[0-9a-f]{40}\b", row[6])
    if not all((code, scenario, pr, sha)):
        raise AssertionError("CUBIERTO sin código, prueba y PR fusionado")
    if not (ROOT / code.group(1)).is_file() or not (ROOT / scenario.group(1)).is_file():
        raise AssertionError("CUBIERTO con prueba inexistente")
    content = (ROOT / scenario.group(1)).read_text(encoding="utf-8")
    if not ("assert" in content or "function" in content):
        raise AssertionError("CUBIERTO sin aserción verificable en prueba")


def require_missing_issue(report: tuple[str, ...], index: tuple[str, ...]) -> None:
    if report[3] != "SIN_COBERTURA":
        return
    m = re.search(r"Hoja ejecutable #(\d+)", report[7])
    if m is None or int(m.group(1)) in CONTAINERS:
        raise AssertionError("SIN_COBERTURA sin hoja canónica ejecutable")
    if f"/issues/{m.group(1)}" not in index[5]:
        raise AssertionError("SIN_COBERTURA sin Issue correspondiente en matriz")


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = DOC.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report = rows(cls.document, "\n## Veredictos\n", 8)
        cls.index = rows(cls.matrix, "\n## Matriz\n", 6)

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(set(self.report), EXPECTED)
        self.assertEqual(len(self.report), 64)
        self.assertEqual(Counter(row[3] for row in self.report.values()),
                         {"NO_VERIFICABLE": 64})
        for rid, row in self.report.items():
            with self.subTest(id=rid):
                self.assertIn(rid, self.index)
                self.assertIn(row[3], ALLOWED)
                self.assertEqual(row[1], self.index[rid][1])
                self.assertEqual(row[2], self.index[rid][2])
                self.assertEqual(row[3], self.index[rid][3])
                self.assertIn("UNKNOWN", row[7])
                self.assertGreater(len(row[7]), 100)
                for candidate in (row[4], row[5]):
                    if candidate != "—":
                        path = candidate.strip("\x60")
                        self.assertTrue((ROOT / path).is_file(), path)
                self.assertIn("contraste-cb_0901_1189.md", self.index[rid][4])
        line = next(x for x in self.document.splitlines()
                    if x.startswith("| CB-0907 |"))
        with self.assertRaisesRegex(AssertionError, "duplicada"):
            rows(self.document.replace(line, line + "\n" + line, 1),
                 "\n## Veredictos\n", 8)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        for rid, row in self.report.items():
            with self.subTest(id=rid):
                claimed_coverage(row)
                if row[3] == "NO_VERIFICABLE":
                    self.assertEqual(row[6], "—")
        false_claim = list(self.report["CB-0907"])
        false_claim[3] = "CUBIERTO"
        with self.assertRaisesRegex(AssertionError, "sin código, prueba y PR"):
            claimed_coverage(tuple(false_claim))

    def test_faltante_tiene_issue(self) -> None:
        for rid, row in self.report.items():
            with self.subTest(id=rid):
                require_missing_issue(row, self.index[rid])
        invalid = list(self.report["CB-0907"])
        invalid[3] = "SIN_COBERTURA"
        with self.assertRaisesRegex(AssertionError, "hoja canónica"):
            require_missing_issue(tuple(invalid), self.index["CB-0907"])

    def test_matriz_actualizada(self) -> None:
        self.assertIn("Contraste CB-0901..1189 #974", self.matrix)
        self.assertIn("docs/contraste-cb_0901_1189.md", self.matrix)
        self.assertIn("Consolidado de 1.194 obligaciones", self.matrix)
        self.assertIn("NO-GO", self.document)
        self.assertEqual(len(self.index), 215)
        self.assertEqual({id for id in self.index if "CB-0901" <= id <= "CB-1189"},
                         EXPECTED)
        for rid in EXPECTED:
            self.assertEqual(self.report[rid][3], self.index[rid][3])
            self.assertIn("offline=UNKNOWN", self.index[rid][4])


if __name__ == "__main__":
    unittest.main()
