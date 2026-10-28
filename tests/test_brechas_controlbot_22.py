#!/usr/bin/env python3
"""ControlBot #1029: fifteen offline contrasts for CB-0319..CB-0333.

Only synthetic fixtures and versioned source files count as local evidence.
The matrix has three rows in this interval; all other rows remain UNKNOWN.
A local pass is not evidence of a deploy, approved probe, live, or CI.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(319, 334))
UNKNOWN = "UNKNOWN"
OFFLINE = "OFFLINE_TESTED"
PRESENT_ROWS = {"CB-0319", "CB-0323", "CB-0329"}
BUILD = "test_factory_deploy_build_adapter.py"
PLANNED = "test_planned_state_adoption.py"
HEALTH = "test_orchestrator_public_health.py"
DOC_BUILD = "docs/factory-deploy-build-adapter.md"
DOC_PLANNED = "docs/factory-planned-state-adoption.md"
DOC_HEALTH = "docs/factory-public-health-observability.md"

# Every ID is tied to an explicit source clause and a runnable regression.
# The three existing suites exercise synthetic Git/PHP/HTTP fixtures only.
CLAUSES = {
    "CB-0319": (DOC_BUILD, "PHASE=construccion", BUILD,
                "test_rejects_missing_provenance_or_nonconstruction_phase"),
    "CB-0320": (DOC_BUILD, "TAR/GZIP es reproducible", BUILD,
                "test_reproducible_archive_and_exact_sha_manifest"),
    "CB-0321": (DOC_BUILD, "ops/factory/backup", BUILD,
                "test_no_live_activation_secrets_or_remote_execution"),
    "CB-0322": (DOC_BUILD, "smoke HTTPS del deploy", BUILD,
                "test_no_live_activation_secrets_or_remote_execution"),
    "CB-0323": (DOC_PLANNED, "Orquestador es una vista privada", PLANNED,
                "test_planned_is_separate_from_blocked_and_not_dispatchable"),
    "CB-0324": (DOC_PLANNED, "GET autenticado por el servidor", PLANNED,
                "test_legacy_states_and_private_read_only_ui_stay_compatible"),
    "CB-0325": (DOC_PLANNED, "contadores", PLANNED,
                "test_planned_is_separate_from_blocked_and_not_dispatchable"),
    "CB-0326": (DOC_PLANNED, "representa otro contrato heredado", PLANNED,
                "test_planned_is_separate_from_blocked_and_not_dispatchable"),
    "CB-0327": (DOC_PLANNED, "deben terminar", PLANNED,
                "test_unknown_pagination_or_label_provenance_cannot_infer_ready"),
    "CB-0328": (DOC_PLANNED, "Fixtures deterministas con PHP", PLANNED,
                "test_unknown_pagination_or_label_provenance_cannot_infer_ready"),
    "CB-0329": (DOC_HEALTH, "Observaciones", HEALTH,
                "test_four_repos_show_grounded_code_latency_timestamp_and_freshness"),
    "CB-0330": (DOC_HEALTH, "tres piezas: FactoryPublicHealthObserver", HEALTH,
                "test_four_repos_show_grounded_code_latency_timestamp_and_freshness"),
    "CB-0331": (DOC_HEALTH, "dos mapas independientes", HEALTH,
                "test_four_repos_show_grounded_code_latency_timestamp_and_freshness"),
    "CB-0332": (DOC_HEALTH, "dominios reservados example.org", HEALTH,
                "test_http_transport_rejects_ssrf_redirect_credentials_and_unbounded_io"),
    "CB-0333": (DOC_HEALTH, "No transportar Authorization, Cookie", HEALTH,
                "test_read_only_private_ui_does_not_persist_body_cookies_or_secrets"),
}
EXPECTED_COUNTS = {BUILD: 7, PLANNED: 4, HEALTH: 5}


def matrix_states(raw: str) -> dict[str, str]:
    """Duplicates, missing rows, malformed cells and promotion all fail closed."""
    rows = {key: [] for key in IDS}
    for line in raw.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if cells and cells[0] in rows:
            rows[cells[0]].append(cells)
    return {
        key: ("NO_VERIFICABLE" if len(found) == 1 and len(found[0]) == 6
              and all(found[0]) and found[0][3] == "NO_VERIFICABLE"
              and "UNKNOWN" in found[0][4] else UNKNOWN)
        for key, found in rows.items()
    }


def has_executable_test(source: str, method: str) -> bool:
    """Named stubs or comments cannot count as an executable assertion."""
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return False
    found = [node for node in ast.walk(tree)
             if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
             and node.name == method]
    return (len(found) == 1 and
            any(isinstance(node, ast.Call) for node in ast.walk(found[0])))


def clause_status(clause: str, docs: dict[str, str], tests: dict[str, str],
                  inventory: dict[str, str], executed: set[str]) -> str:
    entry = CLAUSES.get(clause)
    if entry is None:
        return UNKNOWN
    document, phrase, suite, method = entry
    if (phrase not in docs.get(document, "")
            or inventory.get(clause) not in ("NO_VERIFICABLE", UNKNOWN)
            or not has_executable_test(tests.get(suite, ""), method)):
        return UNKNOWN
    return OFFLINE if suite in executed else UNKNOWN


def operational_state(_clause: str, _claimed: object = None) -> str:
    """Never infer production, CI exact-SHA, or human approval from local tests."""
    return UNKNOWN


def isolated_environment() -> dict[str, str]:
    """Prevent inherited tokens and passwords from reaching subprocess fixtures."""
    allow = ("PATH", "HOME", "LANG", "LC_ALL", "TZ", "TMPDIR", "SYSTEMROOT")
    return {name: os.environ[name] for name in allow if name in os.environ}


@lru_cache(maxsize=1)
def run_offline_suites() -> set[str]:
    """Execute real existing build, orchestration and health regression suites."""
    if not shutil.which("git") or not shutil.which("php"):
        raise AssertionError(f"{UNKNOWN}: git/php runner unavailable")
    verified = set()
    for suite, minimum in EXPECTED_COUNTS.items():
        run = subprocess.run(
            [sys.executable, "-m", "unittest", "discover", "-s", "tests",
             "-p", suite, "-q"], cwd=ROOT, env=isolated_environment(),
            capture_output=True, text=True, timeout=180, check=False,
        )
        transcript = run.stdout + run.stderr
        count = re.search(r"Ran (\d+) tests?", transcript)
        if (run.returncode != 0 or count is None
                or int(count.group(1)) < minimum
                or re.search(r"\bskipped\s*=\s*[1-9]", transcript)):
            raise AssertionError(f"{suite}: {UNKNOWN}\n" + transcript[-1800:])
        verified.add(suite)
    return verified


class BrechasControlbot22Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each of the 15 contracts has executed synthetic evidence."""
        self.assertEqual(set(IDS), set(CLAUSES))
        docs = {name: (ROOT / name).read_text(encoding="utf-8")
                for name in {entry[0] for entry in CLAUSES.values()}}
        tests = {name: (ROOT / "tests" / name).read_text(encoding="utf-8")
                 for name in EXPECTED_COUNTS}
        inventory = matrix_states(
            (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        executed = run_offline_suites()
        self.assertEqual(executed, set(EXPECTED_COUNTS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(
                    OFFLINE, clause_status(clause, docs, tests, inventory, executed),
                    f"{clause}: missing executable evidence")

    def test_falla_cerrado(self) -> None:
        """AC-02: no source, missing test, missing run or promotion => UNKNOWN."""
        raw = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        inventory = matrix_states(raw)
        for clause, (doc, phrase, suite, method) in CLAUSES.items():
            with self.subTest(clause=clause):
                source = (ROOT / "tests" / suite).read_text(encoding="utf-8")
                self.assertEqual(UNKNOWN, clause_status(
                    clause, {doc: phrase}, {suite: f"def {method}():\n    pass\n"},
                    inventory, {suite}))
                self.assertEqual(UNKNOWN, clause_status(
                    clause, {doc: phrase}, {suite: source}, inventory, set()))
                self.assertEqual(UNKNOWN, clause_status(
                    clause, {}, {suite: source}, inventory, {suite}))
                self.assertEqual(UNKNOWN, clause_status(
                    clause, {doc: phrase}, {suite: source},
                    {clause: "CUBIERTO"}, {suite}))
        self.assertFalse(has_executable_test(
            "def fake():\n    # self.assertTrue(True)\n    pass\n", "fake"))
        self.assertFalse(has_executable_test("def fake(:\n pass", "fake"))
        row = next(line for line in raw.splitlines()
                   if line.startswith("| CB-0319 |"))
        self.assertEqual(UNKNOWN, matrix_states(raw + "\n" + row)["CB-0319"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: all inputs local, subprocesses isolated from credentials."""
        self.assertEqual(set(EXPECTED_COUNTS),
                         {entry[2] for entry in CLAUSES.values()})
        for name in EXPECTED_COUNTS:
            self.assertTrue((ROOT / "tests" / name).is_file())
        for name in {entry[0] for entry in CLAUSES.values()}:
            self.assertTrue((ROOT / name).is_file())
        env = isolated_environment()
        for name in ("GITHUB_TOKEN", "GITHUB_API_TOKEN", "DB_PASSWORD",
                     "HOSTINGER_API_TOKEN"):
            self.assertNotIn(name, env)
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            own, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("https:" + "//", own)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: sparse matrix and local successes never authorize live."""
        states = matrix_states(
            (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        self.assertEqual({k for k, v in states.items()
                          if v == "NO_VERIFICABLE"}, PRESENT_ROWS)
        self.assertEqual({k for k, v in states.items()
                          if v == UNKNOWN}, set(IDS) - PRESENT_ROWS)
        for clause in IDS:
            with self.subTest(clause=clause):
                for claim in (None, {}, True, "success", "green",
                              {"ci": "success"}, {"merge": "claimed"},
                              {"health": 200}, {"offline": OFFLINE},
                              {"owner_approval": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
