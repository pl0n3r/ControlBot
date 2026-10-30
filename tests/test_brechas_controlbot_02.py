#!/usr/bin/env python3
"""ControlBot #1009: CB-0016..CB-0030, synthetic AEGIS PHP contracts.

LOCAL_CONTRACT is not proof of live collectors, backup, restore or production.
"""
from __future__ import annotations

import ast
from copy import deepcopy
from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(16, 31))
INDEX = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-01.md"
EXPECTED = {INDEX: {"CB-0017"}, PART: set(IDS) - {"CB-0017"}}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"


@lru_cache(maxsize=20)
def scenario(domain: str, case: str):
    options = {
        "runtime": {"dedupe", "verified"},
        "posture": {"findings", "scope", "identity", "vulnerability",
                    "unknown_finding", "missing_certainty", "continuity",
                    "dedupe", "deterministic"},
    }
    if case not in options.get(domain, set()):
        raise AssertionError("UNKNOWN: invalid fixture")
    script = ("aegis_runtime_scenarios.php" if domain == "runtime"
              else "aegis_posture_scenarios.php")
    proc = subprocess.run(
        ["php", str(ROOT / "tests" / script), case],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if proc.returncode:
        raise AssertionError("UNKNOWN: PHP fixture failed: " + proc.stderr[-500:])
    return json.loads(proc.stdout)


def baseline():
    scope = "venture:condor"
    def continuity(state, label):
        return {"scope": scope, "state": state, "source_ref": f"controlbot:aegis/source-{label}",
                "observed_at": 2000, "freshness": "fresh"}
    return {
        "version": 1, "scope": scope,
        "findings": [{
            "finding_id": "finding-sample", "category": "vulnerability",
            "severity": "medium", "scope": scope, "state": "open",
            "evidence_refs": ["controlbot:aegis/evidence-sample"],
            "source_ref": "controlbot:aegis/source-scan",
            "observed_at": 2000, "freshness": "fresh",
        }],
        "identity_signals": [{
            "metric": metric, "scope": scope, "value": 3,
            "source_type": "authoritative",
            "source_ref": "controlbot:aegis/source-identity",
            "observed_at": 2000, "freshness": "fresh",
        } for metric in ("mfa_coverage", "privileged_identities", "orphan_accounts")],
        "vulnerability_signals": [{
            "severity": severity, "scope": scope, "count": 0,
            "source_ref": "controlbot:aegis/source-vulnerability",
            "observed_at": 2000, "freshness": "fresh",
        } for severity in ("info", "low", "medium", "high", "critical")],
        "backup_signal": continuity("available", "backup"),
        "restore_signal": continuity("verified", "restore"),
    }


PHP = r'''
require 'src/AegisPosture.php';
$data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
 $result=\ControlBot\Security\AegisPosture::summarize($data);
 echo json_encode(['accepted'=>true,'output'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
 echo json_encode(['accepted'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def probe(value):
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP],
        input=json.dumps(value), cwd=ROOT, text=True, capture_output=True,
        timeout=15, check=False,
    )
    if proc.returncode:
        raise AssertionError("UNKNOWN: PHP probe failed: " + proc.stderr[-500:])
    result = json.loads(proc.stdout)
    if type(result) is not dict or type(result.get("accepted")) is not bool:
        raise AssertionError("UNKNOWN: PHP probe unreadable")
    return result


def rejects(value):
    return probe(value)["accepted"] is False


def rows(raw, expected):
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matrix absent")
    found = {}
    for line in raw.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if not cells or cells[0] not in IDS:
            continue
        if cells[0] in found or len(cells) != 6 or not cells[2]:
            raise ValueError("UNKNOWN: malformed/duplicate row")
        if cells[3] == "NO_VERIFICABLE":
            if "UNKNOWN" not in cells[4]:
                raise ValueError("UNKNOWN: absence not explained")
        elif cells[3] == "CUBIERTO":
            if not credible_offline_coverage_row(cells):
                raise ValueError("UNKNOWN: unsupported promotion")
        else:
            raise ValueError("UNKNOWN: invented matrix status")
        found[cells[0]] = cells
    if set(found) != set(expected):
        raise ValueError("UNKNOWN: incomplete rows")
    return found


def assess(matrix, checks):
    unknown = dict.fromkeys(IDS, UNKNOWN)
    if type(matrix) is not dict or set(matrix) != set(EXPECTED):
        return unknown
    if type(checks) is not dict or set(checks) != set(IDS):
        return unknown
    try:
        groups = [rows(matrix[path], EXPECTED[path]) for path in EXPECTED]
    except (ValueError, KeyError):
        return unknown
    if set(groups[0]).intersection(groups[1]):
        return unknown
    return {key: LOCAL if checks[key] is True else UNKNOWN for key in IDS}


def contracts():
    runtime = scenario("runtime", "dedupe")
    verified = scenario("runtime", "verified")
    findings = scenario("posture", "findings")
    scoped = scenario("posture", "scope")
    identity = scenario("posture", "identity")
    vuln = scenario("posture", "vulnerability")
    unknown = scenario("posture", "unknown_finding")
    continuity = scenario("posture", "continuity")
    missing = scenario("posture", "missing_certainty")
    dedupe = scenario("posture", "dedupe")
    deterministic = scenario("posture", "deterministic")
    original = baseline()
    observed = probe(original)

    def altered(change):
        data = deepcopy(original)
        change(data)
        return data

    wrong_scope = altered(lambda d: d["findings"][0].update(scope="project:other"))
    wrong_id_scope = altered(lambda d: d["identity_signals"][0].update(scope="venture:other"))
    wrong_backup_scope = altered(lambda d: d["backup_signal"].update(scope="venture:other"))
    secret = altered(lambda d: d["findings"][0].update(source_ref="controlbot:aegis/api-token"))
    extras = altered(lambda d: d["findings"][0].update(private_key="fixture"))
    conflict = deepcopy(original)
    different = deepcopy(conflict["findings"][0])
    different["severity"] = "high"
    conflict["findings"].append(different)
    bad_backup = altered(lambda d: d["backup_signal"].update(state="verified"))
    bad_restore = altered(lambda d: d["restore_signal"].update(state="available"))
    backup_failed = probe(altered(lambda d: d["backup_signal"].update(state="failed")))
    restore_failed = probe(altered(lambda d: d["restore_signal"].update(state="failed")))
    stale = probe(altered(lambda d: d["vulnerability_signals"][3].update(freshness="stale")))
    invalid_scope = altered(lambda d: d.update(scope="cross-tenant"))
    absent_restore = deepcopy(original)
    absent_restore.pop("restore_signal")
    unexpected = altered(lambda d: d.update(unexpected="fixture"))
    f = findings["posture"]["findings"][0]
    metrics = {x["metric"]: x for x in identity["identity_signals"]}
    exposures = {x["severity"]: x for x in vuln["vulnerability_signals"]}
    return {
        "CB-0016": len(runtime) == 1
            and runtime[0]["work_item"]["idempotency_key"]
                == verified["work_item"]["idempotency_key"]
            and "controlbot:aegis/verification-worker" in verified["work_item"]["evidence_refs"],
        "CB-0017": observed["accepted"] is True
            and observed["output"] == probe(original)["output"]
            and observed["output"]["version"] == 1
            and observed["output"]["scope"] == "venture:condor",
        "CB-0018": scoped["condor"]["scope"] == "venture:condor"
            and rejects(invalid_scope) and observed["output"]["scope"] == "venture:condor",
        "CB-0019": "scope mismatch" in scoped["mismatch"]
            and rejects(wrong_scope) and rejects(wrong_id_scope) and rejects(wrong_backup_scope),
        "CB-0020": "invalid" in findings["secret_rejected"]
            and rejects(secret) and rejects(extras) and rejects(unexpected)
            and f["evidence_refs"] == ["controlbot:aegis/evidence-config"],
        "CB-0021": len(dedupe["findings"]) == 1
            and dedupe["findings"][0]["evidence_refs"]
                == ["controlbot:aegis/evidence-a", "controlbot:aegis/evidence-b"]
            and rejects(conflict) and deterministic["same"] is True,
        "CB-0022": metrics["mfa_coverage"]["state"] == "known"
            and metrics["mfa_coverage"]["value"] == 90
            and all(metrics[x]["state"] == "unknown" and metrics[x]["value"] is None
                    for x in ("privileged_identities", "orphan_accounts")),
        "CB-0023": exposures["high"]["freshness"] == "unknown"
            and exposures["high"]["state"] == "unknown"
            and exposures["critical"]["state"] == "clear"
            and stale["accepted"] is True
            and stale["output"]["posture_state"] == "unknown",
        "CB-0024": continuity["backup_signal"]["state"] == "available"
            and continuity["restore_signal"]["state"] == "unknown"
            and continuity["backup_signal"]["source_ref"] != continuity["restore_signal"]["source_ref"],
        "CB-0025": backup_failed["accepted"] is True
            and backup_failed["output"]["backup_signal"]["state"] == "failed"
            and rejects(bad_backup),
        "CB-0026": restore_failed["accepted"] is True
            and restore_failed["output"]["restore_signal"]["state"] == "failed"
            and rejects(bad_restore),
        "CB-0027": continuity["backup_signal"]["state"] == "available"
            and continuity["restore_signal"]["state"] == "unknown"
            and continuity["posture_state"] == "unknown",
        "CB-0028": dedupe["posture_state"] == "attention_required"
            and backup_failed["output"]["posture_state"] == "attention_required"
            and restore_failed["output"]["posture_state"] == "attention_required",
        "CB-0029": identity["posture_state"] == "unknown"
            and vuln["posture_state"] == "unknown"
            and unknown["posture_state"] == "unknown"
            and stale["output"]["posture_state"] == "unknown",
        "CB-0030": missing["identity_missing"]["posture_state"] == "unknown"
            and missing["vulnerability_missing"]["posture_state"] == "unknown"
            and continuity["posture_state"] == "unknown"
            and rejects(absent_restore),
    }


class BrechasControlbot02Tests(unittest.TestCase):
    def test_contrato_principal(self):
        matrix = {p: (ROOT / p).read_text(encoding="utf-8") for p in EXPECTED}
        checks = contracts()
        self.assertEqual(set(checks), set(IDS))
        self.assertEqual(set(IDS), set(rows(matrix[INDEX], EXPECTED[INDEX]))
                         | set(rows(matrix[PART], EXPECTED[PART])))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                self.assertIs(checks[identifier], True)
                self.assertEqual(assess(matrix, checks)[identifier], LOCAL)

    def test_falla_cerrado(self):
        matrix = {p: (ROOT / p).read_text(encoding="utf-8") for p in EXPECTED}
        observations = dict.fromkeys(IDS, True)
        for path in EXPECTED:
            for identifier in EXPECTED[path]:
                line = next(x for x in matrix[path].splitlines()
                            if x.startswith("| " + identifier + " |"))
                invalid_rows = (
                    matrix[path].replace(line, "", 1),
                    matrix[path] + "\n" + line,
                    matrix[path].replace(line, (line.replace("MERGED", "PENDING", 1) if "CUBIERTO" in line
                                             else line.replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1),
                )
                for bad in invalid_rows:
                    with self.subTest(identifier=identifier), self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(bad, EXPECTED[path])
                    self.assertEqual(set(assess({**matrix, path: bad}, observations).values()), {UNKNOWN})
        self.assertEqual(set(assess(matrix, None).values()), {UNKNOWN})
        self.assertEqual(set(assess({}, observations).values()), {UNKNOWN})
        self.assertEqual(set(assess(matrix, {**observations, "CI": True}).values()), {UNKNOWN})

    def test_sin_datos_reales(self):
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        names = {alias.name.split(".")[0] for n in ast.walk(tree)
                 if isinstance(n, ast.Import) for alias in n.names}
        names |= {n.module.split(".")[0] for n in ast.walk(tree)
                  if isinstance(n, ast.ImportFrom) and n.module}
        self.assertFalse(names & {"requests", "urllib", "httpx", "socket",
                                  "pymysql", "selenium", "playwright"})
        self.assertEqual(baseline()["scope"], "venture:condor")
        self.assertNotIn("api.github.com", PHP)

    def test_estados_unknown_explicitos(self):
        matrix = {p: (ROOT / p).read_text(encoding="utf-8") for p in EXPECTED}
        good = dict.fromkeys(IDS, True)
        self.assertEqual(set(assess(matrix, {}).values()), {UNKNOWN})
        self.assertEqual(set(assess(matrix, {**good, "production": "GREEN"}).values()), {UNKNOWN})
        for identifier in IDS:
            self.assertEqual(assess(matrix, {**good, identifier: False})[identifier], UNKNOWN)
            self.assertEqual(assess(matrix, {**good, identifier: None})[identifier], UNKNOWN)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", assess(matrix, good).values())


if __name__ == "__main__":
    unittest.main()
