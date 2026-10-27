"""ControlBot #971: contraste conservador de cláusulas CB-0001..0300 sin inferir live."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "contraste-cb_0001_0300.md"
MATRIX = ROOT / "docs" / "matriz-cobertura.md"
EXPECTED = frozenset("CB-0001 CB-0011 CB-0017 CB-0031 CB-0039 CB-0042 CB-0046 CB-0050 CB-0058 CB-0070 CB-0080 CB-0087 CB-0093 CB-0099 CB-0105 CB-0111 CB-0117 CB-0124 CB-0127 CB-0130 CB-0134 CB-0141 CB-0167 CB-0174 CB-0184 CB-0189 CB-0194 CB-0199 CB-0204 CB-0208 CB-0212 CB-0215 CB-0221 CB-0232 CB-0239 CB-0244 CB-0248 CB-0254 CB-0260 CB-0266 CB-0271 CB-0274 CB-0279 CB-0288 CB-0292 CB-0296 CB-0297".split())
HEAD = "\n## "
CONTAINERS = {1, 45, 55, 182, 561, 567, 625, 750, 751, 760}


def rows(text: str, heading: str, width: int) -> dict[str, tuple[str, ...]]:
    if text.count(heading) != 1:
        raise AssertionError("Sección única obligatoria")
    section = text.split(heading, 1)[1].split(HEAD, 1)[0]
    result: dict[str, tuple[str, ...]] = {}
    for line in section.splitlines():
        if not re.match(r"^\| CB-\d{4} \|", line):
            continue
        cells = tuple(c.strip() for c in line.strip("|").split("|"))
        if len(cells) != width or cells[0] in result:
            raise AssertionError("Fila incompleta o duplicada")
        result[cells[0]] = cells
    return result


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = DOC.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report_rows = rows(cls.document, "\n## Veredictos\n", 8)
        cls.index = rows(cls.matrix, "\n## Matriz\n", 6)

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(set(self.report_rows), EXPECTED)
        self.assertEqual(len(self.report_rows), 47)
        for rid, r in self.report_rows.items():
            with self.subTest(rid=rid):
                self.assertIn(rid, self.index)
                self.assertEqual(r[1], self.index[rid][1])
                self.assertEqual(r[2], self.index[rid][2])
                self.assertEqual(r[3], self.index[rid][3])
                self.assertEqual(r[3], "CUBIERTO" if rid == "CB-0058" else "NO_VERIFICABLE")
                self.assertTrue(r[1].startswith("[docs/"))
                self.assertIn("live=UNKNOWN; operando=UNKNOWN", r[7])
                self.assertIn("offline=VERIFICADO" if rid == "CB-0058" else "offline=UNKNOWN", r[7])
                self.assertGreater(len(r[7]), 95)
                for candidate, prefix in ((r[4], "src/"), (r[5], "tests/")):
                    if candidate == "—":
                        continue
                    self.assertTrue(candidate.startswith("`" + prefix))
                    self.assertTrue((ROOT / candidate.strip("`")).is_file())
        # Evita contrastes que cortan las cláusulas antes del nombre o salida críticos.
        for rid, expected in {
            "CB-0058": "tests/recovery_evidence_scenarios.php",
            "CB-0117": "CustomerSuccessCore",
            "CB-0271": "bindings con `ExternalApiSession`",
            "CB-0296": "body nunca entra al resultado",
        }.items():
            self.assertIn(expected, self.report_rows[rid][2])
            self.assertEqual(self.report_rows[rid][2], self.index[rid][2])
        first = next(line for line in self.document.splitlines() if line.startswith("| CB-0001 |"))
        with self.assertRaisesRegex(AssertionError, "duplicada"):
            rows(self.document.replace(first, first + "\n" + first, 1), "\n## Veredictos\n", 8)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        # La única promoción offline comprobada usa aserción concreta y PR ya fusionado.
        covered = {rid: row for rid, row in self.report_rows.items() if row[3] == "CUBIERTO"}
        self.assertEqual(set(covered), {"CB-0058"})
        row = covered["CB-0058"]
        self.assertEqual(row[4], "`src/BackupReceipt.php`")
        self.assertEqual(row[5], "`tests/test_backup_integrity.py`")
        self.assertEqual(row[6], "[PR #899](https://github.com/pl0n3r/ControlBot/pull/899)")
        self.assertTrue((ROOT / row[4].strip("`")).is_file())
        test_path = ROOT / row[5].strip("`")
        self.assertTrue(test_path.is_file())
        source = test_path.read_text(encoding="utf-8")
        self.assertIn("def test_sha256_digest_shape_fail_closed(", source)
        self.assertIn("for invalid in (\"short\", \"non_hex\", \"long\"):", source)
        self.assertIn("def test_no_payload_leak_or_provider_io(", source)
        self.assertIn("PR #899 MERGED;", self.index["CB-0058"][4])
        self.assertIn("Código src/BackupReceipt.php; prueba tests/test_backup_integrity.py;", self.index["CB-0058"][4])
        for rid, item in self.report_rows.items():
            if rid != "CB-0058":
                self.assertEqual(item[3], "NO_VERIFICABLE")
                self.assertEqual(item[6], "—")
                self.assertIn("UNKNOWN", self.index[rid][4])

    def test_faltante_tiene_issue(self) -> None:
        for rid, row in self.report_rows.items():
            if row[3] == "SIN_COBERTURA":
                match = re.search(r"Hoja ejecutable #(\d+)", row[7])
                self.assertIsNotNone(match, rid)
                self.assertNotIn(int(match[1]), CONTAINERS)
                self.assertIn("Hoja ejecutable", self.index[rid][4])
        forged = list(self.report_rows["CB-0001"])
        forged[3] = "SIN_COBERTURA"
        self.assertIsNone(re.search(r"Hoja ejecutable #(\d+)", forged[7]))

    def test_matriz_actualizada(self) -> None:
        self.assertEqual(len(self.index), 215)
        self.assertEqual({r for r in self.index if "CB-0001" <= r <= "CB-0300"}, EXPECTED)
        self.assertIn("## Contraste CB-0001..0300 #971", self.matrix)
        self.assertIn("[reporte #971](contraste-cb_0001_0300.md)", self.matrix)
        self.assertIn("Consolidado de 1.194 obligaciones", self.matrix)
        self.assertIn("NO-GO", self.matrix)
        for rid, row in self.report_rows.items():
            self.assertEqual(row[3], self.index[rid][3])
            self.assertIn("Contraste: [docs/contraste-cb_0001_0300.md]", self.index[rid][4])
        self.assertIn("docs/contraste-cb_0001_0300.md", self.matrix)
        self.assertIn("tests/test_contraste_cb_0001_0300.py", self.matrix)


if __name__ == "__main__":
    unittest.main()
