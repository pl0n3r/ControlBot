#!/usr/bin/env python3
"""ControlBot #1081: fifteen explicit, offline, fail-closed coverage gaps.

Normative source text is not behavioral or production evidence. These cases
protect the integrity of CB-1132..CB-1146 without promoting any row in the
coverage matrix or claiming a running service.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest


from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
README = "README.md"
AGENTS = "AGENTES.md"
CONTRAST = "docs/contraste-cb_0901_1189.md"
SOURCES = frozenset({MAIN, PART4, README, AGENTS, CONTRAST})
IDS = tuple(f"CB-{number:04d}" for number in range(1132, 1147))
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"

# Source locations and clauses come from the actual coverage matrix.
CLAUSES = {
    "CB-1132": (PART4, README, "README.md:L34", "observación de reservas V3"),
    "CB-1133": (PART4, README, "README.md:L55", "Control plane GitHub:"),
    "CB-1134": (PART4, README, "README.md:L56", "Operación/health:"),
    "CB-1135": (PART4, README, "README.md:L57", "Despliegue:"),
    "CB-1136": (PART4, README, "README.md:L63", "rama canónica → implementación"),
    "CB-1137": (PART4, README, "README.md:L65", "Un merge no equivale a go-live"),
    "CB-1138": (PART4, README, "README.md:L80", "evidencia verificable exact-HEAD"),
    "CB-1139": (PART4, README, "README.md:L82", "autoridades y tratamiento"),
    "CB-1140": (PART4, README, "README.md:L85", "La evidencia operativa se consulta"),
    "CB-1141": (PART4, README, "README.md:L103", "productos independientes con sus propios datos"),
    "CB-1142": (PART4, README, "README.md:L109", "El inbox descubre puertas humanas abiertas"),
    "CB-1143": (PART4, README, "README.md:L113", "El runtime resuelve las acciones del dueño"),
    "CB-1144": (PART4, README, "README.md:L117", "ControlBot reutiliza una bitácora append-only"),
    "CB-1145": (MAIN, AGENTS, "AGENTES.md:L8", "nunca comparte código ni base con Condor"),
    "CB-1146": (PART4, AGENTS, "AGENTES.md:L9", "acceso solo del dueño (passkey o contraseña + 2FA TOTP)"),
}

# Each clause has an independently identifiable proof that is still missing.
# An explicit gap is an allowed AC-01 outcome; it is never a passed contract.
MISSING = {
    "CB-1132": "reserve_observer_readonly_end_to_end_without_release",
    "CB-1133": "github_projection_permissioned_adapter_runtime",
    "CB-1134": "owner_gate_and_health_authentic_observation",
    "CB-1135": "hostinger_backup_restore_before_mutation_receipt",
    "CB-1136": "issue_reservation_pr_ci_merge_chain_verified",
    "CB-1137": "release_owner_gate_and_production_identity_receipt",
    "CB-1138": "pr_ci_release_exact_sha_evidence_reconciled",
    "CB-1139": "decisions_and_privacy_policy_enforced_runtime",
    "CB-1140": "health_observer_read_from_authoritative_sources",
    "CB-1141": "independent_products_data_runtime_deploy_verified",
    "CB-1142": "owner_inbox_open_gates_complete_pagination",
    "CB-1143": "server_side_csrf_and_mutation_adapter_authority",
    "CB-1144": "private_append_only_history_and_allowed_https",
    "CB-1145": "separate_app_database_deploy_provenance",
    "CB-1146": "owner_only_mfa_sessions_and_revocable_bridge_keys",
}


def documents() -> dict[str, str]:
    """Read checked-in public contracts only; no network or user records."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicated, malformed or prematurely promoted clauses."""
    if type(files) is not dict or set(files) != SOURCES:
        raise ValueError("UNKNOWN: source inventory")
    if any(type(files.get(path)) is not str or not files[path] for path in SOURCES):
        raise ValueError("UNKNOWN: unreadable source")

    rows: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART4):
        for line in files[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            expected_path, source, ref, phrase = CLAUSES[key]
            columns = tuple(part.strip() for part in line.strip().strip("|").split("|"))
            if (key in rows or path != expected_path or len(columns) != 6
                    or columns[0] != key or ref not in columns[1]
                    or phrase.casefold() not in columns[2].casefold()
                    or not credible_offline_coverage_row(columns)
                    or "/issues/1" not in columns[5]
                    or phrase.casefold() not in files[source].casefold()):
                raise ValueError("UNKNOWN: invalid or promoted " + key)
            rows[key] = columns
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing or duplicated coverage clauses")

    # CB-1145 has an explicit cross-reference to the normative gap inventory.
    contrast_rows = [
        line for line in files[CONTRAST].splitlines()
        if re.match(r"^\|\s*CB-1145\s*\|", line)
    ]
    if len(contrast_rows) != 1 or "NO_VERIFICABLE" not in contrast_rows[0]:
        raise ValueError("UNKNOWN: missing contrast provenance")
    return rows


def gap(key: object, files: object) -> dict[str, str]:
    """Document the per-clause missing proof, never infer execution success."""
    unknown = {"status": UNKNOWN, "missing": "source_or_clause_unverified"}
    if type(key) is not str or key not in CLAUSES:
        return unknown
    try:
        rows = matrix_rows(files)
    except (TypeError, ValueError, KeyError, AttributeError):
        return unknown
    if key not in rows:
        return unknown
    return {"status": GAP, "missing": MISSING[key]}


def runtime_attestation(_key: object, _receipt: object = None) -> str:
    """Offline documentation and caller-provided receipts cannot prove live."""
    return UNKNOWN


class BrechasControlbot74Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one explicit, unique documented gap for each of 15 rows."""
        files = documents()
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(CLAUSES), set(IDS))
        self.assertEqual(set(MISSING), set(IDS))
        self.assertEqual(len(set(MISSING.values())), len(IDS))
        rows = matrix_rows(files)
        self.assertEqual(set(rows), set(IDS))
        for key in IDS:
            with self.subTest(id=key):
                self.assertTrue(credible_offline_coverage_row(rows[key]))
                self.assertEqual({"status": GAP, "missing": MISSING[key]}, gap(key, files))
                self.assertEqual(UNKNOWN, runtime_attestation(key))

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed, promoted or source-less evidence stays UNKNOWN."""
        files = documents()
        for key in IDS:
            path, source, _, phrase = CLAUSES[key]
            original = next(
                line for line in files[path].splitlines()
                if re.match(r"^\|\s*" + re.escape(key) + r"\s*\|", line)
            )
            variants = (
                files[path].replace(original, "", 1),
                files[path] + "\n" + original,
                files[path].replace(original, original.replace(
                    "MERGED", "PENDING", 1) if "CUBIERTO" in original
                    else original.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
                files[path].replace(original, re.sub(r"(?i)offline=UNKNOWN", "offline=GREEN", original), 1),
            )
            for altered in variants:
                with self.subTest(id=key):
                    damaged = {**files, path: altered}
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(UNKNOWN, gap(key, damaged)["status"])
            missing_source = {**files, source: files[source].replace(phrase, "", 1)}
            self.assertEqual(UNKNOWN, gap(key, missing_source)["status"])
        damaged_contrast = {**files, CONTRAST: re.sub(
            r"(?m)^\|\s*CB-1145\s*\|.*$", "CB-1145: mention without a table row",
            files[CONTRAST], count=1,
        )}
        self.assertEqual(UNKNOWN, gap("CB-1145", damaged_contrast)["status"])
        for invalid in (None, {}, True, {MAIN: "one fake row"}):
            self.assertEqual(UNKNOWN, gap("CB-1132", invalid)["status"])
        self.assertEqual(UNKNOWN, gap("CB-9999", files)["status"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: only public versioned documents and standard-library checks."""
        path = ROOT / "tests/test_brechas_controlbot_74.py"
        syntax = ast.parse(path.read_text(encoding="utf-8"))
        imported = set()
        for node in ast.walk(syntax):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "pathlib",
                                        "re", "unittest", "test_matriz_cobertura"})
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in SOURCES))
        self.assertEqual(set(documents()), SOURCES)
        self.assertEqual(UNKNOWN, runtime_attestation(
            "CB-1146", {"has_real_tokens": False, "owner_authenticated": True}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: success-shaped caller data is never production evidence."""
        files = documents()
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(GAP, gap(key, files)["status"])
                for forged in (
                    None, True, {}, {"ci": "success"},
                    {"release": "success", "head": "fake"},
                    {"status": "VALIDATED_IN_PRODUCTION", "pending": []},
                    {"backup": "verified", "owner_approved": True},
                    {"health": 200, "authorization": "admin"},
                ):
                    self.assertEqual(UNKNOWN, runtime_attestation(key, forged))
        self.assertFalse(any(gap(key, files)["status"] == "CUBIERTO"
                             for key in IDS))


if __name__ == "__main__":
    unittest.main()
