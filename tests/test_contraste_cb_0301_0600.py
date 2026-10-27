"""ControlBot #972: contrato auditable de las 53 filas del índice CB-0301..0600.
Es un contraste offline de evidencia histórica, no una prueba de servicios reales.
"""
from __future__ import annotations
from collections import Counter
from pathlib import Path
import re
import unittest
ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/contraste-cb_0301_0600.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = frozenset({
    "CB-0305",
    "CB-0310",
    "CB-0319",
    "CB-0323",
    "CB-0329",
    "CB-0336",
    "CB-0344",
    "CB-0350",
    "CB-0358",
    "CB-0363",
    "CB-0366",
    "CB-0367",
    "CB-0371",
    "CB-0375",
    "CB-0377",
    "CB-0382",
    "CB-0386",
    "CB-0392",
    "CB-0397",
    "CB-0403",
    "CB-0406",
    "CB-0411",
    "CB-0415",
    "CB-0427",
    "CB-0432",
    "CB-0437",
    "CB-0443",
    "CB-0450",
    "CB-0452",
    "CB-0454",
    "CB-0459",
    "CB-0468",
    "CB-0472",
    "CB-0476",
    "CB-0482",
    "CB-0486",
    "CB-0489",
    "CB-0490",
    "CB-0499",
    "CB-0502",
    "CB-0506",
    "CB-0514",
    "CB-0532",
    "CB-0539",
    "CB-0545",
    "CB-0549",
    "CB-0560",
    "CB-0573",
    "CB-0577",
    "CB-0580",
    "CB-0587",
    "CB-0588",
    "CB-0595",
})
COVERED = {
    "CB-0305": ("src/FactoryAgentActivity.php", "tests/factory_agent_activity_scenarios.php", 756, "b193fe724ff2356e59b304b22038971cc166026c"),
    "CB-0310": ("src/FactoryOrchestratorLiveUi.php", "tests/factory_blockers_panel_scenarios.php", 784, "076041ae28e9b37fff187b500f57c69e12b7700b"),
    "CB-0545": ("src/HostingerPublicApi.php", "tests/test_hostinger_public_api.py", 711, "9b7ab6a18b948b8be5ae53e9efa86afd23c0dcf1"),
    "CB-0367": ("src/GitHubHttpTransport.php", "tests/github_http_transport_scenarios.php", 768, "ff810b8da2c3ea60f67ea304947d0d6c80620da9"),
    "CB-0486": ("src/GlobalSearchCore.php", "tests/global_search_scenarios.php", 346, "af2eb3da018bf4dee6e61d80ea1045d7818bdca0"),
    "CB-0489": ("src/GlobalSearchIndex.php", "tests/global_search_index_scenarios.php", 371, "24863694ec8fe12190978fb7830bd54110d29a05"),
    "CB-0499": ("src/GuardrailInfrastructureClassification.php", "tests/guardrail_infrastructure_classification_scenarios.php", 486, "e86968c58312c3ee8960d90b9d481b84265f020a"),
    "CB-0577": ("src/IncidentLesson.php", "tests/incident_lesson_scenarios.php", 364, "846f26bc58b594cfac10751c696df76a648451aa"),
    "CB-0580": ("src/IncidentTimeline.php", "tests/incident_postmortem_scenarios.php", 344, "3b5e70b577ecca053f9bc6eecc43f00769ced172"),
    "CB-0587": ("src/IncidentUi.php", "tests/incident_ui_scenarios.php", 378, "ace664f9d9ced8ee56b56dc6aceeff112a9abeb2"),
}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "NO_APLICA", "DESCARTADO", "SIN_COBERTURA"}
def parse_rows(text: str) -> dict[str, tuple[str, ...]]:
    if text.count("\n## Veredictos\n") != 1:
        raise AssertionError("Tabla de contraste única y obligatoria")
    section = text.split("\n## Veredictos\n", 1)[1].split("\n## Balance verificable", 1)[0]
    result: dict[str, tuple[str, ...]] = {}
    for line in section.splitlines():
        if not re.match(r"^\| CB-\d{4} \|", line):
            continue
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if len(cells) != 8:
            raise AssertionError("Fila de contraste sin ocho columnas")
        rid = cells[0]
        if rid in result:
            raise AssertionError("Obligación duplicada")
        if cells[3] not in STATES or not cells[2] or not cells[7]:
            raise AssertionError("Veredicto/razón inválidos")
        result[rid] = cells
    return result
def index_rows(text: str) -> dict[str, tuple[str, ...]]:
    matrix: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        if not re.match(r"^\| CB-\d{4} \|", line):
            continue
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if len(cells) != 6:
            raise AssertionError("Índice sin seis columnas")
        if cells[0] in matrix:
            raise AssertionError("ID de matriz duplicado")
        matrix[cells[0]] = cells
    return matrix
def require_missing_issue(row: tuple[str, ...], index: tuple[str, ...]) -> None:
    if row[3] != "SIN_COBERTURA":
        return
    if not re.search(r"Hoja ejecutable #\d+", row[7]):
        raise AssertionError("SIN_COBERTURA sin hoja canónica")
    if not re.search(r"Hoja ejecutable", index[4]):
        raise AssertionError("Matriz carece de hoja ejecutable")
    if re.search(r"/issues/(?:1|45|55|182|561|567|625|750|751|760)\)", index[5]):
        raise AssertionError("Un épico/roadmap no es una hoja ejecutable")
class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = parse_rows(cls.report)
        cls.index = index_rows(cls.matrix)
    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(set(self.rows), EXPECTED)
        self.assertEqual(len(self.rows), 53)
        self.assertEqual(Counter(row[3] for row in self.rows.values()),
                         {"CUBIERTO": 10, "NO_VERIFICABLE": 43})
        for rid, row in self.rows.items():
            with self.subTest(rid=rid):
                self.assertIn(rid, self.index)
                source = re.search(r"(docs/[a-z0-9-]+\.md):L\d+", row[1])
                self.assertIsNotNone(source)
                self.assertTrue((ROOT / source.group(1)).is_file())
                self.assertIn(source.group(1), self.index[rid][1])
                self.assertGreater(len(row[2]), 15)
                self.assertGreater(len(row[7]), 50)
                self.assertEqual(row[3], self.index[rid][3])
                self.assertIn("UNKNOWN", row[7])
        with self.assertRaisesRegex(AssertionError, "duplicada"):
            first = next(line for line in self.report.splitlines() if line.startswith("| CB-0305 |"))
            parse_rows(self.report.replace(first, first + "\n" + first, 1))
    def test_cubierto_enlaza_prueba_existente(self) -> None:
        actual = {rid for rid, row in self.rows.items() if row[3] == "CUBIERTO"}
        self.assertEqual(actual, set(COVERED))
        for rid, (code, test, pr, sha) in COVERED.items():
            with self.subTest(rid=rid):
                row, index = self.rows[rid], self.index[rid]
                self.assertTrue((ROOT / code).is_file(), code)
                self.assertTrue((ROOT / test).is_file(), test)
                self.assertIn(code, row[4])
                self.assertIn(test, row[5])
                self.assertIn(f"/pull/{pr}", row[6])
                self.assertIn(sha, row[6])
                self.assertIn(f"Código {code}; prueba {test}; PR #{pr} MERGED;", index[4])
                self.assertIn("live=UNKNOWN", index[4])
        for rid, row in self.rows.items():
            if rid not in COVERED:
                self.assertEqual(row[6], "—")
                self.assertEqual(row[3], "NO_VERIFICABLE")
    def test_faltante_tiene_issue(self) -> None:
        for rid, row in self.rows.items():
            with self.subTest(rid=rid):
                require_missing_issue(row, self.index[rid])
        sample = list(self.rows["CB-0305"])
        sample[3] = "SIN_COBERTURA"
        with self.assertRaisesRegex(AssertionError, "hoja canónica"):
            require_missing_issue(tuple(sample), self.index["CB-0305"])
    def test_matriz_actualizada(self) -> None:
        self.assertIn("contraste-cb_0301_0600.md", self.matrix)
        self.assertEqual(len(self.index), 215)
        for rid, row in self.rows.items():
            with self.subTest(rid=rid):
                self.assertEqual(self.index[rid][3], row[3])
                self.assertIn("contraste-cb_0301_0600.md", self.index[rid][4])
        stats = Counter(row[3] for row in self.index.values())
        self.assertEqual(stats["CUBIERTO"], 12)
        self.assertEqual(stats["NO_VERIFICABLE"], 203)
        self.assertIn("| CUBIERTO | 12 |", self.matrix)
        self.assertIn("| NO_VERIFICABLE | 203 |", self.matrix)
        self.assertIn("CUBIERTO=12, EN_CURSO=0, SIN_COBERTURA=0, DESCARTADO=0, NO_VERIFICABLE=1182", self.matrix)
        self.assertIn("docs/contraste-cb_0301_0600.md", self.matrix)
        with self.assertRaisesRegex(AssertionError, "ocho columnas"):
            parse_rows(self.report.replace("| CB-0305 |", "| CB-0305 | extra |", 1))
if __name__ == "__main__":
    unittest.main()
