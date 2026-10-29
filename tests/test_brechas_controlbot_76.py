"""ControlBot #1083: fail-closed, traceable coverage of CB-1162..CB-1176.

These fifteen cases record what terminal evidence is missing from the canonical
coverage matrix. They are *not* simulated production approvals, UI acceptance,
recovery drills, personal-data processing, or a claim that the product is live.
No external IO, production credentials, GitHub/Sonar connections or real data.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
MATRICES = (MAIN, PART)
UNKNOWN = "UNKNOWN"
DOCUMENTED = "BRECHA_DOCUMENTADA"

# Exact text and canonical issue source seen in the complete matrix row.
# Source assertions detect drift, wrong partition and accidental promotions.
REQUIREMENTS = {
    "CB-1162": (MAIN, "issues/1:L11",
                "Toda acción importante tiene trazabilidad y evidencia."),
    "CB-1163": (MAIN, "issues/45:L1",
                "EPIC: Production Authority — Hostinger/SSH autónomo con backup gate y permisos temporales"),
    "CB-1164": (MAIN, "issues/55:L1",
                "Desde un Proyecto se ven todos sus repos con CI, PRs, Issues y releases reales."),
    "CB-1165": (PART, "issues/55:L2",
                "Una intención de workflow/merge/Issue pasa por policy antes de ejecutar."),
    "CB-1166": (PART, "issues/55:L3",
                "Cada ejecución deja evidencia enlazable y resultado idempotente cuando aplique."),
    "CB-1167": (PART, "issues/55:L4",
                "Si GitHub falla o responde ambiguo, ControlBot falla cerrado y no inventa estado."),
    "CB-1168": (PART, "issues/55:L5",
                "Permisos GitHub son mínimos y revocables."),
    "CB-1169": (PART, "issues/55:L6",
                "La UI funciona en iPhone y desktop."),
    "CB-1170": (MAIN, "issues/182:L1",
                "AEGIS / Disaster Recovery Center — backups, RPO/RTO, restore drills e integridad"),
    "CB-1171": (MAIN, "issues/561:L1",
                "Existe una vista «Fábrica viva» con las secciones 1 a 8, cada señal con fuente y antigüedad."),
    "CB-1172": (PART, "issues/561:L2",
                "Las decisiones del dueño pendientes de todos los repos aparecen arriba, con enlace al Issue."),
    "CB-1173": (PART, "issues/561:L3",
                "Un dato no disponible se muestra como UNKNOWN o STALE, nunca como éxito."),
    "CB-1174": (PART, "issues/561:L4",
                "Es solo lectura y no expone secretos."),
    "CB-1175": (PART, "issues/561:L5",
                "Funciona en móvil y cumple el contraste AA ya verificado para el centro de control."),
    "CB-1176": (PART, "issues/561:L6",
                "Tests con datos simulados de GitHub y Sonar para cada sección."),
}

# Fifteen independent acceptance gaps, not generic green checkboxes.
# Every case makes the missing proof explicit and never promotes UNKNOWN.
GAPS = {
    "CB-1162": "No authenticated action/audit receipt for each important action.",
    "CB-1163": "No terminal backup/restore gate and temporary SSH authority evidence.",
    "CB-1164": "No cross-repository project UI test with real CI/PR/issue/release identities.",
    "CB-1165": "No complete policy-before-mutation execution trace for each intent type.",
    "CB-1166": "No idempotent replay and linked-result witness for every execution path.",
    "CB-1167": "No verified ambiguous GitHub response failure/retry without invented status.",
    "CB-1168": "No revocation and minimum-scope permission enforcement evidence.",
    "CB-1169": "No verified iPhone and desktop navigation/interaction browser run.",
    "CB-1170": "No authentic AEGIS recovery receipts or RPO/RTO restore-drill result.",
    "CB-1171": "No eight-section live factory projection with source and age per signal.",
    "CB-1172": "No complete multi-repo pending owner-decision ordering and issue links.",
    "CB-1173": "No exhaustive unavailable/stale source projection against real UI states.",
    "CB-1174": "No tested read-only authorization and secret-redaction perimeter.",
    "CB-1175": "No complete mobile accessibility AA contrast for control-center view.",
    "CB-1176": "No executed synthetic GitHub/Sonar fixture suite for all eight sections.",
}

ID_PATTERN = re.compile(r"^\|\s*(CB-(?:116[2-9]|117[0-6]))\s*\|")


def read_sources() -> dict[str, str]:
    """Read only the two versioned, public requirements files."""
    return {name: (ROOT / name).read_text(encoding="utf-8")
            if (ROOT / name).is_file() else ""
            for name in MATRICES}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    """Reject duplicates, wrong source/partition, missing or promoted clauses."""
    if (type(files) is not dict or set(files) != set(MATRICES)
            or any(type(files[name]) is not str or not files[name] for name in MATRICES)):
        raise ValueError("UNKNOWN: missing or ambiguous coverage matrices")
    found = {}
    for filename in MATRICES:
        for line in files[filename].splitlines():
            match = ID_PATTERN.match(line)
            if match is None:
                continue
            ident = match.group(1)
            cells = tuple(item.strip() for item in line.strip().strip("|").split("|"))
            expected_file, origin, clause = REQUIREMENTS[ident]
            if (ident in found or filename != expected_file
                    or len(cells) != 6 or cells[0] != ident
                    or origin not in cells[1] or cells[2] != clause
                    or cells[3] != "NO_VERIFICABLE"
                    or "Aceptación integral o evidencia terminal no comprobada" not in cells[4]
                    or not all(value in cells[4].casefold() for value in
                               ("offline=unknown", "live=unknown", "operando=unknown"))):
                raise ValueError("UNKNOWN: invalid, duplicated or promoted " + ident)
            found[ident] = cells
    if set(found) != set(REQUIREMENTS):
        raise ValueError("UNKNOWN: incomplete coverage of CB-1162..CB-1176")
    return found


def gap_record(ident: object, files: object, external: object = None) -> dict[str, str]:
    """Documentation is the ONLY local evidence. It proves no real execution."""
    fail = {"local": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN,
            "operations": UNKNOWN, "authority": UNKNOWN}
    if not isinstance(ident, str) or ident not in REQUIREMENTS:
        return fail
    try:
        rows = matrix_rows(files)
    except (ValueError, TypeError):
        return fail
    if not rows.get(ident) or not GAPS.get(ident) or not GAPS[ident].startswith("No "):
        return fail
    # Even a claimed success/owner approval is unauthenticated input here.
    return {**fail, "local": DOCUMENTED}


def terminal_verdict(ident: object, proof: object = None) -> str:
    """No source document, fake check or synthetic assertion certifies live."""
    return UNKNOWN


class BrechasControlbot76Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = read_sources()

    def test_contrato_principal(self) -> None:
        """AC-01: each of the fifteen clauses has its own explicit UNKNOWN case."""
        rows = matrix_rows(self.files)
        self.assertEqual(15, len(rows))
        self.assertEqual(set(REQUIREMENTS), set(GAPS))
        self.assertEqual(set(rows), set(REQUIREMENTS))
        for ident in REQUIREMENTS:
            with self.subTest(ident=ident):
                self.assertEqual(rows[ident][3], "NO_VERIFICABLE")
                self.assertIn(ident, GAPS)
                self.assertGreater(len(GAPS[ident]), 45)
                record = gap_record(ident, self.files)
                self.assertEqual(record["local"], DOCUMENTED)
                self.assertEqual(record["operations"], UNKNOWN)
                self.assertEqual(terminal_verdict(ident, record), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: each row fails under missing, duplicate or dishonest evidence."""
        self.assertEqual(gap_record(None, self.files)["local"], UNKNOWN)
        for ident, (location, _origin, _clause) in REQUIREMENTS.items():
            with self.subTest(ident=ident):
                line = next(row for row in self.files[location].splitlines()
                            if ID_PATTERN.match(row) and ident == ID_PATTERN.match(row).group(1))
                for malformed in (
                    self.files[location].replace(line + "\n", "", 1),
                    self.files[location] + "\n" + line,
                    self.files[location].replace(line, line.replace(
                        "NO_VERIFICABLE", "CUBIERTO"), 1),
                    self.files[location].replace(line, "| " + ident + " | broken |", 1),
                ):
                    damaged = {**self.files, location: malformed}
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(gap_record(ident, damaged)["local"], UNKNOWN)
                for missing in MATRICES:
                    self.assertEqual(gap_record(ident, {
                        **self.files, missing: ""})["local"], UNKNOWN)
                self.assertEqual(terminal_verdict(ident, {"ci": "success"}), UNKNOWN)
        self.assertEqual(gap_record("CB-9999", self.files)["local"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network, secrets, personal info or mutable external sources."""
        self.assertEqual(set(MATRICES), {MAIN, PART})
        self.assertTrue(all((ROOT / name).is_file() for name in MATRICES))
        source = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(source)
        unsafe = {"socket", "urllib", "requests", "httpx", "selenium", "playwright",
                  "subprocess", "sqlite3", "pymysql", "mysql", "psycopg2"}
        modules = {a.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for a in node.names}
        modules.update(node.module.split(".")[0] for node in ast.walk(tree)
                       if isinstance(node, ast.ImportFrom) and node.module)
        self.assertFalse(modules & unsafe)
        self.assertFalse(any("password" in name.casefold()
                             for name in MATRICES))
        self.assertNotIn("GITHUB_TOKEN" + "=", source)
        self.assertNotIn("DB_PASSWORD" + "=", source)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: a CI success, synthetic fixture or approval never turns into live."""
        rows = matrix_rows(self.files)
        for ident in REQUIREMENTS:
            with self.subTest(ident=ident):
                self.assertEqual(rows[ident][3], "NO_VERIFICABLE")
                for fake in (None, True, "success", {"ci": "success"},
                             {"sonar": "success", "github": "success"},
                             {"approved": True}, {"production": "GREEN"}):
                    record = gap_record(ident, self.files, external=fake)
                    self.assertEqual(record["local"], DOCUMENTED)
                    for dimension in ("ci", "live", "operations", "authority"):
                        self.assertEqual(record[dimension], UNKNOWN)
                    self.assertEqual(terminal_verdict(ident, fake), UNKNOWN)
        self.assertEqual(terminal_verdict("CB-1163", "backup:ready"), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
