#!/usr/bin/env python3
"""#1071: 15 contratos sintéticos CB-0982..0996, sin atestar producción.

No cambia la matriz, autoriza B2/V3, verifica backups ni permite despliegues.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(982, 997))
UNKNOWN, LOCAL = "UNKNOWN", "CONTRATO_LOCAL"
MAIN, PART = "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-04.md"
DOC = {"r": "docs/recovery-center-web.md", "u": "docs/replay-ui-v1.md"}
SRC = {"w": "src/RecoveryCenterWebEntrypoint.php",
       "p": "src/RecoveryPortfolio.php", "u": "src/ReplayUi.php"}
SCRIPT = {"w": "tests/recovery_center_web_scenarios.php",
          "p": "tests/recovery_portfolio_scenarios.php",
          "u": "tests/replay_ui_scenarios.php"}
# id: document, anchor in actual documented contract, text in matrix row
CLAUSE = {
    "CB-0982": ("r", "503 genérico", "Un perfil ausente/roto"),
    "CB-0983": ("r", "servidores, bases de datos", "servidores, bases de datos"),
    "CB-0984": ("r", "no autoriza provisión", "no autoriza provisión"),
    "CB-0985": ("r", "reserva **V3**", "reserva **V3**"),
    "CB-0986": ("r", "deploy, secretos", "deploy, secretos"),
    "CB-0987": ("r", "seis perfiles locales JSON", "seis perfiles locales JSON"),
    "CB-0988": ("r", "`FactoryRunner`", "`FactoryRunner`"),
    "CB-0989": ("r", "`RecoveryPortfolio`", "`RecoveryPortfolio`"),
    "CB-0990": ("r", "RPO/RTO como **objetivos**", "RPO/RTO como **objetivos**"),
    "CB-0991": ("r", "siempre es `UNKNOWN`", "siempre es `UNKNOWN`"),
    "CB-0992": ("r", "**No confirma backups**", "**No confirma backups**"),
    "CB-0993": ("r", "`configured`", "`configured`"),
    "CB-0994": ("u", "Proyección HTML read-only", "Proyección HTML read-only"),
    "CB-0995": ("u", "Categorías cerradas", "Categorías cerradas"),
    "CB-0996": ("u", "skipped, startup_failure, failure y success", "kinds skipped"),
}
GAPS = {"CB-0984", "CB-0985", "CB-0986"}  # permiso humano/merge/deploy externo
FILES = {MAIN, PART, *DOC.values(), *SRC.values(), *SCRIPT.values()}
PROJECTS = ("autofactory", "brvtal", "condor", "controlbot",
            "factory", "factoryrunner", "grindflow")


def sources() -> dict[str, str]:
    return {f: (ROOT / f).read_text(encoding="utf-8") for f in FILES}


def rows(data: object) -> dict[str, tuple[str, ...]]:
    if type(data) is not dict or any(type(data.get(p)) is not str or not data[p]
                                      for p in (MAIN, PART)):
        raise ValueError("UNKNOWN: matrix missing")
    found = {}
    for path in (MAIN, PART):
        for line in data[path].splitlines():
            match = re.match(r"^\s*\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in IDS:
                continue
            ident = match.group(1)
            fields = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            group, _, anchor = CLAUSE[ident]
            if (ident in found or len(fields) != 6 or fields[0] != ident
                    or DOC[group] not in fields[1] or anchor not in fields[2]
                    or fields[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos." not in fields[4]
                    or any(f"{k}=unknown" not in fields[4].lower()
                           for k in ("offline", "live", "operando"))
                    or (path == MAIN) != (ident == "CB-0994")):
                raise ValueError("UNKNOWN: invalid row " + ident)
            found[ident] = fields
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows")
    return found


@lru_cache(maxsize=32)
def scenario(group: str, case: str, category: str = "all") -> object:
    cases = {
        "w": {"owner", "guards", "invalid-profile"},
        "p": {"baseline", "stale", "cross-project", "sensitive",
              "extra-project", "bad-shape", "unexpected-runner"},
        "u": {"filters", "states", "explicit", "contradictory", "escape", "secret"},
    }
    if (group not in cases or case not in cases[group]
            or category not in {"all", "code", "ci", "security"}):
        raise ValueError("UNKNOWN: unsupported fixture")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP missing")
    command = [php, str(ROOT / SCRIPT[group]), case]
    if group == "u":
        command.append(category)
    run = subprocess.run(command, cwd=ROOT, capture_output=True,
                         text=True, timeout=30, check=False)
    if run.returncode or run.stderr.strip():
        raise AssertionError(f"UNKNOWN: fixture failed {group}/{case}")
    if group == "u" and case not in {"contradictory", "secret"}:
        if "<!doctype html>" not in run.stdout:
            raise AssertionError("UNKNOWN: no HTML")
        return run.stdout
    try:
        value = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: no JSON") from exc
    if type(value) is not dict or not value:
        raise AssertionError("UNKNOWN: empty JSON")
    return value


def exercises(ident: str, source: dict[str, str]) -> bool:
    owner = lambda: scenario("w", "owner")
    projects = lambda: scenario("p", "baseline")["projects"]
    current = lambda: next(p for p in projects() if p["project"] == "controlbot")
    if ident == "CB-0982":
        responses = scenario("w", "invalid-profile")
        return (set(responses) == {"missing", "invalid"}
                and all(x["status"] == 503 and x["body"] == "Service unavailable.\n"
                        for x in responses.values()))
    if ident == "CB-0983":
        code = source[SRC["w"]].lower()
        return (owner()["status"] == 200
                and all(s not in code for s in
                        ("curl_", "new pdo", "mysqli", "shell_exec", "proc_open", "http://"))
                and "RPO objetivo" in owner()["body"])
    if ident in GAPS:
        return False  # No convertir el enunciado de autoridad en un pase sintético.
    if ident == "CB-0987":
        return (tuple(x["project"] for x in projects()) == PROJECTS
                and len(projects()) == 7
                and sum(p["profile_status"] == "configured" for p in projects()) == 6
                and "config/recovery" in source[SRC["w"]])
    if ident == "CB-0988":
        p = next(x for x in projects() if x["project"] == "factoryrunner")
        return (p["profile_status"] == "unknown" and p["dr_status"] == UNKNOWN
                and p["reason"] == "PROFILE_MISSING"
                and p["rpo_target_minutes"] is None
                and p["rto_target_minutes"] is None
                and scenario("p", "unexpected-runner")["rejected"] is True)
    if ident == "CB-0989":
        base = scenario("p", "baseline")
        return (base["execution"] is False and base["actions"] == []
                and all(scenario("p", name)["rejected"] is True for name in
                        ("cross-project", "sensitive", "extra-project", "bad-shape")))
    if ident == "CB-0990":
        return (current()["rpo_target_minutes"] == 15
                and current()["rto_target_minutes"] == 60
                and current()["dr_status"] == UNKNOWN
                and all(x in owner()["body"] for x in ("RPO objetivo", "RTO objetivo")))
    if ident == "CB-0991":
        return (len(projects()) == 7
                and all(p["dr_status"] == UNKNOWN for p in projects())
                and all(p["reason"] in ("PROFILE_MISSING", "RECOVERY_HEALTH_MISSING")
                        for p in projects()) and "UNKNOWN" in owner()["body"])
    if ident == "CB-0992":
        return (all("backup_receipt" not in p and "observed_rpo" not in p
                    and p["dr_status"] == UNKNOWN for p in projects())
                and "No confirma backups" in source[DOC["r"]])
    if ident == "CB-0993":
        stale = scenario("p", "stale")["projects"]
        bad = next(p for p in stale if p["project"] == "controlbot")
        return (current()["profile_status"] == "configured"
                and bad["profile_status"] == "unknown"
                and bad["dr_status"] == UNKNOWN
                and bad["rpo_target_minutes"] is None)
    if ident == "CB-0994":
        html = scenario("u", "filters")
        code = source[SRC["u"]]
        return (all(x not in html.lower() for x in ("<form", "<button", "<script"))
                and "Replay verificable" in html
                and "AgentReplayLifecycle::build" in code
                and "AgentReplayCore::event" in code)
    if ident == "CB-0995":
        html = scenario("u", "filters", "ci")
        return (all(f"?category={name}" in html for name in
                    ("all", "code", "ci", "coordination", "decisions",
                     "production", "security"))
                and "CI check evidence" in html
                and "Code commit evidence" not in html
                and scenario("u", "contradictory")["rejected"] is True
                and "Explicit security category" in scenario("u", "explicit", "security")
                and "Explicit security category" not in scenario("u", "explicit", "ci"))
    if ident == "CB-0996":
        html = scenario("u", "states", "ci")
        other = scenario("u", "states", "security")
        return (all(x in html for x in ("skipped", "startup_failure",
                                       "failure", "success"))
                and "unknown" in html
                and "conflicting_evidence" in html
                and "step_failure" not in html
                and "CI check evidence" not in other)
    return False


def verdict(ident: object, data: object, proof: object = True) -> str:
    if (type(ident) is not str or ident not in IDS
            or type(data) is not dict or proof is not True):
        return UNKNOWN
    try:
        if set(data) != FILES or any(type(data[p]) is not str or not data[p]
                                    for p in FILES):
            return UNKNOWN
        rows(data)
        group, clause, _ = CLAUSE[ident]
        if clause not in data[DOC[group]] or ident in GAPS:
            return UNKNOWN
        if not exercises(ident, data):
            return UNKNOWN
    except (AssertionError, ValueError, TypeError, KeyError,
            IndexError, AttributeError, OSError, subprocess.TimeoutExpired):
        return UNKNOWN
    return LOCAL


def operational_state(_ident: object, _claim: object = None) -> str:
    """La política externa no se acredita por fixtures locales."""
    return UNKNOWN


class BrechasControlbot64Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cobertura nominal e independiente de las quince filas."""
        data = sources()
        indexed = rows(data)
        self.assertEqual(15, len(IDS))
        self.assertEqual(set(IDS), set(CLAUSE))
        self.assertEqual(set(indexed), set(IDS))
        for ident in IDS:
            with self.subTest(id=ident):
                self.assertEqual(verdict(ident, data), UNKNOWN if ident in GAPS else LOCAL)
                self.assertEqual(indexed[ident][3], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        """AC-02: falta, duplicación, promoción o alteración nunca es GREEN."""
        data = sources()
        for ident in IDS:
            with self.subTest(id=ident):
                path = MAIN if ident == "CB-0994" else PART
                row = next(x for x in data[path].splitlines()
                           if x.startswith(f"| {ident} |"))
                for modified in (data[path].replace(row, "", 1),
                                 data[path] + "\n" + row,
                                 data[path].replace(
                                     row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                    self.assertEqual(UNKNOWN, verdict(
                        ident, {**data, path: modified}))
                group, clause, _ = CLAUSE[ident]
                doc = DOC[group]
                self.assertEqual(UNKNOWN, verdict(
                    ident, {**data, doc: data[doc].replace(clause, "")}))
                self.assertEqual(UNKNOWN, verdict(ident, data, proof=None))
        self.assertEqual(UNKNOWN, verdict("CB-9999", data))
        self.assertEqual(UNKNOWN, verdict("CB-0982", {}))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("u", "execute")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            rows({MAIN: "missing"})

    def test_sin_datos_reales(self) -> None:
        """AC-03: script sin red; fixtures existentes son sintéticos."""
        data = sources()
        self.assertEqual(set(data), FILES)
        for name in FILES:
            with self.subTest(path=name):
                path = Path(name)
                self.assertFalse(path.is_absolute())
                self.assertNotIn("..", path.parts)
                self.assertTrue((ROOT / path).is_file())
        own = (ROOT / "tests/test_brechas_controlbot_64.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for n in ast.walk(tree)
                   if isinstance(n, ast.Import) for alias in n.names}
        imports |= {(n.module or "").split(".")[0] for n in ast.walk(tree)
                    if isinstance(n, ast.ImportFrom)}
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json",
                                      "pathlib", "re", "shutil", "subprocess", "unittest"})
        self.assertNotIn("do-not-echo", json.dumps(scenario("p", "baseline")))
        escaped = scenario("u", "escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", escaped)
        self.assertNotIn("<script>alert(1)</script>", escaped)
        self.assertTrue(scenario("u", "secret")["rejected"])
        self.assertEqual(UNKNOWN, operational_state("CB-0984", {"approved": True}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ninguna fila se promueve a evidencia de backup/CI/live."""
        data = sources()
        indexed = rows(data)
        for ident in IDS:
            with self.subTest(id=ident):
                self.assertEqual("NO_VERIFICABLE", indexed[ident][3])
                for claim in (None, {}, {"ci": "success", "sha": "a" * 40},
                              {"factory_recovery_health": "GREEN"},
                              {"backup_receipt": "VALIDATED_IN_PRODUCTION"},
                              {"owner_approval": True, "deploy": True}):
                    self.assertEqual(UNKNOWN, operational_state(ident, claim))
                if ident in GAPS:
                    self.assertEqual(UNKNOWN, verdict(ident, data))
        self.assertEqual(UNKNOWN, scenario("p", "baseline")["projects"][5]["dr_status"])


if __name__ == "__main__":
    unittest.main()
