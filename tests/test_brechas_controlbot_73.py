#!/usr/bin/env python3
"""#1080: offline coverage for CB-1117..CB-1131; never attest live state.

The eight PHP scenarios are pre-existing, synthetic contract fixtures. In
particular, runtime integration of #208, site/go-live and actual production
GitHub authority stay explicitly UNKNOWN, not "CUBIERTO".
"""
from __future__ import annotations

from functools import lru_cache
import ast
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
ACCESS = "docs/verified-access-context.md"
FOCUS = "docs/weekly-focus-v1.md"
UI = "docs/weekly-focus-ui-v1.md"
README = "README.md"
BACKUP = "docs/hostinger-d059-backup.md"
GITHUB = "docs/github-control-plane-readonly-v1.md"
INTENT = "docs/github-intent-policy-v1.md"
VERIFIED_FIXTURE = "tests/verified_access_context_scenarios.php"
FOCUS_FIXTURE = "tests/weekly_focus_scenarios.php"
UI_FIXTURE = "tests/weekly_focus_ui_scenarios.php"
UNKNOWN = "UNKNOWN"
PARTIAL = "CONTRATO_LOCAL_PARCIAL"
GAP = "BRECHA_DOCUMENTADA"
IDS = tuple(f"CB-{i:04d}" for i in range(1117, 1132))
# Exact source/requirement fragments obtained from the coverage matrix.
CLAUSES = {
    "CB-1117": (MAIN, ACCESS, "VerifiedAccessContext", "frontera nominal"),
    "CB-1118": (PART, ACCESS, "La UI, requests y agentes", "query mínima"),
    "CB-1119": (PART, ACCESS, "identity, grant, scope, policy activa", "material sensible"),
    "CB-1120": (PART, ACCESS, "la emisión no muta lifecycle", "audit"),
    "CB-1121": (PART, ACCESS, "#208 debe consumir", "AuthorityProjection"),
    "CB-1122": (MAIN, UI, "Desktop expone drag/reorder", "move_down"),
    "CB-1123": (PART, UI, "El guardado optimista envía únicamente", "expected_version"),
    "CB-1124": (MAIN, FOCUS, "WeeklyFocus::preference()", "cohort"),
    "CB-1125": (PART, FOCUS, "El módulo no acepta", "selected_key"),
    "CB-1126": (PART, FOCUS, "Candidatos bloqueados", "unavailable"),
    "CB-1127": (PART, FOCUS, "Dentro de un cohort homogéneo", "focus_position"),
    "CB-1128": (PART, FOCUS, "Cambiar el foco solo afecta", "running"),
    "CB-1129": (PART, FOCUS, "revise()", "expected_version"),
    "CB-1130": (MAIN, README, "**BLOCKED:**", "D-059"),
    "CB-1131": (PART, README, "proyección GitHub read-only", "no conceden ejecución"),
}
DOCUMENTS = {MAIN, PART, ACCESS, FOCUS, UI, README, BACKUP, GITHUB, INTENT}
LOCAL = set(IDS) - {"CB-1121", "CB-1130", "CB-1131"}
SCENARIOS = {
    "verified": ("structural", "server", "copy", "readonly", "secrets", "decisions"),
    "focus": ("main",),
    "ui": ("main",),
}
SCRIPTS = {"verified": VERIFIED_FIXTURE, "focus": FOCUS_FIXTURE, "ui": UI_FIXTURE}


def documents() -> dict[str, str]:
    """Only checked-in files; no remote lookup and no personal records."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in DOCUMENTS}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(files, dict) or any(
        not isinstance(files.get(p), str) for p in (MAIN, PART)
    ):
        raise ValueError("UNKNOWN: matrices no legibles")
    found = {}
    for path in (MAIN, PART):
        for line in files[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in IDS:
                continue
            key = match.group(1)
            expected_path, source, fragment, _ = CLAUSES[key]
            cells = tuple(v.strip() for v in line.strip().strip("|").split("|"))
            if (key in found or path != expected_path or len(cells) != 6
                    or cells[0] != key or f"{source}:L" not in cells[1]
                    or fragment not in cells[2] or cells[3] != "NO_VERIFICABLE"
                    or not all(f"{x}=unknown" in cells[4].lower()
                               for x in ("offline", "live", "operando"))
                    or "Falta contraste" not in cells[4]
                       and "Fuente normativa sin verificación" not in cells[4]
                    or "/issues/1" not in cells[5]):
                raise ValueError("UNKNOWN: fila incompleta o promovida " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: faltan filas de cobertura")
    return found


@lru_cache(maxsize=12)
def php_scenario(group: str, case: str) -> dict:
    if case not in SCENARIOS.get(group, ()):
        raise AssertionError("UNKNOWN: fixture no permitida")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI ausente")
    argv = [php, str(ROOT / SCRIPTS[group])]
    if group == "verified":
        argv.append(case)
    completed = subprocess.run(
        argv, cwd=ROOT, capture_output=True, text=True,
        check=False, timeout=35,
    )
    if completed.returncode != 0 or completed.stderr.strip():
        raise AssertionError("UNKNOWN: fixture PHP falló")
    try:
        output = json.loads(completed.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: JSON de fixture inválido") from exc
    if not isinstance(output, dict) or not output:
        raise AssertionError("UNKNOWN: fixture vacía")
    return output


@lru_cache(maxsize=1)
def proofs() -> dict:
    return {
        "structural": php_scenario("verified", "structural"),
        "server": php_scenario("verified", "server"),
        "copy": php_scenario("verified", "copy"),
        "readonly": php_scenario("verified", "readonly"),
        "secrets": php_scenario("verified", "secrets"),
        "decisions": php_scenario("verified", "decisions"),
        "focus": php_scenario("focus", "main"),
        "ui": php_scenario("ui", "main"),
    }


def witness(key: str, p: dict) -> bool:
    """Per-ID behavioral witness, not certification of operational readiness."""
    try:
        v = p["structural"]
        s = p["server"]
        c = p["copy"]
        r = p["readonly"]
        sec = p["secrets"]
        dec = p["decisions"]
        f = p["focus"]
        u = p["ui"]
        if key == "CB-1117":
            return (v["constructor_private"] is True
                    and v["raw_factory_absent"] is True
                    and v["array_is_context"] is False
                    and c["shape_is_context"] is False
                    and c["clone_blocked"] is True
                    and c["serialize_blocked"] is True)
        if key == "CB-1118":
            return (v["source_factory_absent"] is True
                    and v["malicious_source_cannot_mint"] is True
                    and s["source_call"] == {
                        "identityId": "identity-admin", "scope": "venture:alpha",
                        "capability": "hostinger.read", "now": 5000,
                    } and s["summary"]["scope"] == "venture:alpha")
        if key == "CB-1119":
            return (sec["sensitive_blocked"] is True
                    and sec["normal_allowed"] is True
                    and dec["allow"]["decision"] == "allow"
                    and dec["deny"]["decision"] == "deny"
                    and dec["owner"]["decision"] == "owner_decision_required")
        if key == "CB-1120":
            return (r["unchanged"] is True and r["source_calls"] == 1
                    and r["has_audit"] is False
                    and r["has_top_level_state"] is False)
        if key == "CB-1122":
            view = u["view"]
            actions = view["accessibility"]
            return (actions["desktop_drag"] is True
                    and actions["mobile_buttons"] is True
                    and actions["keyboard_buttons"] is True
                    and actions["drag_only"] is False
                    and all(item["actions"][move]["keyboard_focusable"] is True
                            and item["actions"][move]["focus_visible"] is True
                            and item["actions"][move]["requires_drag"] is False
                            for item in view["items"]
                            for move in ("move_up", "move_down")))
        if key == "CB-1123":
            a, b = u["desktop"], u["mobile"]
            failed = u["conflict"]
            return (all(set(x["save_request"]) ==
                        {"ordered_refs", "expected_version"}
                        and x["status"] == "ready" for x in (a, b))
                    and failed["status"] == "conflict"
                    and failed["save_request"] is None
                    and failed["requires_refresh"] is True
                    and failed["overwrite_on_conflict"] is False)
        if key == "CB-1124":
            return (f["low_cannot_win"] is True
                    and f["critical_protected"] is True)
        if key == "CB-1125":
            return (f["parallel_authority_absent"] is True
                    and "selected_key" not in f["first"]
                    and "policy_rank" not in f["first"])
        if key == "CB-1126":
            return (f["blocked"]["preferred_key"] == "work-b"
                    and f["unavailable"]["preferred_key"] == "work-b"
                    and u["view"]["items"][1]["dispatchable"] is False)
        if key == "CB-1127":
            first = f["first"]
            return (first["preferred_key"] == "work-b"
                    and first["focus_version"] == 1
                    and first["focus_position"] == 1
                    and first["focus_influenced"] is True
                    and first["preference_reason"] == "weekly_focus_tiebreak"
                    and bool(re.fullmatch(r"[0-9a-f]{64}", first["request_fingerprint"])))
        if key == "CB-1128":
            return (f["first"]["preferred_key"] == "work-b"
                    and f["second"]["preferred_key"] == "work-a"
                    and f["first"]["priority"] == f["second"]["priority"]
                    and u["clear"]["affects_running_work"] is False
                    and u["clear"]["save_request"]["ordered_refs"] == [])
        if key == "CB-1129":
            return (f["conflict_rejected"] is True
                    and f["history_150"]["version"] == 1
                    and f["history_250"]["version"] == 2
                    and u["conflict"]["save_request"] is None)
    except (KeyError, IndexError, TypeError, AttributeError):
        return False
    return False


def local_status(key: object, files: object, p: object) -> str:
    if (not isinstance(key, str) or key not in CLAUSES
            or not isinstance(files, dict) or set(files) != DOCUMENTS
            or not isinstance(p, dict) or set(p) != set(proofs_schema())):
        return UNKNOWN
    if any(not isinstance(files[path], str) or not files[path] for path in DOCUMENTS):
        return UNKNOWN
    if any(not isinstance(v, dict) or not v for v in p.values()):
        return UNKNOWN
    try:
        rows = matrix_rows(files)
    except ValueError:
        return UNKNOWN
    _, source, _, required = CLAUSES[key]
    if required not in files[source] or key not in rows:
        return UNKNOWN
    if key == "CB-1121":
        return GAP if "no ejecuta acciones de infraestructura" in files[ACCESS] else UNKNOWN
    if key == "CB-1130":
        return GAP if ("#625" in files[README]
                       and "D-059" in files[BACKUP]
                       and "no hay un " in files[README]) else UNKNOWN
    if key == "CB-1131":
        return GAP if ("solo hace GET" in files[GITHUB]
                       and "no conceden ejecución por inferencia" in files[README]
                       and files[INTENT].startswith("#")) else UNKNOWN
    return PARTIAL if witness(key, p) else UNKNOWN


def proofs_schema() -> tuple[str, ...]:
    return ("structural", "server", "copy", "readonly", "secrets",
            "decisions", "focus", "ui")


def attested_state(_key: object, _evidence: object = None) -> str:
    """No fixture or documentation proves production, CI, or authorization."""
    return UNKNOWN


class BrechasControlbot73Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one real behavioral assertion or explicit gap per matrix ID."""
        files, p = documents(), proofs()
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(CLAUSES), set(IDS))
        self.assertEqual(set(matrix_rows(files)), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                expected = PARTIAL if key in LOCAL else GAP
                self.assertEqual(local_status(key, files, p), expected)
                self.assertEqual(attested_state(key, p), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absent/duplicate/promoted rows or incomplete evidence deny."""
        files, p = documents(), proofs()
        for key in IDS:
            path = CLAUSES[key][0]
            line = next(x for x in files[path].splitlines()
                        if x.startswith("| " + key + " |"))
            for variant in (
                files[path].replace(line, "", 1),
                files[path] + "\n" + line,
                files[path].replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows({**files, path: variant})
                    self.assertEqual(local_status(key, {**files, path: variant}, p), UNKNOWN)
            source = CLAUSES[key][1]
            self.assertEqual(local_status(key, {**files, source: ""}, p), UNKNOWN)
        self.assertEqual(local_status("CB-9999", files, p), UNKNOWN)
        self.assertEqual(local_status("CB-1117", {}, p), UNKNOWN)
        self.assertEqual(local_status("CB-1117", files, {}), UNKNOWN)
        for name in proofs_schema():
            altered = {**p, name: {}}
            self.assertEqual(local_status("CB-1122", files, altered), UNKNOWN)
        for key in LOCAL:
            with self.subTest(invalid_fixture=key):
                destroyed = {**p, "focus": {"broken": True}}
                self.assertEqual(local_status(key, files, destroyed), UNKNOWN
                                 if key not in {"CB-1117", "CB-1118",
                                                "CB-1119", "CB-1120"} else
                                 local_status(key, files, p))
        self.assertFalse(witness("CB-1127", {**p, "focus": {"first": {}}}))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only fixed synthetic PHP scenarios and versioned documents."""
        this_file = (ROOT / "tests/test_brechas_controlbot_73.py").read_text(encoding="utf-8")
        tree = ast.parse(this_file)
        imported = {alias.name.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.Import) for alias in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {
            "requests", "urllib", "socket", "httpx", "playwright",
            "pymysql", "mysql", "sqlalchemy",
        })
        for group in SCRIPTS:
            self.assertTrue((ROOT / SCRIPTS[group]).is_file())
            self.assertIn(group, SCENARIOS)
        self.assertEqual(set(SCRIPTS), set(SCENARIOS))
        self.assertTrue((ROOT / BACKUP).is_file())
        self.assertTrue(all(attested_state(k, proofs()) == UNKNOWN for k in IDS))
        self.assertNotIn("DATABASE_URL", this_file)
        self.assertNotIn("APP_SECRET", this_file)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: docs-only obligations never become operational approval."""
        files, p = documents(), proofs()
        for key in IDS:
            self.assertIn(local_status(key, files, p), (PARTIAL, GAP))
            for purported in (
                None, True, {}, {"deploy": "success", "ci": "success"},
                {"owner": "synthetic", "approved": True},
                {"status": "VALIDATED_IN_PRODUCTION"},
                {"grants": ["fake"], "scope": "venture:alpha"},
            ):
                self.assertEqual(attested_state(key, purported), UNKNOWN)
        for key in ("CB-1121", "CB-1130", "CB-1131"):
            self.assertEqual(local_status(key, files, p), GAP)


if __name__ == "__main__":
    unittest.main()
