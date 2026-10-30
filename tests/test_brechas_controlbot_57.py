#!/usr/bin/env python3
"""ControlBot #1064: 15 PauseControl coverage gaps, synthetic and fail-closed.

Only the local PHP contracts can be proven here. Neither these fixtures nor a
successful CI run authorize production, providers, persistence, or live state.
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

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura-parte-03.md"
PAUSE_DOC = "docs/pause-control-v1.md"
GATE_DOC = "docs/pause-production-gate-v1.md"
RESUME_DOC = "docs/pause-resume-plan-v1.md"
AUDIT_DOC = "docs/pause-transition-audit-v1.md"
PAUSE = "src/PauseControl.php"
GATE = "src/PauseProductionGate.php"
RESUME = "src/PauseResumePlan.php"
AUDIT = "src/PauseTransitionAudit.php"
UNKNOWN = "UNKNOWN"
PARTIAL = "LOCAL_PARTIAL"
IDS = tuple(f"CB-{n:04d}" for n in (
    867, 868, 869, 870, 872, 873, 874, 875, 876,
    878, 879, 880, 882, 883, 884,
))
DOCUMENT_ONLY = {"CB-0870"}
DOC_MARKERS = {
    "CB-0867": (PAUSE_DOC, "non_preemptible"),
    "CB-0868": (PAUSE_DOC, "Una segunda liberación"),
    "CB-0869": (PAUSE_DOC, "material sensible obvio"),
    "CB-0870": (PAUSE_DOC, "Persistencia, endpoints, UI"),
    "CB-0872": (GATE_DOC, "mutation=true"),
    "CB-0873": (GATE_DOC, "mutation=false"),
    "CB-0874": (GATE_DOC, "authorization=not_granted"),
    "CB-0875": (GATE_DOC, "global > project > account > session"),
    "CB-0876": (GATE_DOC, "fingerprint SHA-256 determinista"),
    "CB-0878": (RESUME_DOC, "assigned|running|review"),
    "CB-0879": (RESUME_DOC, "create_work_item=false"),
    "CB-0880": (RESUME_DOC, "assertReplay()"),
    "CB-0882": (AUDIT_DOC, "pause_id"),
    "CB-0883": (AUDIT_DOC, "append(history,event)"),
    "CB-0884": (AUDIT_DOC, "patrones de secretos"),
}
CODE_MARKERS = {
    "CB-0867": (PAUSE, "Pause activation requires safe-point evidence."),
    "CB-0868": (PAUSE, "function release("),
    "CB-0869": (PAUSE, "private static function text("),
    "CB-0872": (GATE, "PauseControl::effective("),
    "CB-0873": (GATE, "$mutation="),
    "CB-0874": (GATE, "'authorization'=>'not_granted'"),
    "CB-0875": (PAUSE, "'global' => 4"),
    "CB-0876": (GATE, "hash('sha256'"),
    "CB-0878": (RESUME, "private const ACTIVE_STATES="),
    "CB-0879": (RESUME, "'create_work_item'=>false"),
    "CB-0880": (RESUME, "function assertReplay("),
    "CB-0882": (AUDIT, "sameIdentityAndProvenance("),
    "CB-0883": (AUDIT, "function append("),
    "CB-0884": (AUDIT, "private const SENSITIVE="),
}
PROBES = {
    "p_preempt": ("pause_control_scenarios.php", "preemptibility"),
    "p_release": ("pause_control_scenarios.php", "release"),
    "p_invalid": ("pause_control_scenarios.php", "invalid"),
    "p_precedence": ("pause_control_scenarios.php", "precedence"),
    "g_project": ("pause_production_gate_scenarios.php", "project"),
    "g_precedence": ("pause_production_gate_scenarios.php", "precedence"),
    "g_invalid": ("pause_production_gate_scenarios.php", "invalid"),
    "g_release": ("pause_production_gate_scenarios.php", "release"),
    "g_deterministic": ("pause_production_gate_scenarios.php", "deterministic"),
    "r_valid": ("pause_resume_plan_scenarios.php", "valid"),
    "r_drift": ("pause_resume_plan_scenarios.php", "drift"),
    "r_state": ("pause_resume_plan_scenarios.php", "state"),
    "r_replay": ("pause_resume_plan_scenarios.php", "replay"),
    "r_deterministic": ("pause_resume_plan_scenarios.php", "deterministic"),
    "a_transition": ("pause_transition_audit_scenarios.php", "transition"),
    "a_invalid": ("pause_transition_audit_scenarios.php", "invalid"),
    "a_append": ("pause_transition_audit_scenarios.php", "append"),
    "a_history": ("pause_transition_audit_scenarios.php", "history"),
    "a_secret": ("pause_transition_audit_scenarios.php", "secret"),
}
PROBE_FOR = {
    "CB-0867": ("p_preempt",), "CB-0868": ("p_release",),
    "CB-0869": ("p_invalid",), "CB-0872": ("g_project", "g_invalid"),
    "CB-0873": ("g_project",), "CB-0874": ("g_project", "g_release"),
    "CB-0875": ("g_precedence", "g_invalid"), "CB-0876": ("g_project", "g_deterministic"),
    "CB-0878": ("r_valid", "r_drift", "r_state"),
    "CB-0879": ("r_valid",), "CB-0880": ("r_replay", "r_deterministic"),
    "CB-0882": ("a_transition", "a_invalid"),
    "CB-0883": ("a_append", "a_history"), "CB-0884": ("a_secret",),
}
FILES = ({MATRIX} | {p for p, _ in DOC_MARKERS.values()}
         | {p for p, _ in CODE_MARKERS.values()}
         | {"tests/" + s for s, _ in PROBES.values()})


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in FILES}


def matrix_rows(data: dict[str, str]) -> dict[str, tuple[str, ...]]:
    text = data.get(MATRIX)
    if not isinstance(text, str):
        raise ValueError("UNKNOWN: coverage matrix missing")
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (CB-\d{4}) \|", line)
        if match is None or match.group(1) not in IDS:
            continue
        parts = tuple(c.strip() for c in line.strip().strip("|").split("|"))
        key = match.group(1)
        if (key in found or len(parts) != 6 or not all(parts)
                or not e3_coverage(parts)):
            raise ValueError("UNKNOWN: duplicated, incomplete, or promoted " + key)
        found[key] = parts
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: coverage IDs missing")
    return found


@lru_cache(maxsize=None)
def php_scenario(name: str) -> object:
    script, scenario = PROBES[name]
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: php unavailable")
    run = subprocess.run(
        [php, str(ROOT / "tests" / script), scenario],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
    )
    if run.returncode != 0 or run.stderr.strip():
        raise AssertionError("UNKNOWN: synthetic probe failed: " + run.stderr[-280:])
    try:
        return json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP result") from exc


def observations() -> dict[str, object]:
    return {name: php_scenario(name) for name in PROBES}


def local_verdict(key: str, data: dict[str, str],
                  observed: dict[str, object]) -> str:
    if key not in IDS:
        return UNKNOWN
    try:
        row = matrix_rows(data)[key]
        doc, marker = DOC_MARKERS[key]
        if doc not in row[1] or marker not in data[doc]:
            return UNKNOWN
        if key in DOCUMENT_ONLY:
            return UNKNOWN  # Explicit gap, never pretend live/operational evidence.
        source, contract = CODE_MARKERS[key]
        if contract not in data[source]:
            return UNKNOWN
        if any(not isinstance(observed.get(probe), dict) or not observed[probe]
               for probe in PROBE_FOR[key]):
            return UNKNOWN
        p = observed
        if key == "CB-0867":
            x = p["p_preempt"]
            ok = (x["non_preemptible_missing"] is True
                  and x["safe_point_missing"] is True
                  and x["non_preemptible"]["safe_point_at"] == 105
                  and x["immediate"]["safe_point_at"] is None)
        elif key == "CB-0868":
            x = p["p_release"]
            ok = all(x[k] is True for k in ("idempotent", "same_keys", "extra_rejected"))
        elif key == "CB-0869":
            ok = (p["p_invalid"]["invalid"] is True
                  and p["p_invalid"]["foreign"] is True
                  and "file_put_contents" not in data[PAUSE].lower())
        elif key == "CB-0872":
            x = p["g_project"]["write"]
            ok = (x["effect"] == "write" and x["pause_blocked"] is True
                  and x["pause_allows"] is False
                  and p["g_invalid"]["unknown_write"]["pause_blocked"] is True)
        elif key == "CB-0873":
            x = p["g_project"]["read"]
            ok = (x["effect"] == "read" and x["pause_blocked"] is False
                  and x["pause_allows"] is True and x["effective_scope"] == "project"
                  and x["effective_state"] == "active")
        elif key == "CB-0874":
            rows = [*p["g_project"].values(), *p["g_release"].values()]
            ok = all(x["requires_existing_authority"] is True
                     and x["authorization"] == "not_granted"
                     and "grant" not in x and "approval" not in x for x in rows)
        elif key == "CB-0875":
            x = p["g_precedence"]
            ok = (x["effective_scope"] == "global"
                  and x["effective_pause_id"] == "pause-global"
                  and p["g_invalid"]["unknown_operation"] is True
                  and p["g_invalid"]["scope_mismatch"] is True)
        elif key == "CB-0876":
            x = p["g_project"]["write"]
            ok = (x["operation_id"] == "database.backup"
                  and x["capability"] == "database.backup"
                  and x["effect"] == "write"
                  and re.fullmatch(r"[0-9a-f]{64}", x["fingerprint"]) is not None
                  and p["g_deterministic"]["same"] is True
                  and p["g_deterministic"]["fingerprint"] is True)
        elif key == "CB-0878":
            x = p["r_valid"]
            ok = (x["work_item_id"] == "work-a" and x["generation"] == 4
                  and x["attempt"] == 2
                  and all(v is True for v in p["r_drift"].values())
                  and all(v is True for v in p["r_state"].values()))
        elif key == "CB-0879":
            x = p["r_valid"]
            ok = (x["create_work_item"] is False and x["create_order"] is False
                  and x["reuse_current_attempt"] is True
                  and x["requires_existing_authority"] is True
                  and x["order_id"] == "11111111-1111-4111-8111-111111111111"
                  and x["issued_at"] == 110 and x["expires_at"] == 1000)
        elif key == "CB-0880":
            ok = (p["r_replay"]["exact"] is True
                  and p["r_replay"]["conflict"] is True
                  and p["r_deterministic"]["same"] is True
                  and p["r_deterministic"]["fingerprint"] is True)
        elif key == "CB-0882":
            x = p["a_transition"]
            ok = (x["same_create"] is True
                  and x["release"]["before_state"] == "active"
                  and x["release"]["after_state"] == "releasing"
                  and x["create"]["actor"] == "owner-main"
                  and all(v is True for v in p["a_invalid"].values()))
        elif key == "CB-0883":
            x = p["a_append"]
            ok = (x["count"] == 2 and x["replay_same"] is True
                  and x["conflict"] is True and x["chain_gap"] is True
                  and x["history_provenance"] is True
                  and all(v is True for v in p["a_history"].values()))
        else:  # CB-0884: audited secrets/PII rejected with no external I/O.
            x = p["a_secret"]
            ok = (all(x[k] is True for k in
                      ("actor_secret", "actor_pii", "reason_secret",
                       "reason_pii", "evidence_pii"))
                  and x["hits"] == [])
        return PARTIAL if ok else UNKNOWN
    except (ValueError, KeyError, TypeError, AttributeError, IndexError):
        return UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    return UNKNOWN


class BrechasControlbot57Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all fifteen distinct gaps have real assertions or explicit UNKNOWN."""
        data, observed = sources(), observations()
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(DOC_MARKERS), set(IDS))
        self.assertEqual(set(CODE_MARKERS), set(IDS) - DOCUMENT_ONLY)
        self.assertEqual(set(PROBE_FOR), set(IDS) - DOCUMENT_ONLY)
        self.assertEqual(set(matrix_rows(data)), set(IDS))
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(local_verdict(key, data, observed),
                                 UNKNOWN if key in DOCUMENT_ONLY else PARTIAL)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, promoted, or falsified evidence is UNKNOWN."""
        data, observed = sources(), observations()
        for key in IDS:
            with self.subTest(id=key):
                line = next(v for v in data[MATRIX].splitlines()
                            if v.startswith(f"| {key} |"))
                for mutated in (data[MATRIX].replace(line, ""),
                                data[MATRIX] + "\n" + line,
                                data[MATRIX].replace(line, (line.replace("MERGED", "PENDING") if " | CUBIERTO | " in line else line.replace("NO_VERIFICABLE", "CUBIERTO")))):
                    self.assertEqual(local_verdict(key, {**data, MATRIX: mutated}, observed), UNKNOWN)
                doc, marker = DOC_MARKERS[key]
                self.assertEqual(local_verdict(key, {**data, doc: data[doc].replace(marker, "")},
                                               observed), UNKNOWN)
                if key not in DOCUMENT_ONLY:
                    source, contract = CODE_MARKERS[key]
                    self.assertEqual(local_verdict(
                        key, {**data, source: data[source].replace(contract, "")}, observed), UNKNOWN)
                    probe = PROBE_FOR[key][0]
                    self.assertEqual(local_verdict(key, data, {**observed, probe: {}}), UNKNOWN)
        self.assertEqual(local_verdict("CB-9999", data, observed), UNKNOWN)
        self.assertEqual(local_verdict("CB-0867", {}, observed), UNKNOWN)
        duplicate = next(line for line in data[MATRIX].splitlines()
                         if line.startswith("| CB-0867 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows({**data, MATRIX: data[MATRIX] + "\n" + duplicate})

    def test_sin_datos_reales(self) -> None:
        """AC-03: read-only synthetic fixtures; no HTTP, database or credentials."""
        own = (ROOT / "tests/test_brechas_controlbot_57.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json",
                                       "pathlib", "re", "shutil", "subprocess", "unittest",
                                       "test_brechas_controlbot_20"})
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in FILES))
        for name, (script, _) in PROBES.items():
            with self.subTest(probe=name):
                self.assertTrue(script.endswith("_scenarios.php"))
        self.assertEqual(operational_state("CB-0870", {"deploy": "claimed"}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local contracts cannot grant remote operational authority."""
        data, observed = sources(), observations()
        for key in IDS:
            with self.subTest(id=key):
                for forged in (None, {}, {"ci": "success", "sha": "forged"},
                               {"production": "GREEN", "authorized": True},
                               {"scope": "live", "approval": "claimed"}):
                    self.assertEqual(operational_state(key, forged), UNKNOWN)
                if key not in DOCUMENT_ONLY:
                    self.assertEqual(local_verdict(key, data, observed), PARTIAL)
        self.assertEqual(local_verdict("CB-0870", data, observed), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
