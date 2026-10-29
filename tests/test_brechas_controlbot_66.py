#!/usr/bin/env python3
"""#1073 — CB-1012..1026, synthetic contracts only; NEVER a live attestation.
The tests execute existing PHP domain logic with fixed, offline inputs. They
assert both local behavior and gaps explicitly; no matrix row is promoted.
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
IDS = tuple(f"CB-{number:04d}" for number in range(1012, 1027))
UNKNOWN, LOCAL = "UNKNOWN", "CONTRATO_LOCAL"
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
DOCS = {
    "material": "docs/requirement-materialization-v1.md",
    "migration": "docs/runbooks/brvtal-migration-reconcile.md",
    "collector": "docs/runbooks/orchestrator-snapshot-cron.md",
}
CODE = {
    "material": "src/RequirementMaterializer.php",
    "migration": "src/BrvtalMigrationReconcileRunbook.php",
    "collector": "src/FactoryOrchestratorEvidenceCollector.php",
    "entry": "scripts/orchestrator-evidence-collector.php",
    "wrapper": "scripts/orchestrator-snapshot-cron.php",
}
FIXTURES = {
    "material": "tests/requirement_materialization_scenarios.php",
    "collector": "tests/factory_orchestrator_evidence_collector_scenarios.php",
}
CLAUSES = {
    "CB-1012": ("material", "Un diff alterado"),
    "CB-1013": ("migration", "Este runbook demuestra la composición"),
    "CB-1014": ("migration", "migration.status"),
    "CB-1015": ("migration", "schema_up_to_date=true"),
    "CB-1016": ("migration", "recoverable"),
    "CB-1017": ("migration", "El agente recibe solo identificadores"),
    "CB-1018": ("collector", "El colector"),
    "CB-1019": ("collector", "HTTPS-only"),
    "CB-1020": ("collector", "signal.state=pending"),
    "CB-1021": ("collector", "2 MB por respuesta"),
    "CB-1022": ("collector", "bytes"),
    "CB-1023": ("collector", "retry 5xx"),
    "CB-1024": ("collector", "Issue #767"),
    "CB-1025": ("collector", "dos páginas de 100"),
    "CB-1026": ("collector", "código allowlisted"),
}
EXTERNAL_GAPS = {"CB-1024"}
FILES = {MAIN, PART, *DOCS.values(), *CODE.values(), *FIXTURES.values()}
RUN_ID = "123e4567-e89b-42d3-a456-426614174000"
SHA = "a" * 40
STEPS = [
    "migration.status", "database.backup", "migration.registry.reconcile",
    "migration.verify", "health.check", "smoke.run",
]
PHP_PROBE = r"""
require 'src/CapabilityPolicy.php';
require 'src/BackupReceipt.php';
require 'src/BackupGate.php';
require 'src/BrvtalMigrationReconcileRunbook.php';
require 'src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Production\BrvtalMigrationReconcileRunbook;
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$base=['run_id'=>'123e4567-e89b-42d3-a456-426614174000',
       'sha'=>str_repeat('a',40),'now'=>1790748000,
       'schema_state'=>'reconcile_needed','backup_receipt'=>null,
       'completed_steps'=>[],'failed_step'=>null];
$project=static fn(array $change):array=>
    BrvtalMigrationReconcileRunbook::project(array_replace($base,$change));
$sequence=[];
foreach ([[],['migration.status'],['migration.status','database.backup'],
    ['migration.status','database.backup','migration.registry.reconcile'],
    ['migration.status','database.backup','migration.registry.reconcile','migration.verify'],
    ['migration.status','database.backup','migration.registry.reconcile','migration.verify','health.check'],
    ['migration.status','database.backup','migration.registry.reconcile','migration.verify','health.check','smoke.run']] as $steps)
    $sequence[]=$project(['completed_steps'=>$steps]);
$blocked=$project(['schema_state'=>'ambiguous','completed_steps'=>['migration.status']]);
$failed=$project(['completed_steps'=>['migration.status','database.backup'],
                  'failed_step'=>'migration.registry.reconcile']);
$invalid=false;
try{$project(['completed_steps'=>['migration.status','migration.verify']]);}
catch(Throwable $e){$invalid=true;}
$bad=[];
foreach([['POST','https://api.github.com/repos/pl0n3r/Factory'],
         ['GET','http://api.github.com/repos/x'],
         ['GET','https://example.com/repos/x'],
         ['GET','https://u:p@api.github.com/repos/x'],
         ['GET','https://api.github.com:444/repos/x']] as [$method,$url]) {
    try{FactoryOrchestratorEvidenceCollector::validateLiveRequest($method,$url);
        $bad[]=false;}catch(Throwable $e){$bad[]=true;}
}
$nonjson=FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(
    502,[], '<html>upstream unavailable</html>');
$valid=FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(
    200,[], "{\"ok\":true} \n");
$invalidJson=false;$oversize=false;
try{FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,[],'not-json');}
catch(Throwable $e){$invalidJson=true;}
try{FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(
    200,[],str_repeat('x',2000001));}
catch(Throwable $e){$oversize=true;}
$diagnostic=FactoryOrchestratorEvidenceCollector::diagnosticFor(
    new RuntimeException('github http status 403 path /repos/pl0n3r/Factory/issues'));
$opaque=FactoryOrchestratorEvidenceCollector::diagnosticFor(
    new RuntimeException('private/secret?token=do-not-echo'));
echo json_encode(compact('sequence','blocked','failed','invalid','bad',
    'nonjson','valid','invalidJson','oversize','diagnostic','opaque'),
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""
def local_sources() -> dict[str, str]:
    """Only tracked public repository files; never credentials or network."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}
def coverage_rows(files: object) -> dict[str, tuple[str, ...]]:
    if type(files) is not dict or any(type(files.get(p)) is not str
                                      for p in (MAIN, PART)):
        raise ValueError("UNKNOWN: missing coverage matrix")
    found: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART):
        for line in files[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            ident = match.group(1)
            cells = tuple(part.strip() for part in line.strip("|").split("|"))
            group, anchor = CLAUSES[ident]
            if (ident in found or len(cells) != 6 or cells[0] != ident
                    or DOCS[group] not in cells[1]
                    or anchor not in cells[2] or cells[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos."
                       not in cells[4]
                    or any(f"{kind}=unknown" not in cells[4].lower()
                           for kind in ("offline", "live", "operando"))
                    or "/issues/1" not in cells[5]
                    or (path == MAIN) != (ident in {"CB-1013", "CB-1018"})):
                raise ValueError("UNKNOWN: invalid or duplicate row " + ident)
            found[ident] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows")
    return found
@lru_cache(maxsize=12)
def scenario(group: str, case: str) -> dict:
    allow = {
        "material": {"rejected", "project-choice", "receipt"},
        "collector": {"canonical", "full-pull-page",
                      "recent-pulls", "transport-negative-branches", "failure"},
    }
    if case not in allow.get(group, set()):
        raise ValueError("UNKNOWN: scenario not allowlisted")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    proc = subprocess.run(
        [php, str(ROOT / FIXTURES[group]), case], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=35,
    )
    if proc.returncode != 0 or proc.stderr.strip():
        raise AssertionError("UNKNOWN: PHP scenario failed: " + group + "." + case)
    try:
        result = json.loads(proc.stdout)
    except (json.JSONDecodeError, UnicodeError) as exc:
        raise AssertionError("UNKNOWN: invalid local PHP evidence") from exc
    if type(result) is not dict:
        raise AssertionError("UNKNOWN: empty local PHP evidence")
    return result
@lru_cache(maxsize=1)
def probe() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    proc = subprocess.run([php, "-r", PHP_PROBE], cwd=ROOT, text=True,
                          capture_output=True, check=False, timeout=35)
    if proc.returncode != 0 or proc.stderr.strip():
        raise AssertionError("UNKNOWN: local PHP probe failed")
    try:
        out = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: corrupt PHP probe") from exc
    if type(out) is not dict or "sequence" not in out:
        raise AssertionError("UNKNOWN: incomplete PHP probe")
    return out
def observations() -> dict:
    return {
        "material_rejected": scenario("material", "rejected"),
        "material_choice": scenario("material", "project-choice"),
        "material_receipt": scenario("material", "receipt"),
        "collector": scenario("collector", "canonical"),
        "collector_full": scenario("collector", "full-pull-page"),
        "collector_negative": scenario("collector", "transport-negative-branches"),
        "collector_failure": scenario("collector", "failure"),
        "runtime": probe(),
    }
def local_evidence(ident: str, obs: object) -> bool:
    if type(obs) is not dict or any(type(x) is not dict for x in obs.values()):
        return False
    try:
        m, choice, receipt = (obs[x] for x in
                              ("material_rejected", "material_choice", "material_receipt"))
        c, full, negative, fail, p = (obs[x] for x in
                                      ("collector", "collector_full",
                                       "collector_negative", "collector_failure", "runtime"))
        seq = p["sequence"]
        if ident == "CB-1012":
            return (m["blocked"] == [True, True, True]
                    and m["mutations"] == [0, 0, 0]
                    and choice["missing_blocked"] is True
                    and choice["ambiguous_blocked"] is True
                    and choice["ambiguous_mutations"] == 0)
        if ident == "CB-1013":
            return (seq[0]["status"] == "planned"
                    and seq[0]["next_intent"]["operation"] == "migration.status"
                    and seq[0]["next_intent"]["effect"] == "read"
                    and seq[0]["revoke_grant"] is False)
        if ident == "CB-1014":
            return (len(seq) == 7 and
                    [x["next_intent"]["operation"] if x["next_intent"] else None
                     for x in seq] ==
                    ["migration.status", "database.backup", None,
                     "migration.verify", "health.check", "smoke.run", None]
                    and seq[2]["status"] == "blocked"
                    and seq[2]["reason"] == "backup_receipt_required"
                    and seq[6]["status"] == "success"
                    and seq[6]["revoke_grant"] is True
                    and p["invalid"] is True)
        if ident == "CB-1015":
            return (seq[4]["next_intent"]["operation"] == "health.check"
                    and seq[4]["next_intent"]["expect"] ==
                    {"schema_up_to_date": True})
        if ident == "CB-1016":
            return (p["failed"]["status"] == "recoverable"
                    and p["failed"]["continuation"]["resume_from"] ==
                    "migration.registry.reconcile"
                    and p["failed"]["next_intent"] is None
                    and p["failed"]["revoke_grant"] is True)
        if ident == "CB-1017":
            serialized = json.dumps(seq + [p["failed"], p["blocked"]]).lower()
            return (all(x not in serialized for x in
                        ("password", "ssh", "secret", "credential", "shell"))
                    and p["blocked"]["evidence"]["baseline_allowed"] is False
                    and receipt["secret_blocked"] is True)
        if ident == "CB-1018":
            return (c["result"]["state"] == "written"
                    and c["result"]["requests"] == len(c["calls"])
                    and all(call["method"] == "GET" for call in c["calls"])
                    and fail["failed"] is True
                    and fail["previous"] == '{"old":true}')
        if ident == "CB-1019":
            return (p["bad"] == [True] * 5
                    and negative["failed"] == [True] * 7
                    and negative["retry"]["status"] == 502
                    and "json" not in negative["retry"])
        if ident == "CB-1020":
            work = c["evidence"]["work"]
            return (c["evidence"]["releases"] == []
                    and "work_inventory" not in c["evidence"]
                    and any(row["state"] == "pending"
                            and row["data"].get("labels") == ["estado: disponible"]
                            and "status" not in row["data"]
                            for row in work)
                    and any(row["data"].get("status") == "in_review"
                            for row in work)
                    and any(row["data"].get("status") == "merged"
                            for row in work))
        if ident == "CB-1021":
            r = c["result"]
            return (0 < r["requests"] <= 40
                    and 0 < r["download_bytes"] <= 8_000_000
                    and 0 < r["evidence_bytes"] <= 2_000_000
                    and p["oversize"] is True)
        if ident == "CB-1022":
            return (p["valid"]["bytes"] == len('{"ok":true} \n')
                    and p["valid"]["json"] == {"ok": True}
                    and p["nonjson"]["bytes"] ==
                        len("<html>upstream unavailable</html>")
                    and negative["retry"]["bytes"] ==
                        len("<html>bad gateway</html>"))
        if ident == "CB-1023":
            return (p["nonjson"]["status"] == 502
                    and "json" not in p["nonjson"]
                    and p["invalidJson"] is True
                    and negative["retry"]["status"] == 502)
        if ident == "CB-1024":
            return False  # UNKNOWN: strict kill switch negative cases are separate
        if ident == "CB-1025":
            urls = full["calls"]
            return (any("per_page=10" in url for url in urls)
                    and not any("page=2" in url and "/pulls" in url
                                for url in urls)
                    and len(full["evidence"]["releases"]) == 0)
        if ident == "CB-1026":
            return (p["diagnostic"] == {
                "code": "http_status_403",
                "path": "/repos/pl0n3r/Factory/issues", "status": 403}
                and p["opaque"] == {"code": "internal_error"}
                and negative["http_diagnostic"] == p["diagnostic"])
    except (KeyError, IndexError, TypeError, ValueError, AttributeError):
        return False
    return False
def verdict(ident: object, files: object, evidence: object) -> str:
    if ident not in IDS or type(files) is not dict or set(files) != FILES:
        return UNKNOWN
    if any(type(value) is not str or not value.strip()
           for value in files.values()):
        return UNKNOWN
    try:
        rows = coverage_rows(files)
    except ValueError:
        return UNKNOWN
    group, anchor = CLAUSES[ident]
    if (anchor not in files[DOCS[group]]
            or rows[ident][3] != "NO_VERIFICABLE"
            or not files[CODE[group]].strip()):
        return UNKNOWN
    return LOCAL if local_evidence(ident, evidence) else UNKNOWN
def operational_status(_ident: object, _claim: object = None) -> str:
    """No fixture can attest production, auth, permission, or exact CI HEAD."""
    return UNKNOWN
class BrechasControlbot66Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, obs = local_sources(), observations()
        rows = coverage_rows(data)
        self.assertEqual(set(IDS), set(rows))
        self.assertEqual(set(IDS), set(CLAUSES))
        for ident in IDS:
            with self.subTest(requirement=ident):
                self.assertEqual("NO_VERIFICABLE", rows[ident][3])
                expected = UNKNOWN if ident in EXTERNAL_GAPS else LOCAL
                self.assertEqual(expected, verdict(ident, data, obs))
    def test_falla_cerrado(self) -> None:
        data, obs = local_sources(), observations()
        for ident in IDS:
            with self.subTest(requirement=ident):
                path = MAIN if ident in {"CB-1013", "CB-1018"} else PART
                line = next(x for x in data[path].splitlines()
                            if x.startswith(f"| {ident} |"))
                for altered in (
                    data[path].replace(line, "", 1),
                    data[path] + "\n" + line,
                    data[path].replace(
                        line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
                    data[path].replace(
                        line, line.replace(
                            "Falta contraste de comportamiento", "Demostrado", 1), 1),
                ):
                    self.assertEqual(UNKNOWN, verdict(
                        ident, {**data, path: altered}, obs))
                group, anchor = CLAUSES[ident]
                doc = DOCS[group]
                self.assertEqual(UNKNOWN, verdict(
                    ident, {**data, doc: data[doc].replace(anchor, "")}, obs))
                self.assertEqual(UNKNOWN, verdict(ident, data, None))
                self.assertEqual(UNKNOWN, verdict(
                    ident, data, {**obs, "runtime": {}}))
        self.assertEqual(UNKNOWN, verdict("CB-9999", data, obs))
        self.assertEqual(UNKNOWN, verdict("CB-1012", {}, obs))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            coverage_rows({MAIN: ""})
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("collector", "live-credentials")
    def test_sin_datos_reales(self) -> None:
        data = local_sources()
        self.assertEqual(set(data), FILES)
        for path in FILES:
            self.assertFalse(Path(path).is_absolute())
            self.assertNotIn("..", Path(path).parts)
        own = (ROOT / "tests/test_brechas_controlbot_66.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertLessEqual(imports, {
            "__future__", "ast", "functools", "json", "pathlib", "re",
            "shutil", "subprocess", "unittest",
        })
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotIn("file_put_contents(", PHP_PROBE)
        self.assertNotIn("curl_init(", PHP_PROBE)
        self.assertNotIn("do-not-echo", json.dumps(observations()["material_receipt"]))
        self.assertEqual(UNKNOWN, operational_status(
            "CB-1017", {"owner_approval": True, "production": True}))
    def test_estados_unknown_explicitos(self) -> None:
        data, obs = local_sources(), observations()
        for ident in IDS:
            with self.subTest(requirement=ident):
                for claim in (None, {}, {"ci": "success", "sha": SHA},
                              {"backup": "VERIFIED", "owner_approval": True},
                              {"production": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(UNKNOWN, operational_status(ident, claim))
                if ident in EXTERNAL_GAPS:
                    self.assertEqual(UNKNOWN, verdict(ident, data, obs))
                else:
                    self.assertEqual(LOCAL, verdict(ident, data, obs))
if __name__ == "__main__":
    unittest.main()
