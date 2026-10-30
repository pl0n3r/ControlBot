#!/usr/bin/env python3
"""ControlBot #1074: CB-1027..1041, synthetic contracts; live remains UNKNOWN."""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row, corrupted_e4_row
import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(1027, 1042))
UNKNOWN, LOCAL = "UNKNOWN", "CONTRATO_LOCAL"
MAIN, PART = "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-04.md"
DOCS = {
    "cron": "docs/runbooks/orchestrator-snapshot-cron.md",
    "commit": "docs/scheduler-assignment-commit-v1.md",
    "plan": "docs/scheduler-assignment-plan-v1.md",
}
SRC = {
    "cron": "src/FactoryOrchestratorSnapshotCron.php",
    "collector": "src/FactoryOrchestratorEvidenceCollector.php",
    "web": "src/FactoryOrchestratorWebEntrypoint.php",
    "commit": "src/SchedulerAssignmentCommit.php",
    "plan": "src/SchedulerAssignmentPlan.php",
}
SCRIPTS = {
    "collector": "scripts/orchestrator-evidence-collector.php",
    "cron": "scripts/orchestrator-snapshot-cron.php",
}
FIXTURES = {
    "refresh": "tests/factory_orchestrator_snapshot_refresh_scenarios.php",
    "collector": "tests/factory_orchestrator_evidence_collector_scenarios.php",
    "commit": "tests/scheduler_assignment_commit_scenarios.php",
    "plan": "tests/scheduler_assignment_plan_scenarios.php",
}
CLAUSES = {
    "CB-1027": ("cron", "internal_error"),
    "CB-1028": ("cron", "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS=1"),
    "CB-1029": ("cron", "Los despliegues automáticos de Hostinger"),
    "CB-1030": ("cron", "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"),
    "CB-1031": ("cron", "El productor offline y el lector web"),
    "CB-1032": ("cron", "El colector usa un máximo de 40 requests reales"),
    "CB-1033": ("cron", "La medición read-only de #720"),
    "CB-1034": ("cron", "Configura **cada 5 minutos**"),
    "CB-1035": ("commit", "CommitSet CAS lógico"),
    "CB-1036": ("commit", "PreparedSchedulerAssignmentPlan::fromSelection()"),
    "CB-1037": ("commit", "En commit se vuelven a normalizar"),
    "CB-1038": ("commit", "Los cuatro writes son un único conjunto"),
    "CB-1039": ("commit", "Se rechazan drift"),
    "CB-1040": ("plan", "Antes de producir salida vuelve a ligar"),
    "CB-1041": ("plan", "Los IDs se reciben como entrada"),
}
FILES = {MAIN, PART, ".htaccess", *DOCS.values(),
         *SRC.values(), *SCRIPTS.values(), *FIXTURES.values()}
HISTORICAL_GAPS = {"CB-1033"}
PRIVATE = "/home/u151692719/domains/control.condorapp.com.co/private/orchestrator-live.json"
PHP_PROBE = r"""
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$unknown=FactoryOrchestratorEvidenceCollector::diagnosticFor(
    new RuntimeException("private/secret=never-echo"));
$known=FactoryOrchestratorEvidenceCollector::diagnosticFor(
    new RuntimeException("github http status 403 path /repos/pl0n3r/Factory/issues"));
echo json_encode(compact("unknown","known"),JSON_THROW_ON_ERROR);
"""

def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}

def coverage_rows(data: object) -> dict[str, tuple[str, ...]]:
    if type(data) is not dict or not all(type(data.get(p)) is str
                                         for p in (MAIN, PART)):
        raise ValueError("UNKNOWN: missing matrix")
    found = {}
    for path in (MAIN, PART):
        for line in data[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            ident = match.group(1)
            cells = tuple(piece.strip() for piece in line.strip("|").split("|"))
            group, anchor = CLAUSES[ident]
            if (ident in found or len(cells) != 6 or cells[0] != ident
                    or DOCS[group] not in cells[1] or anchor not in cells[2]
                    or not credible_e4_coverage_row(cells)
                    or "/issues/1" not in cells[5]
                    or (path == MAIN) != (ident in {"CB-1035", "CB-1040"})):
                raise ValueError("UNKNOWN: malformed row " + ident)
            found[ident] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows")
    return found

@lru_cache(maxsize=25)
def scenario(group: str, name: str) -> dict:
    allowed = {
        "refresh": {"symlink_parent", "stale_fallback", "fail_closed", "success"},
        "collector": {"canonical", "failure", "over-400", "full-pull-page"},
        "commit": {"cas", "ownership", "binding", "atomic", "pure", "deterministic"},
        "plan": {"selection", "target", "binding", "ownership", "pure", "deterministic"},
    }
    if name not in allowed.get(group, set()):
        raise ValueError("UNKNOWN: scenario not allowlisted")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    proc = subprocess.run(
        [php, str(ROOT / FIXTURES[group]), name], cwd=ROOT,
        capture_output=True, text=True, check=False, timeout=40,
    )
    if proc.returncode != 0 or proc.stderr.strip():
        raise AssertionError("UNKNOWN: PHP scenario failed: " + group + "/" + name)
    try:
        value = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: malformed PHP result") from exc
    if type(value) is not dict:
        raise AssertionError("UNKNOWN: PHP result not an object")
    return value

@lru_cache(maxsize=1)
def diagnostic_probe() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    proc = subprocess.run([php, "-r", PHP_PROBE], cwd=ROOT,
                          capture_output=True, text=True, timeout=20)
    if proc.returncode or proc.stderr.strip():
        raise AssertionError("UNKNOWN: diagnostic probe failed")
    return json.loads(proc.stdout)

@lru_cache(maxsize=1)
def diagnostic_cli() -> dict:
    """Isolated CLI failure; never set collector enable or a GitHub token."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    with tempfile.TemporaryDirectory(prefix="cb1074-") as work:
        root = Path(work)
        snapshot = root / "orchestrator-live.json"
        env = {**os.environ,
               "CONTROLBOT_ORCHESTRATOR_CRON_ENABLED": "1",
               "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH": str(snapshot),
               "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH": str(root / "missing.json"),
               "CONTROLBOT_ORCHESTRATOR_COLLECTOR_RESULT": "failed",
               "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS": "1"}
        env.pop("CONTROLBOT_GITHUB_READ_TOKEN_FILE", None)
        env.pop("CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED", None)
        on = subprocess.run([php, str(ROOT / SCRIPTS["cron"])], cwd=ROOT,
                            env=env, capture_output=True, text=True, timeout=25)
        env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS"] = "0"
        off = subprocess.run([php, str(ROOT / SCRIPTS["cron"])], cwd=ROOT,
                             env=env, capture_output=True, text=True, timeout=25)
        return {"on_code": on.returncode, "off_code": off.returncode,
                "on": on.stderr, "off": off.stderr,
                "target": str(snapshot), "on_stdout": on.stdout}

def observations() -> dict[str, dict]:
    return {
        "diagnostic": diagnostic_probe(),
        "cli": diagnostic_cli(),
        "symlink": scenario("refresh", "symlink_parent"),
        "stale": scenario("refresh", "stale_fallback"),
        "collector": scenario("collector", "canonical"),
        "large": scenario("collector", "over-400"),
        "full": scenario("collector", "full-pull-page"),
        "collector_failure": scenario("collector", "failure"),
        "cas": scenario("commit", "cas"),
        "owner": scenario("commit", "ownership"),
        "binding": scenario("commit", "binding"),
        "atomic": scenario("commit", "atomic"),
        "commit_pure": scenario("commit", "pure"),
        "commit_deterministic": scenario("commit", "deterministic"),
        "selection": scenario("plan", "selection"),
        "target": scenario("plan", "target"),
        "plan_binding": scenario("plan", "binding"),
        "plan_pure": scenario("plan", "pure"),
        "plan_deterministic": scenario("plan", "deterministic"),
    }

def local_evidence(ident: str, proof: object, data: dict[str, str]) -> bool:
    if (type(proof) is not dict or
            any(type(value) is not dict for value in proof.values())):
        return False
    try:
        diag, cli = proof["diagnostic"], proof["cli"]
        sym, stale = proof["symlink"], proof["stale"]
        collector, large, full = proof["collector"], proof["large"], proof["full"]
        cas, owner, bind, atomic = (proof[x] for x in
                                   ("cas", "owner", "binding", "atomic"))
        selection, target, plan = (proof[x] for x in
                                   ("selection", "target", "plan_binding"))
        if ident == "CB-1027":
            return (diag["unknown"] == {"code": "internal_error"}
                    and diag["known"] == {"code": "http_status_403",
                        "path": "/repos/pl0n3r/Factory/issues", "status": 403})
        if ident == "CB-1028":
            names = re.findall(r"\b(dir_[a-z_]+)=", cli["on"])
            return (cli["on_code"] == cli["off_code"] == 70
                    and set(names) == {"dir_exists", "dir_writable",
                                       "dir_owner_match", "dir_mode"}
                    and len(names) == 4 and "dir_exists=1" in cli["on"]
                    and not any(f"{key}=" in cli["off"] for key in names)
                    and cli["target"] not in cli["on"] and "secret" not in cli["on"])
        if ident == "CB-1029":
            return (sym["alias_error"] != "NO_FAILURE"
                    and sym["dotdot_error"] != "NO_FAILURE"
                    and sym["public_error"] != "NO_FAILURE"
                    and sym["public_html_error"] != "NO_FAILURE"
                    and sym["nothing_in_served"] is True
                    and sym["private_ok"] is True)
        if ident == "CB-1030":
            source = data[SRC["web"]]
            return ("validConfiguredPath" in source
                    and "hasSymlinkComponent" in source
                    and "self::unknown($now)" in source
                    and "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH" in source
                    and "SetEnv CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH " + PRIVATE
                       in data[".htaccess"])
        if ident == "CB-1031":
            return (PRIVATE in data[DOCS["cron"]]
                    and "SetEnv CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH " + PRIVATE
                       in data[".htaccess"]
                    and "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH" in
                       data[SCRIPTS["cron"]]
                    and stale["status"] == "stale"
                    and stale["visible_stale"] is True)
        if ident == "CB-1032":
            r = collector["result"]
            return (0 < r["requests"] <= 40
                    and r["requests"] == len(collector["calls"])
                    and 0 < r["download_bytes"] <= 8_000_000
                    and 0 < r["evidence_bytes"] <= 2_000_000
                    and proof["collector_failure"]["previous"] == '{"old":true}'
                    and large["run"]["requests"] <= 40
                    and large["summary"]["truncated"] is True)
        if ident == "CB-1033":
            return False  # historical bytes are not new live measurements
        if ident == "CB-1034":
            lines = [line for line in data[DOCS["cron"]].splitlines()
                     if line.startswith("*/5 * * * *")]
            return (len(lines) == 1 and
                    all(token in lines[0] for token in
                        ('/opt/alt/php85/usr/bin/php',
                         'scripts/orchestrator-evidence-collector.php',
                         'scripts/orchestrator-snapshot-cron.php',
                         'CONTROLBOT_GITHUB_READ_TOKEN_FILE=',
                         'orchestrator-snapshot-cron.log'))
                    and lines[0].index("scripts/orchestrator-evidence-collector.php")
                        < lines[0].index("scripts/orchestrator-snapshot-cron.php"))
        if ident == "CB-1035":
            commit = cas["valid"]
            return (commit["version"] == 1
                    and set(commit) == {"version", "policy_ref", "expected",
                                       "writes", "plan_fingerprint", "commit_fingerprint"}
                    and "cas_sha256" in commit["expected"]
                    and "applied" not in commit)
        if ident == "CB-1036":
            return (cas["fabricated"] is True
                    and selection["rejected"]["fabricated_array"] is True
                    and "ValidatedSchedulerSelection" in data[SRC["plan"]]
                    and "PreparedSchedulerAssignmentPlan" in data[SRC["commit"]])
        if ident == "CB-1037":
            return (all(cas[key] is True for key in
                        ("work_drift", "session_drift", "agent_drift", "clock_stale"))
                    and "sessionHealth" in data[SRC["commit"]])
        if ident == "CB-1038":
            return (atomic["write_keys"] ==
                    ["reservation", "work_item", "session", "assignment"]
                    and atomic["has_partial"] is False
                    and atomic["top_keys"] ==
                    ["version", "policy_ref", "expected", "writes",
                     "plan_fingerprint", "commit_fingerprint"])
        if ident == "CB-1039":
            return (all(owner.values())
                    and all(cas[key] is True for key in
                            ("fabricated", "work_drift", "session_drift",
                             "agent_drift", "clock_stale")))
        if ident == "CB-1040":
            return (all(selection["rejected"].values())
                    and all(target[key] is True for key in
                            ("stale", "busy", "account", "agent_identity", "capability"))
                    and plan["work_item"]["state"] == "assigned")
        if ident == "CB-1041":
            d, p = proof["commit_deterministic"], proof["plan_deterministic"]
            return (d["same"] is True and d["same_fingerprint"] is True
                    and p["same"] is True and p["fingerprint_same"] is True
                    and p["work_cas_changed"] is True
                    and p["session_cas_changed"] is True
                    and proof["plan_pure"]["hits"] == []
                    and proof["commit_pure"]["hits"] == [])
    except (KeyError, IndexError, TypeError, ValueError, AttributeError):
        return False
    return False

def verdict(ident: object, data: object, evidence: object) -> str:
    if ident not in IDS or type(data) is not dict or set(data) != FILES:
        return UNKNOWN
    if any(type(value) is not str or not value.strip() for value in data.values()):
        return UNKNOWN
    try:
        rows = coverage_rows(data)
    except ValueError:
        return UNKNOWN
    group, anchor = CLAUSES[ident]
    if anchor not in data[DOCS[group]] or not credible_e4_coverage_row(rows[ident]):
        return UNKNOWN
    return LOCAL if local_evidence(ident, evidence, data) else UNKNOWN

def operational_status(_ident: object, _evidence: object = None) -> str:
    """Simulated tests never attest cron installation, production or authorization."""
    return UNKNOWN

class BrechasControlbot67Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, proof = sources(), observations()
        rows = coverage_rows(data)
        self.assertEqual(set(IDS), set(rows))
        self.assertEqual(set(IDS), set(CLAUSES))
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertTrue(credible_e4_coverage_row(rows[ident]))
                expected = UNKNOWN if ident in HISTORICAL_GAPS else LOCAL
                self.assertEqual(expected, verdict(ident, data, proof))
    def test_falla_cerrado(self) -> None:
        data, proof = sources(), observations()
        for ident in IDS:
            with self.subTest(ident=ident):
                path = MAIN if ident in {"CB-1035", "CB-1040"} else PART
                row = next(line for line in data[path].splitlines()
                           if line.startswith("| " + ident + " |"))
                for broken in (data[path].replace(row, "", 1),
                               data[path] + "\n" + row,
                               data[path].replace(
                                   row, corrupted_e4_row(row), 1)):
                    self.assertEqual(UNKNOWN, verdict(
                        ident, {**data, path: broken}, proof))
                doc, anchor = DOCS[CLAUSES[ident][0]], CLAUSES[ident][1]
                self.assertEqual(UNKNOWN, verdict(
                    ident, {**data, doc: data[doc].replace(anchor, "")}, proof))
                self.assertEqual(UNKNOWN, verdict(ident, data, None))
                self.assertEqual(UNKNOWN, verdict(
                    ident, {**data, SRC["commit"]: ""}, proof))
        self.assertEqual(UNKNOWN, verdict("CB-9999", data, proof))
        self.assertEqual(UNKNOWN, verdict("CB-1027", {}, proof))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            coverage_rows({MAIN: ""})
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("collector", "live-token")
    def test_sin_datos_reales(self) -> None:
        data = sources()
        self.assertEqual(set(data), FILES)
        own = (ROOT / "tests/test_brechas_controlbot_67.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json",
                                      "os", "pathlib", "re", "shutil",
                                      "subprocess", "tempfile", "unittest", "test_brechas_controlbot_20"})
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in FILES))
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotIn("curl_init(", PHP_PROBE)
        self.assertEqual(UNKNOWN, operational_status("CB-1028", {"cron": "active"}))
    def test_estados_unknown_explicitos(self) -> None:
        data, proof = sources(), observations()
        for ident in IDS:
            with self.subTest(ident=ident):
                for claim in (None, {}, {"ci": "success"},
                              {"hostinger": "installed", "production": "ok"},
                              {"review": "approved", "db_transaction": "applied"}):
                    self.assertEqual(UNKNOWN, operational_status(ident, claim))
                self.assertEqual(UNKNOWN if ident in HISTORICAL_GAPS else LOCAL,
                                 verdict(ident, data, proof))

if __name__ == "__main__":
    unittest.main()
