#!/usr/bin/env python3
"""ControlBot #1075: fifteen Scheduler/security-alert gaps, synthetic only."""
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
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MAIN, PART4 = "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{n:04d}" for n in range(1042, 1057))
DOCS = {
    "a": "docs/scheduler-assignment-plan-v1.md",
    "p": "docs/scheduler-policy-guard-v1.md",
    "r": "docs/scheduler-requeue-plan-v1.md",
    "s": "docs/scheduler-selection-v1.md",
    "c": "docs/security-alert-channel-v1.md",
}
SRC = {
    "a": "src/SchedulerAssignmentPlan.php",
    "p": "src/SchedulerPolicyGuard.php",
    "r": "src/SchedulerRequeuePlan.php",
    "s": "src/SchedulerSelection.php",
    "c": "src/SecurityAlertChannel.php",
}
SCRIPTS = {
    "a": "scheduler_assignment_plan_scenarios.php",
    "p": "scheduler_policy_guard_scenarios.php",
    "r": "scheduler_requeue_plan_scenarios.php",
    "s": "scheduler_selection_scenarios.php",
    "c": "security_alert_scenarios.php",
}
CASES = {
    "a": ("target", "binding", "ownership", "deterministic", "pure"),
    "p": ("preemption", "focus", "blocked_focus"),
    "r": ("transition", "guard", "requeue", "owner_guard", "deterministic"),
    "s": ("request", "drift", "authority", "telemetry", "pure"),
    "c": (None,),
}
CLAUSES = {
    "CB-1042": ("a", "Los fingerprints son"),
    "CB-1043": ("a", "Session ocupada"),
    "CB-1044": ("p", "Solo una selección protegida"),
    "CB-1045": ("p", "focus_influenced"),
    "CB-1046": ("r", "convierte una pérdida real de heartbeat"),
    "CB-1047": ("r", "Solo continúa si"),
    "CB-1048": ("r", "La salida marca la Reservation"),
    "CB-1049": ("r", "solo acepta eventos cuya Session"),
    "CB-1050": ("s", "Salida: la selección validada"),
    "CB-1051": ("s", "factory-dispatcher-v2"),
    "CB-1052": ("s", "La telemetría se limita a"),
    "CB-1053": ("s", "El módulo es puro y aditivo"),
    "CB-1054": ("c", "primary_state"),
    "CB-1055": ("c", "Si el canal primario está"),
    "CB-1056": ("c", "Para un evento"),
}
MAIN_IDS = {"CB-1044", "CB-1046", "CB-1050", "CB-1054"}
# Future DB atomicity is not proven by the pure plan, despite valid CAS.
DOCUMENTED_UNKNOWN = {"CB-1042"}
PROBES = {
    "CB-1042": "a.deterministic", "CB-1043": "a.target",
    "CB-1044": "p.preemption", "CB-1045": "p.focus",
    "CB-1046": "r.transition", "CB-1047": "r.guard",
    "CB-1048": "r.requeue", "CB-1049": "r.owner_guard",
    "CB-1050": "s.authority", "CB-1051": "s.request",
    "CB-1052": "s.telemetry", "CB-1053": "s.pure",
    "CB-1054": "c", "CB-1055": "c", "CB-1056": "c",
}
FILES = {MAIN, PART4, *DOCS.values(), *SRC.values(),
         *("tests/" + x for x in SCRIPTS.values())}


def sources() -> dict[str, str]:
    """Read tracked files; no external services or writes."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in FILES}


def matrix_rows(data: object) -> dict[str, tuple[str, ...]]:
    """Fail closed for missing, duplicated or falsely promoted clauses."""
    if type(data) is not dict or any(type(data.get(p)) is not str for p in (MAIN, PART4)):
        raise ValueError("UNKNOWN: missing matrix")
    found = {}
    for path in (MAIN, PART4):
        for line in data[path].splitlines():
            m = re.match(r"^\s*\|\s*(CB-\d{4})\s*\|", line)
            if m is None or m.group(1) not in IDS:
                continue
            key = m.group(1)
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            group, clue = CLAUSES[key]
            if (key in found or len(cells) != 6 or cells[0] != key
                    or DOCS[group] not in cells[1] or clue not in cells[2]
                    or cells[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos." not in cells[4]
                    or any(f"{x}=unknown" not in cells[4].lower()
                           for x in ("offline", "live", "operando"))
                    or "/issues/1" not in cells[5]
                    or (path == MAIN) != (key in MAIN_IDS)):
                raise ValueError("UNKNOWN: invalid row " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage IDs")
    return found


@lru_cache(maxsize=None)
def scenario(group: str, case: str | None) -> dict:
    """Execute allowlisted local PHP synthetic fixture with bounded runtime."""
    if group not in CASES or case not in CASES[group]:
        raise AssertionError("UNKNOWN: unapproved probe")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP unavailable")
    cmd = [php, str(ROOT / "tests" / SCRIPTS[group])]
    if case is not None:
        cmd.append(case)
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True,
                          check=False, timeout=30)
    if proc.returncode != 0:
        raise AssertionError("UNKNOWN: synthetic scenario failed")
    try:
        result = json.loads(proc.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: bad JSON evidence") from exc
    if type(result) is not dict or not result:
        raise AssertionError("UNKNOWN: no synthetic evidence")
    return result


def observations() -> dict[str, dict]:
    """One reproducible set of actual PHP outputs for all 15 clauses."""
    return {group if case is None else group + "." + case: scenario(group, case)
            for group, cases in CASES.items() for case in cases}


def behavior(key: str, p: dict[str, dict]) -> bool:
    """Check concrete behavior for every ID rather than trusting documentation."""
    g = lambda grp, case: p[grp + "." + case]
    if key == "CB-1042":
        x = g("a", "deterministic")
        y = g("a", "binding")
        return (all(x[k] is True for k in
                    ("same", "fingerprint_same", "work_cas_changed", "session_cas_changed"))
                and re.fullmatch("[0-9a-f]{64}", y["preconditions"]["cas_sha256"]) is not None
                and g("a", "pure")["hits"] == [])
    if key == "CB-1043":
        x, y = g("a", "target"), g("a", "ownership")
        return (x["valid"]["session"]["status"] == "assigned"
                and all(x[k] is True for k in
                        ("stale", "busy", "account", "agent_identity", "capability"))
                and all(y[k] is True for k in
                        ("reserved", "assigned", "occupied", "stale_generation")))
    if key == "CB-1044":
        x = g("p", "preemption")
        return (x["safe"]["allowed"] is True
                and x["blocked"]["allowed"] is False
                and "non_preemptible_outside_safe_point" in x["blocked"]["reasons"]
                and x["stale"]["allowed"] is False
                and x["higher"]["allowed"] is False
                and "running_priority_not_lower" in x["higher"]["reasons"])
    if key == "CB-1045":
        x, y = g("p", "focus"), g("p", "blocked_focus")
        return (x["critical_rejected"] is True and x["incident_rejected"] is True
                and x["protected_selected"]["selected_key"] == "work-critical"
                and x["protected_selected"]["focus"]["influenced"] is True
                and y["excluded_ids"] == ["work-critical"]
                and y["protected_ready_ids"] == [])
    if key == "CB-1046":
        x, y = g("r", "transition"), g("r", "deterministic")
        return (x["valid"]["version"] == 1
                and all(x[k] is True for k in ("no_change", "leave", "recovery"))
                and y["same"] is True and y["same_fingerprint"] is True
                and y["hits"] == [])
    if key == "CB-1047":
        x, y = g("r", "transition"), g("r", "guard")
        return (all(x[k] is True for k in ("no_change", "leave", "recovery"))
                and all(y[k] is True for k in
                        ("stale_generation", "unsafe_preemption", "safe_non_preemptible")))
    if key == "CB-1048":
        x = g("r", "requeue")
        return (x["release_reservation"]["active"] is False
                and x["release_reservation"]["generation"] == 4
                and x["work_item"]["state"] == "queued"
                and x["work_item"]["reservation_id"] is None
                and x["work_item"]["assigned_session_id"] is None
                and x["fence"]["previous_generation"] == 4
                and x["fence"]["next_generation"] == 5
                and x["fence"]["previous_attempt"] == 2
                and x["fence"]["next_attempt"] == 3
                and x["handoff"]["to_session_id"] is None)
    if key == "CB-1049":
        x = g("r", "owner_guard")
        return (x["before"]["allowed"] is True
                and x["old_after"]["allowed"] is False
                and "stale_generation" in x["old_after"]["reasons"]
                and "work_unowned" in x["old_after"]["reasons"]
                and x["new_generation_unowned"]["allowed"] is False)
    if key == "CB-1050":
        x = g("s", "authority")
        return (x["validated"]["selected"]["generation"] == x["candidate"]["generation"]
                and x["validated"]["selected"]["required_capabilities"] ==
                x["candidate"]["required_capabilities"]
                and all(x[k] is True for k in
                        ("generation_rejected", "capabilities_rejected", "authority_rejected"))
                and "authority" not in x["validated"]["selected"])
    if key == "CB-1051":
        x, y = g("s", "request"), g("s", "drift")
        return (x["valid"]["policy_ref"] == "factory-dispatcher-v2"
                and x["wrong_policy"] is True and y["wrong_policy"] is True
                and y["wrong_fingerprint"] is True)
    if key == "CB-1052":
        x = g("s", "telemetry")
        return (x["same"] is True
                and x["validated"]["telemetry"]["ready_not_selected"] == ["work-b"]
                and set(x["validated"]["telemetry"]["excluded"]) == {"work-c"}
                and all(x[k] is True for k in ("fabricated", "secret", "pii")))
    if key == "CB-1053":
        x = g("s", "pure")
        return x["pure"] is True and x["hits"] == []
    if key == "CB-1054":
        x = p["c"]
        return (x["critical_independent"]["decision"] == "independent_channel"
                and x["unknown_independent"]["decision"] == "owner_review"
                and x["unknown_primary"]["decision"] == "owner_review")
    if key == "CB-1055":
        x = p["c"]
        return (x["warning_primary"]["decision"] == "primary_channel"
                and x["warning_primary"]["channel"] == "primary"
                and x["primary_healthy"]["decision"] == "primary_channel"
                and x["primary_healthy"]["channel"] == "primary")
    if key == "CB-1056":
        x = p["c"]
        return (x["critical_independent"]["decision"] == "independent_channel"
                and x["critical_independent"]["channel"] == "independent"
                and x["unknown_independent"]["decision"] == "owner_review"
                and x["unknown_primary"]["decision"] == "owner_review"
                and x["warning_no_alert"]["decision"] == "no_alert"
                and x["warning_escalated"]["decision"] == "independent_channel"
                and x["unknown_confidence"]["confidence"] == "unknown")
    return False


def verdict(key: object, data: object, proof: object) -> str:
    """Unproven remote atomicity stays UNKNOWN even with passing local CAS."""
    if type(key) is not str or key not in IDS or type(data) is not dict or type(proof) is not dict:
        return UNKNOWN
    try:
        if set(data) != FILES or any(type(data[p]) is not str or not data[p] for p in FILES):
            return UNKNOWN
        matrix_rows(data)
        group, clue = CLAUSES[key]
        if clue not in data[DOCS[group]] or "final class " not in data[SRC[group]]:
            return UNKNOWN
        if type(proof.get(PROBES[key])) is not dict or not proof[PROBES[key]]:
            return UNKNOWN
        if not behavior(key, proof):
            return UNKNOWN
    except (KeyError, TypeError, ValueError, IndexError, AttributeError):
        return UNKNOWN
    return UNKNOWN if key in DOCUMENTED_UNKNOWN else LOCAL


def operational_state(_key: object, _evidence: object = None) -> str:
    """No local artifact certifies persisted atomicity, delivery or production."""
    return UNKNOWN


class BrechasControlbot68Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 specific local assertions or explicit unproven gap."""
        data, proof = sources(), observations()
        rows = matrix_rows(data)
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(CLAUSES), set(IDS))
        self.assertEqual(set(PROBES), set(IDS))
        self.assertEqual(set(rows), set(IDS))
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(UNKNOWN if key in DOCUMENTED_UNKNOWN else LOCAL,
                                 verdict(key, data, proof))
                self.assertEqual(rows[key][3], "NO_VERIFICABLE")
        self.assertTrue(behavior("CB-1042", proof))

    def test_falla_cerrado(self) -> None:
        """AC-02: absent, duplicate, indent-duplicate and promoted fail closed."""
        data, proof = sources(), observations()
        for key in IDS:
            path = MAIN if key in MAIN_IDS else PART4
            line = next(x for x in data[path].splitlines() if x.startswith("| " + key + " |"))
            for bad in (data[path].replace(line, "", 1), data[path] + "\n" + line,
                        data[path] + "\n   " + line,
                        data[path].replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                with self.subTest(id=key):
                    self.assertEqual(UNKNOWN, verdict(key, {**data, path: bad}, proof))
            group, clue = CLAUSES[key]
            self.assertEqual(UNKNOWN, verdict(
                key, {**data, DOCS[group]: data[DOCS[group]].replace(clue, "")}, proof))
            self.assertEqual(UNKNOWN, verdict(key, {**data, SRC[group]: ""}, proof))
            self.assertEqual(UNKNOWN, verdict(key, data, {**proof, PROBES[key]: {}}))
        self.assertEqual(UNKNOWN, verdict("CB-9999", data, proof))
        self.assertEqual(UNKNOWN, verdict("CB-1043", {}, proof))
        self.assertEqual(UNKNOWN, verdict("CB-1043", data, {}))

    def test_sin_datos_reales(self) -> None:
        """AC-03: allowlisted PHP fixtures only; no network, secrets or PII."""
        data = sources()
        self.assertEqual(set(data), FILES)
        own = (ROOT / "tests/test_brechas_controlbot_68.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json",
                                      "pathlib", "re", "shutil", "subprocess", "unittest"})
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in FILES))
        self.assertEqual(UNKNOWN, operational_state("CB-1056", {"delivered": True}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: fake CI, review or live receipts never grant authority."""
        data, proof = sources(), observations()
        for key in IDS:
            with self.subTest(id=key):
                for fake in (None, {}, {"ci": "success", "sha": "a" * 40},
                             {"production": "GREEN", "authorized": True},
                             {"atomic_transaction": "committed", "review": "approved"}):
                    self.assertEqual(UNKNOWN, operational_state(key, fake))
                self.assertEqual(UNKNOWN if key in DOCUMENTED_UNKNOWN else LOCAL,
                                 verdict(key, data, proof))


if __name__ == "__main__":
    unittest.main()
