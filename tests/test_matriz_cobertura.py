"""Aceptación ejecutable #940: fuentes, estados y evidencia documental fail-closed."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "matriz-cobertura.md"
ALLOWED = frozenset({"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"})
CONTAINERS = frozenset({1, 45, 55, 182, 561, 567, 625, 750, 751, 760})
SOURCE = re.compile(
    r"^\[(?P<path>docs/[A-Za-z0-9_./-]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<ref>blob/[0-9a-f]{40}/[^)]+|issues/\d+)\)$"
)
ISSUE = re.compile(r"^\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)$")
CODE_EVIDENCE = re.compile(r"^Código (?P<code>[-A-Za-z0-9_./]+); prueba (?P<test>[-A-Za-z0-9_./]+); PR #(?P<pr>\d+) MERGED;")


def parse_rows(text: str) -> list[tuple[str, ...]]:
    if text.count("\n## Matriz\n") != 1 or text.count("\n## Resumen\n") != 1:
        raise ValueError("Secciones Matriz y Resumen únicas obligatorias")
    section = text.split("\n## Matriz\n", 1)[1].split("\n## Resumen\n", 1)[0]
    rows: list[tuple[str, ...]] = []
    seen: set[str] = set()
    for line in section.splitlines():
        if not line.startswith("|"):
            continue
        cells = tuple(v.strip() for v in line.strip().strip("|").split("|"))
        if cells[0] in ("ID", "---") or set(cells[0]) <= {"-", ":", " "}:
            continue
        if len(cells) != 6 or any(not cell for cell in cells):
            raise ValueError("Fila sin seis columnas completas")
        rid, source, requirement, status, evidence, issue = cells
        if not re.fullmatch(r"CB-\d{4}", rid) or rid in seen:
            raise ValueError("ID de requisito incorrecto o repetido")
        seen.add(rid)
        src = SOURCE.fullmatch(source)
        link = ISSUE.fullmatch(issue)
        if src is None or link is None:
            raise ValueError("La fuente y el Issue requieren URLs exactas")
        if src["path"].startswith("issues/"):
            if src["ref"] != src["path"]:
                raise ValueError("Identidad de Issue de fuente incorrecta")
        elif not src["ref"].endswith(f"/{src['path']}#L{src['line']}"):
            raise ValueError("Identidad de fichero o línea de fuente incorrecta")
        if len(requirement) < 20 or status not in ALLOWED:
            raise ValueError("Requisito vacío o estado no permitido")
        issue_id = int(link["id"])
        if status in {"EN_CURSO", "SIN_COBERTURA"}:
            if issue_id in CONTAINERS or "Hoja ejecutable" not in evidence:
                raise ValueError("Una hoja ejecutable no es un epic o roadmap")
        if status == "CUBIERTO":
            match = CODE_EVIDENCE.search(evidence)
            if match is None:
                raise ValueError("CUBIERTO requiere ruta de código, test y PR MERGED")
            for filename in (match["code"], match["test"]):
                if not (ROOT / filename).is_file():
                    raise ValueError(f"Falta artefacto de evidencia: {filename}")
            if "live=UNKNOWN" not in evidence:
                raise ValueError("Evidencia offline no certifica operación live")
        if status == "NO_VERIFICABLE" and "UNKNOWN" not in evidence:
            raise ValueError("NO_VERIFICABLE requiere evidencia de incertidumbre")
        if status == "DESCARTADO" and "razón" not in evidence.lower():
            raise ValueError("DESCARTADO requiere razón documentada")
        rows.append(cells)
    if not rows:
        raise ValueError("Matriz vacía")
    return rows


def parse_summary(text: str) -> dict[str, int]:
    if text.count("\n## Resumen\n") != 1:
        raise ValueError("Resumen único obligatorio")
    s = text.split("\n## Resumen\n", 1)[1]
    totals = {}
    for state, count in re.findall(
        r"^\|\s*(CUBIERTO|EN_CURSO|SIN_COBERTURA|DESCARTADO|NO_VERIFICABLE|TOTAL)\s*\|\s*(\d+)\s*\|$",
        s, re.M
    ):
        if state in totals:
            raise ValueError("Fila duplicada en el resumen")
        totals[state] = int(count)
    if set(totals) != ALLOWED | {"TOTAL"}:
        raise ValueError("Resumen no incluye todos los estados")
    return totals


class MatrizCoberturaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = parse_rows(cls.text)

    def test_cubre_todas_las_fuentes(self):
        files = {f"docs/{p.relative_to(ROOT / 'docs').as_posix()}"
                 for p in (ROOT / "docs").rglob("*.md") if p.name != DOC.name}
        sources = {SOURCE.fullmatch(r[1])["path"] for r in self.rows}
        self.assertGreaterEqual(len(files), 191)
        self.assertTrue(files <= sources, sorted(files - sources))
        self.assertTrue({"README.md", "AGENTES.md"} <= sources)
        self.assertTrue({f"issues/{i}" for i in CONTAINERS} <= sources)
        self.assertGreaterEqual(sum(r[1].startswith("[issues/1:") for r in self.rows), 11)

    def test_sin_cobertura_tiene_issue(self):
        for row in self.rows:
            if row[3] == "SIN_COBERTURA":
                self.assertIsNotNone(ISSUE.fullmatch(row[5]))
                self.assertIn("Hoja ejecutable", row[4])
                self.assertNotIn(int(ISSUE.fullmatch(row[5])["id"]), CONTAINERS)
        bad = self.text.replace(
            "| NO_VERIFICABLE |", "| SIN_COBERTURA |", 1
        )
        with self.assertRaises(ValueError):
            parse_rows(bad)

    def test_formato_y_estados(self):
        self.assertEqual(len(self.rows), len({row[0] for row in self.rows}))
        self.assertEqual({row[3] for row in self.rows} - ALLOWED, set())
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace("| NO_VERIFICABLE |", "| CERTIFICADO |", 1))
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace("CB-0001", "CB-0011", 1))
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace(self.rows[0][5],
                                         "[#0](https://github.com/otro/proyecto/issues/0)", 1))

    def test_resumen_por_estado(self):
        actual = Counter(row[3] for row in self.rows)
        found = parse_summary(self.text)
        self.assertEqual(found["TOTAL"], len(self.rows))
        for status in ALLOWED:
            self.assertEqual(found[status], actual[status])
        self.assertIn("Hojas nuevas creadas por esta auditoría: 0", self.text)
        percentage = 100 * actual["CUBIERTO"] / len(self.rows)
        self.assertIn(f"**Porcentaje CUBIERTO:** {percentage:.2f}%", self.text)
        with self.assertRaises(ValueError):
            parse_summary(self.text.replace("| TOTAL |", "| TOTAL_FAKE |", 1))


if __name__ == "__main__":
    unittest.main()
