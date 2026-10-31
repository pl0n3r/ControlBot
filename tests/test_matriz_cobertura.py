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
    "docs/github-readonly-installation-admission.md": (998, 999, "tests/test_github_readonly_installation_admission.py"),
    "docs/github-readonly-admission-projection-gate.md": (1003, 1004, "tests/test_github_readonly_admission_projection_gate.py"),
    "docs/DISENO-CONTROLBOT.md": (1169, 1171, "tests/test_control_theme_redesign.py"),
    "docs/operator-runbook.md": (959, 965, "tests/test_runbook_operador_doc.py"),
    "docs/dr-drill-plan.md": (960, 968, "tests/test_simulacro_dr_doc.py"),
    "docs/owner-login-acceptance-script.md": (961, 969, "tests/test_guion_login_doc.py"),
    "docs/emergency-shutdown-plan.md": (962, 966, "tests/test_apagado_emergencia_doc.py"),
    "docs/evidence-receipts.md": (963, 967, "tests/test_recibos_evidencia_doc.py"),
    "docs/signal-budget-load-script.md": (964, 970, "tests/test_carga_senales_doc.py"),
    "docs/hostinger-deploy-backup-gate.md": (1359, 1367, "tests/test_deploy_backup_gate.py"),
    "docs/health-monitor.md": (1370, 1376, "tests/test_health_monitor_alerts.py"),
    "docs/profiles-chats-inventory.md": (1362, 1386, "tests/test_profiles_chats_inventory.py"),
    "docs/hostinger-transport.md": (1368, 1392, "tests/test_hostinger_transport.py"),
    "docs/secretos-en-archivo-hostinger.md": (1349, 1350, "tests/test_secret_source.py"),
    "docs/hostinger-additive-only.md": (1369, 1398, "tests/test_hostinger_additive_only.py"),
    "docs/auth-negative-tests.md": (1364, 1400, "tests/test_auth_negative.py"),
    "docs/quota-panel.md": (1360, 1403, "tests/test_quota_panel_nonblocking.py"),
    "docs/support-channels.md": (1358, 1404, "tests/test_support_channels.py"),
}
POST_TITLE = "\n## Fuentes de preparación posteriores al corte\n"
POST_CELL = re.compile(
    r"^\u0060(?P<path>docs/(?:[a-z0-9_./-]+|DISENO-CONTROLBOT)\.md)\u0060$"
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


_OFFLINE_RECONCILIATION = (
    (1, 16, 1261, 3), (17, 30, 1260, 4), (31, 49, 1301, 5),
    (50, 69, 1299, 6), (70, 86, 1306, 7), (87, 104, 1307, 8),
    (105, 123, 1300, 9), (124, 140, 1302, 10),
    (141, 166, 1290, 11), (167, 183, 1304, 12),
    (184, 203, 1291, 13), (204, 220, 1296, 14),
    (221, 238, 1286, 15), (239, 253, 1287, 16),
    (254, 273, 1288, 17), (274, 291, 1292, 18),
    (1130, 1144, 1258, 2), (1145, 1151, 1259, 1),
)


def credible_offline_coverage_row(row):
    """Reject fabricated CUBIERTO while accepting a real offline transition."""
    if not isinstance(row, (tuple, list)) or len(row) != 6:
        return False
    key, _, requirement, status, evidence, _ = row
    if not isinstance(key, str) or re.fullmatch(r"CB-\d{4}", key) is None:
        return False
    if not isinstance(requirement, str) or not requirement or not isinstance(evidence, str):
        return False
    if status == "NO_VERIFICABLE":
        return "offline=unknown" in evidence.lower() and "live=UNKNOWN" in evidence and "operando=UNKNOWN" in evidence
    if status != "CUBIERTO":
        return False
    if "live=UNKNOWN" not in evidence or "operando=UNKNOWN" not in evidence:
        return False
    match = re.match(
        r"^Código (src/[A-Za-z0-9_.-]+\.php|ops/factory/deploy); "
        r"prueba (tests/test_cobertura_controlbot_(\d{2})\.py); "
        r"PR #(\d+) MERGED; cláusula (CB-\d{4}) ejercida offline",
        evidence,
    )
    if match is None or match.group(5) != key:
        return False
    number = int(key[3:])
    expected = next(
        ((pr, test) for low, high, pr, test in _OFFLINE_RECONCILIATION
         if low <= number <= high), None
    )
    if expected is None or expected != (int(match.group(4)), int(match.group(3))):
        return False
    return (ROOT.joinpath(match.group(1)).is_file()
            and ROOT.joinpath(match.group(2)).is_file())


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

    def test_registro_guia_secretos_privados_exacta(self):
        """La guía privada se vincula al Issue, PR y prueba reales sin promocionar live."""
        expected = (1349, 1350, "tests/test_secret_source.py")
        self.assertEqual(expected, POST_SNAPSHOT["docs/secretos-en-archivo-hostinger.md"])
        self.assertEqual(expected, parse_post_snapshot(self.text)["docs/secretos-en-archivo-hostinger.md"])

    def test_registro_hostinger_additive_only_exacto(self):
        """La política offline #1369 referencia el PR #1398 sin acreditar producción."""
        expected = (1369, 1398, "tests/test_hostinger_additive_only.py")
        self.assertEqual(expected, POST_SNAPSHOT["docs/hostinger-additive-only.md"])
        self.assertEqual(expected, parse_post_snapshot(self.text)["docs/hostinger-additive-only.md"])

    def test_registro_auth_negative_exacto(self):
        """#1364/#1400: documento de pruebas sintéticas, sin atestación live."""
        expected = (1364, 1400, "tests/test_auth_negative.py")
        self.assertEqual(expected, POST_SNAPSHOT["docs/auth-negative-tests.md"])
        self.assertEqual(expected, parse_post_snapshot(self.text)["docs/auth-negative-tests.md"])
        # Los archivos de #1364 pertenecen al PR bloqueado #1400:
        # registrar la identidad no presupone que ya estén en esta rama.

    def test_registro_support_channels_exacto(self):
        """#1358 / PR #1404: identidad y estado post-snapshot de canales de soporte."""
        path = "docs/support-channels.md"
        expected = (1358, 1404, "tests/test_support_channels.py")
        self.assertEqual(expected, POST_SNAPSHOT[path])
        self.assertEqual(expected, parse_post_snapshot(self.text)[path])
        self.assertEqual(missing_normative_sources(self.text, {path}), set())
        self.assertNotIn(path, {SOURCE.fullmatch(row[1])["path"] for row in self.rows})
        rows = [row for row in self.text.splitlines()
                if row.startswith("| `" + path + "` |")]
        self.assertEqual(1, len(rows), "La fuente post-snapshot es única")
        row = rows[0]
        self.assertTrue(row.endswith("| NO_VERIFICABLE / NO ENSAYADO |"))
        for original, replacement in (
            (path, "docs/soporte-inventado.md"),
            ("[#1358](https://github.com/pl0n3r/ControlBot/issues/1358)",
             "[#9999](https://github.com/pl0n3r/ControlBot/issues/9999)"),
            ("[#1404](https://github.com/pl0n3r/ControlBot/pull/1404)",
             "[#9999](https://github.com/pl0n3r/ControlBot/pull/9999)"),
            ("tests/test_support_channels.py", "tests/test_falso.py"),
        ):
            with self.subTest(replacement=replacement):
                altered = self.text.replace(row, row.replace(original, replacement, 1), 1)
                self.assertNotEqual(altered, self.text)
                with self.assertRaisesRegex(ValueError, "no coinciden"):
                    parse_post_snapshot(altered)
        with self.assertRaisesRegex(ValueError, "no acredita"):
            parse_post_snapshot(self.text.replace(
                row, row.replace("NO_VERIFICABLE / NO ENSAYADO", "CUBIERTO", 1),
            ))
        with self.assertRaisesRegex(ValueError, "duplicado"):
            parse_post_snapshot(self.text.replace(row, row + "\n" + row, 1))

    def test_registro_quota_panel_nonblocking_exacto(self):
        """#1360 / PR #1403: registra fuente sin acreditar medición ni desplegar."""
        path = "docs/quota-panel.md"
        expected = (1360, 1403, "tests/test_quota_panel_nonblocking.py")
        self.assertEqual(expected, POST_SNAPSHOT[path])
        self.assertEqual(expected, parse_post_snapshot(self.text)[path])
        self.assertEqual(missing_normative_sources(self.text, {path}), set())
        self.assertNotIn(path, {SOURCE.fullmatch(r[1])["path"] for r in self.rows})

        rows = [line for line in self.text.splitlines()
                if line.startswith("| `" + path + "` |")]
        self.assertEqual(1, len(rows), "La fuente post-snapshot se registra solo una vez")
        row = rows[0]
        self.assertTrue(row.endswith("| NO_VERIFICABLE / NO ENSAYADO |"))

        for original, falsified in (
            (path, "docs/cuota-falsa.md"),
            ("[#1360](https://github.com/pl0n3r/ControlBot/issues/1360)",
             "[#1406](https://github.com/pl0n3r/ControlBot/issues/1406)"),
            ("[#1403](https://github.com/pl0n3r/ControlBot/pull/1403)",
             "[#9999](https://github.com/pl0n3r/ControlBot/pull/9999)"),
            ("tests/test_quota_panel_nonblocking.py", "tests/test_cuota_falsa.py"),
        ):
            with self.subTest(falsified=falsified):
                altered = self.text.replace(row, row.replace(original, falsified, 1), 1)
                self.assertNotEqual(self.text, altered)
                with self.assertRaisesRegex(ValueError, "no coinciden"):
                    parse_post_snapshot(altered)

        with self.assertRaisesRegex(ValueError, "no acredita"):
            parse_post_snapshot(self.text.replace(
                row, row.replace("NO_VERIFICABLE / NO ENSAYADO", "CUBIERTO"), 1,
            ))
        with self.assertRaisesRegex(ValueError, "duplicado"):
            parse_post_snapshot(self.text.replace(row, row + "\n" + row, 1))

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
        self.assertEqual(expected["docs/github-readonly-installation-admission.md"],
                         (998, 999, "tests/test_github_readonly_installation_admission.py"))
        self.assertEqual(expected["docs/github-readonly-admission-projection-gate.md"],
                         (1003, 1004, "tests/test_github_readonly_admission_projection_gate.py"))
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

    def test_controlbot_design_source_is_exact_and_unknown(self):
        """El documento #1169 no aumenta los IDs ni acredita un deploy."""
        target = "docs/DISENO-CONTROLBOT.md"
        self.assertEqual(
            parse_post_snapshot(self.text)[target],
            (1169, 1171, "tests/test_control_theme_redesign.py"),
        )
        line = next(row for row in self.text.splitlines()
                    if row.startswith("| " + chr(96) + target + chr(96) + " |"))
        self.assertTrue(line.endswith("| NO_VERIFICABLE / NO ENSAYADO |"))
        self.assertNotIn(target, {SOURCE.fullmatch(r[1])["path"] for r in self.rows})
        self.assertEqual(missing_normative_sources(self.text, {target}), set())
        with self.assertRaisesRegex(ValueError, "inválido"):
            parse_post_snapshot(self.text.replace(
                target, "docs/OTRA-FUENTE-NO-PERMITIDA.md", 1,
            ))
        with self.assertRaisesRegex(ValueError, "no acredita"):
            parse_post_snapshot(self.text.replace(
                line, line.replace("NO_VERIFICABLE / NO ENSAYADO", "CUBIERTO"), 1,
            ))

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
        # La fuente vigente son las 1.194 filas, nunca una cifra histórica fija.
        # full_matrix valida las cuatro particiones, cardinalidades, IDs únicos
        # y evidencia fail-closed por estado antes de devolver las filas.
        rows = full_matrix()
        counts = Counter(row[3] for row in rows)
        self.assertEqual(len(rows), 1194)
        self.assertEqual(len({row[0] for row in rows}), 1194)
        self.assertEqual(set(counts) - ALLOWED, set())
        self.assertEqual(sum(counts.values()), 1194)
        self.assertEqual(
            sum(counts[state] for state in ALLOWED), 1194
        )

        # La cifra previa se conserva solo como snapshot trazable al SHA.
        historical = (
            "**Corte histórico pre-#1255 "
            "(main@67a414923b867f66ec3791b7ce2544f1412413fb):** "
            "CUBIERTO=51, EN_CURSO=0, SIN_COBERTURA=0, "
            "DESCARTADO=0, NO_VERIFICABLE=1143; "
            "porcentaje CUBIERTO=4.27%."
        )
        self.assertIn(historical, self.text)
        self.assertIn("**Consolidado vigente (derivado de 1.194 filas):**", self.text)
        self.assertNotIn("**Consolidado de 1.194 obligaciones:**", self.text)

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
