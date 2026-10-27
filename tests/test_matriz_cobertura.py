"""Aceptación ejecutable #940: fuentes, estados y evidencia documental fail-closed."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "matriz-cobertura.md"
DERIVED_PART = re.compile(r"matriz-cobertura-parte-0[1-4]\.md")


def is_normative_document(path: Path) -> bool:
    """Solo las cuatro particiones canónicas son salidas, no fuentes."""
    return path != DOC and not (path.parent == ROOT / "docs" and DERIVED_PART.fullmatch(path.name))
ALLOWED = frozenset({"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"})
CONTAINERS = frozenset({1, 45, 55, 182, 561, 567, 625, 750, 751, 760})
SOURCE = re.compile(
    r"^\[(?P<path>docs/[A-Za-z0-9_./-]+|README\.md|AGENTES\.md|issues/\d+):L(?P<line>[1-9]\d*)\]"
    r"\(https://github\.com/pl0n3r/ControlBot/(?P<ref>blob/[0-9a-f]{40}/[^)]+|issues/\d+)\)$"
)
ISSUE = re.compile(r"^\[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=id)\)$")
CODE_EVIDENCE = re.compile(r"^Código (?P<code>[-A-Za-z0-9_./]+); prueba (?P<test>[-A-Za-z0-9_./]+); PR #(?P<pr>\d+) MERGED;")


# Hoja posterior al snapshot histórico: catálogo explícito de PRs abiertos,
# sin incrementar ni relajar los 1.194 IDs de la matriz canónica.
POST_SNAPSHOT = {
    "docs/contraste-cb_0001_0300.md": (971, 996, "tests/test_contraste_cb_0001_0300.py"),
    "docs/contraste-cb_0301_0600.md": (972, 982, "tests/test_contraste_cb_0301_0600.py"),
    "docs/contraste-cb_0601_0900.md": (973, 987, "tests/test_contraste_cb_0601_0900.py"),
    "docs/contraste-cb_0901_1189.md": (974, 995, "tests/test_contraste_cb_0901_1189.py"),
    "docs/github-projection-owner-e2e.md": (993, 994, "tests/test_github_projection_owner_e2e.py"),
    "docs/operator-runbook.md": (959, 965, "tests/test_runbook_operador_doc.py"),
    "docs/dr-drill-plan.md": (960, 968, "tests/test_simulacro_dr_doc.py"),
    "docs/owner-login-acceptance-script.md": (961, 969, "tests/test_guion_login_doc.py"),
    "docs/emergency-shutdown-plan.md": (962, 966, "tests/test_apagado_emergencia_doc.py"),
    "docs/evidence-receipts.md": (963, 967, "tests/test_recibos_evidencia_doc.py"),
    "docs/signal-budget-load-script.md": (964, 970, "tests/test_carga_senales_doc.py"),
}
POST_TITLE = "\n## Fuentes de preparación posteriores al corte\n"
POST_CELL = re.compile(
    r"^\u0060(?P<path>docs/[a-z0-9_./-]+\.md)\u0060$"
)
POST_LINK_ISSUE = re.compile(
    r"^\[#(?P<n>\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(?P=n)\)$"
)
POST_LINK_PR = re.compile(
    r"^\[#(?P<n>\d+)\]\(https://github\.com/pl0n3r/ControlBot/pull/(?P=n)\)$"
)
POST_TEST = re.compile(r"^\u0060(tests/test_[a-z0-9_./-]+\.py)\u0060$")


def parse_post_snapshot(text: str) -> dict[str, tuple[int, int, str]]:
    """Contrato de fuente exacta; detecta duplicados, metadatos falsos y wildcards."""
    if text.count(POST_TITLE) != 1:
        raise ValueError("Registro post-snapshot único obligatorio")
    block = text.split(POST_TITLE, 1)[1].split("\n## ", 1)[0]
    rows = {}
    for line in block.splitlines():
        if not line.startswith("| "):
            continue
        if line.startswith("| Ruta |") or line.startswith("| --- |"):
            continue
        cells = [x.strip() for x in line.strip("|").split("|")]
        if len(cells) != 5:
            raise ValueError("Fila post-snapshot sin cinco columnas")
        path_match = POST_CELL.fullmatch(cells[0])
        issue_match = POST_LINK_ISSUE.fullmatch(cells[1])
        pr_match = POST_LINK_PR.fullmatch(cells[2])
        test_match = POST_TEST.fullmatch(cells[3])
        if not all((path_match, issue_match, pr_match, test_match)):
            raise ValueError("Ruta, Issue, PR o test post-snapshot inválido")
        path = path_match["path"]
        if any(segment in ("", ".", "..") for segment in path.split("/")):
            raise ValueError("Ruta post-snapshot inválido (path traversal)")
        if path in rows or path.endswith("/matriz-cobertura.md") or "matriz-cobertura-parte-" in path:
            raise ValueError("Documento duplicado o partición derivada prohibida")
        if cells[4] != "NO_VERIFICABLE / NO ENSAYADO":
            raise ValueError("Registro post-snapshot no acredita producción")
        rows[path] = (int(issue_match["n"]), int(pr_match["n"]), test_match.group(1))
    if rows != POST_SNAPSHOT:
        raise ValueError("Rutas/Issues/PRs de preparación no coinciden con catálogo verificado")
    return rows


def missing_normative_sources(text: str, discovered: set[str]) -> set[str]:
    """Solo índice histórico + entradas explícitas cubren fuentes documentales."""
    historical = {SOURCE.fullmatch(row[1])["path"] for row in parse_rows(text)}
    post = set(parse_post_snapshot(text))
    if historical & post:
        raise ValueError("Fuente histórica y post-snapshot duplicada")
    return discovered - historical - post


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


PART_NUMBERS = (1, 2, 3, 4)
PART_COUNTS = (245, 245, 250, 239)


def full_matrix() -> list[tuple[str, ...]]:
    """Fail-closed ante parte ausente, IDs ausentes/duplicados o pruebas faltantes."""
    gathered = parse_rows(DOC.read_text(encoding="utf-8"))
    ids = {row[0] for row in gathered}
    if len(gathered) != 215:
        raise ValueError("Índice de fuentes original no esperado")
    for number, expected in zip(PART_NUMBERS, PART_COUNTS):
        path = ROOT / "docs" / f"matriz-cobertura-parte-{number:02d}.md"
        qa = ROOT / "tests" / f"test_matriz_parte_{number:02d}.py"
        if not path.is_file() or not qa.is_file():
            raise ValueError(f"Parte o prueba de integridad ausente: {number}")
        subset = parse_rows(path.read_text(encoding="utf-8"))
        if len(subset) != expected:
            raise ValueError(f"Cardinalidad de parte {number} distinta")
        for row in subset:
            if row[0] in ids:
                raise ValueError(f"Requisito duplicado: {row[0]}")
            ids.add(row[0])
        gathered.extend(subset)
    expected_ids = {f"CB-{n:04d}" for n in range(1, 1195)}
    if ids != expected_ids or len(gathered) != 1194:
        raise ValueError("Matriz incompleta o IDs ajenos al snapshot")
    return gathered

class MatrizCoberturaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")
        cls.rows = parse_rows(cls.text)

    def test_cubre_todas_las_fuentes(self):
        files = {f"docs/{p.relative_to(ROOT / 'docs').as_posix()}"
                 for p in (ROOT / "docs").rglob("*.md") if is_normative_document(p)}
        sources = {SOURCE.fullmatch(r[1])["path"] for r in self.rows}
        self.assertGreaterEqual(len(files), 191)
        post = parse_post_snapshot(self.text)
        self.assertFalse(sources & set(post))
        self.assertFalse(missing_normative_sources(self.text, files),
                         sorted(missing_normative_sources(self.text, files)))
        # Un documento posterior ya presente en HEAD requiere también prueba real.
        for path, (_, _, test_path) in post.items():
            if (ROOT / path).is_file():
                self.assertTrue((ROOT / test_path).is_file(), path)
        self.assertTrue({"README.md", "AGENTES.md"} <= sources)
        self.assertTrue({f"issues/{i}" for i in CONTAINERS} <= sources)
        self.assertGreaterEqual(sum(r[1].startswith("[issues/1:") for r in self.rows), 11)

    def test_particiones_son_salidas_no_fuentes(self):
        self.assertFalse(is_normative_document(DOC))
        for number in range(1, 5):
            name = f"matriz-cobertura-parte-{number:02d}.md"
            path = ROOT / "docs" / name
            self.assertFalse(is_normative_document(path), name)
            if path.is_file():
                self.assertTrue(
                    (ROOT / "tests" / f"test_matriz_parte_{number:02d}.py").is_file(),
                    f"Partición sin test de integridad: {name}",
                )
        for name in ("sub/matriz-cobertura-parte-01.md",
                     "sub/matriz-cobertura-parte-04.md",
                     "matriz-cobertura-parte-externa.md",
                     "matriz-cobertura-parte-00.md",
                     "matriz-cobertura-parte-05.md",
                     "matriz-cobertura-parte-01-extra.md",
                     "nueva-fuente-normativa.md"):
            self.assertTrue(is_normative_document(ROOT / "docs" / name), name)

    def test_post_snapshot_registro_exacto_y_negativos(self):
        expected = parse_post_snapshot(self.text)
        self.assertEqual(expected, POST_SNAPSHOT)
        self.assertIn("docs/owner-login-acceptance-script.md", expected)
        self.assertIn("docs/contraste-cb_0901_1189.md", expected)
        self.assertIn("docs/github-projection-owner-e2e.md", expected)
        unknown = "docs/no-registrada.md"
        self.assertEqual(missing_normative_sources(self.text, {unknown}), {unknown})
        self.assertEqual(missing_normative_sources(self.text, set(expected)), set())
        with self.assertRaisesRegex(ValueError, "único obligatorio"):
            parse_post_snapshot(self.text.replace(POST_TITLE, "", 1))
        with self.assertRaisesRegex(ValueError, "duplicado"):
            line = next(line for line in self.text.splitlines()
                        if line.startswith("| \u0060docs/owner-login-acceptance-script.md\u0060 |"))
            parse_post_snapshot(self.text + line + "\n")
        with self.assertRaisesRegex(ValueError, "no coinciden"):
            parse_post_snapshot(self.text.replace("[#961](https://github.com/pl0n3r/ControlBot/issues/961)",
                                                  "[#9999](https://github.com/pl0n3r/ControlBot/issues/9999)", 1))
        with self.assertRaisesRegex(ValueError, "no coinciden"):
            parse_post_snapshot(self.text.replace("[#969](https://github.com/pl0n3r/ControlBot/pull/969)",
                                                  "[#9999](https://github.com/pl0n3r/ControlBot/pull/9999)", 1))
        with self.assertRaisesRegex(ValueError, "no coinciden"):
            parse_post_snapshot(self.text.replace("tests/test_guion_login_doc.py",
                                                  "tests/test_inexistente.py", 1))
        with self.assertRaisesRegex(ValueError, "inválido"):
            parse_post_snapshot(self.text.replace("\u0060docs/owner-login-acceptance-script.md\u0060",
                                                  "\u0060docs/../owner-login-acceptance-script.md\u0060", 1))
        with self.assertRaisesRegex(ValueError, "no acredita"):
            parse_post_snapshot(self.text.replace("NO_VERIFICABLE / NO ENSAYADO", "CUBIERTO", 1))
        with self.assertRaisesRegex(ValueError, "prohibida"):
            parse_post_snapshot(self.text.replace("docs/owner-login-acceptance-script.md",
                                                  "docs/matriz-cobertura-parte-01.md", 1))

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
            parse_rows(self.text.replace("| CB-0001 |", "| CB-0011 |", 1))
        with self.assertRaises(ValueError):
            parse_rows(self.text.replace(self.rows[0][5],
                                         "[#0](https://github.com/otro/proyecto/issues/0)", 1))

    def test_union_global_1194_estricta(self):
        rows = full_matrix()
        counts = Counter(row[3] for row in rows)
        self.assertEqual(counts, {"CUBIERTO": 30, "NO_VERIFICABLE": 1164})
        self.assertEqual(len(rows), 1194)
        summary = ("**Consolidado de 1.194 obligaciones:** CUBIERTO=30, "
                   "EN_CURSO=0, SIN_COBERTURA=0, DESCARTADO=0, "
                   "NO_VERIFICABLE=1164; porcentaje CUBIERTO=2.51%. "
                   "**Hojas nuevas creadas: 0.**")
        self.assertIn(summary, self.text)

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
