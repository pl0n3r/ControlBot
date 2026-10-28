"""Regresiones offline ControlBot #1017: CB-0137..CB-0151.

Los escenarios sintéticos no acreditan permisos live, backups ni restore reales.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "matriz-cobertura.md"
IDS = tuple(f"CB-{i:04d}" for i in range(137, 152))
UNKNOWN = "UNKNOWN"
SCRIPTS = {"rights": "decision_rights_scenarios.php",
           "recovery": "recovery_profile_scenarios.php"}


@lru_cache(maxsize=None)
def run(domain: str, case: str) -> dict:
    if domain not in SCRIPTS:
        raise AssertionError("Not an allowlisted offline harness")
    command = ["php", str(ROOT / "tests" / SCRIPTS[domain]), case]
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True,
                            timeout=30, check=False)
    if result.returncode:
        raise AssertionError(f"{domain}/{case} failed: {result.stderr[:200]}")
    response = json.loads(result.stdout)
    if not isinstance(response, dict):
        raise AssertionError("Fixture response is not a mapping")
    return response


def matrix_states(content: str) -> dict[str, str]:
    states = {}
    for item in IDS:
        rows = [line for line in content.splitlines()
                if line.startswith(f"| {item} |")]
        if len(rows) != 1:
            states[item] = UNKNOWN
            continue
        cells = [cell.strip() for cell in rows[0].strip("|").split("|")]
        states[item] = ("NO_VERIFICABLE" if len(cells) == 6
                        and cells[0] == item and cells[3] == "NO_VERIFICABLE"
                        and "Offline=UNKNOWN" in cells[4] else UNKNOWN)
    return states


def production_status(_item: str, _offline: object = None) -> str:
    return UNKNOWN


class BrechasControlbot10Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: un predicado ejecutable por ID, sin inferir producción."""
        a = run("rights", "allow-exact")
        d = run("rights", "deterministic")
        invalid = run("rights", "invalid")
        cross = run("rights", "cross-venture")
        restricted = run("rights", "restrictions")
        owner = run("rights", "owner-decision")
        valid = run("recovery", "valid")
        p = valid["profile"]
        doc = (ROOT / "docs" / "disaster-recovery-contract.md").read_text(encoding="utf-8")
        source = (ROOT / "src" / "RecoveryProfile.php").read_text(encoding="utf-8")
        matrix_test = (ROOT / "tests" / "test_recovery_profile_matrix.py").read_text(encoding="utf-8")
        proof = {
            "CB-0137": (a["allowed"] == {"decision": "allow", "reasons": ["authorized"]}
                        and d["first"] == d["second"]
                        and "identity_mismatch" in d["first"]["reasons"]
                        and invalid["expired_grant"]["decision"] == "deny"),
            "CB-0138": (cross["result"] == {"decision": "deny", "reasons": ["scope_mismatch"]}
                        and a["wrong_capability"] == {
                            "decision": "deny", "reasons": ["capability_not_granted"]}),
            "CB-0139": (restricted["budget"] == {
                            "decision": "owner_decision_required",
                            "reasons": ["budget_approval_required"]}
                        and restricted["authority"] == {
                            "decision": "owner_decision_required",
                            "reasons": ["authority_escalation_required"]}
                        and owner["result"]["decision"] == "owner_decision_required"),
            "CB-0140": (restricted["policy"] == {
                            "decision": "deny", "reasons": ["policy_not_active"]}
                        and restricted["budget"]["decision"] != "allow"
                        and a["wrong_capability"]["decision"] != "allow"),
            "CB-0141": ("Factory #305" in doc
                        and "no crea un segundo motor" in doc
                        and "class BackupReceipt" not in source
                        and "class BackupGate" not in source),
            "CB-0142": (p["version"] == 1
                        and p["project_ref"].startswith("controlbot:project/")
                        and p["manifest_ref"].startswith("controlbot:recovery-manifest/")),
            "CB-0143": ("BackupReceipt" in doc and "BackupGate" in doc
                        and "no valida un backup real" in doc
                        and "backup_receipt" not in p and "restore_authorized" not in p),
            "CB-0144": (p["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                        and run("recovery", "bad-rpo")["blocked"]
                        and "observed_rpo" not in p and "observed_rto" not in p),
            "CB-0145": (p["strategy"] == {
                            "copies_required": 3, "media_types_required": 2,
                            "offsite_required": True, "immutable_required": True,
                            "undetected_restore_failures_target": 0}
                        and p["encryption_required"] is True
                        and run("recovery", "bad-strategy")["blocked"]),
            "CB-0146": (run("recovery", "sensitive-manifest-ref")["blocked"]
                        and run("recovery", "unknown-field")["blocked"]
                        and all(k not in p for k in ("token", "password", "secret"))),
            "CB-0147": (all(run("recovery", case)["effective_status"] == "unknown"
                            for case in ("stale", "unknown", "missing-profile"))
                        and run("recovery", "unknown-with-provenance")["blocked"]
                        and run("recovery", "stale-without-provenance")["blocked"]),
            "CB-0148": (valid["effective_status"] == "configured"
                        and "No confirma backups" in doc
                        and "HEALTHY" not in json.dumps(p)),
            "CB-0149": (set(p) == {
                            "version", "project_ref", "manifest_ref", "targets", "retention",
                            "sources", "strategy", "encryption_required",
                            "restore_drill_cadence_hours", "source_ref", "observed_at", "freshness"}
                        and run("recovery", "explicit-not-applicable")[
                            "sources"]["configuration"] == "not_applicable"
                        and run("recovery", "unknown-field")["blocked"]),
            "CB-0150": ("sin estado externo" in doc and "Revertir" in doc
                        and all(x not in source for x in (
                            "curl_exec", "file_get_contents", "PDO(", "shell_exec", "exec("))),
            "CB-0151": ("Offline Recovery profile matrix" in matrix_test
                        and "RecoveryProfile::effectiveStatus" in matrix_test
                        and "No confirma backups" in doc),
        }
        self.assertEqual(set(proof), set(IDS))
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(states), set(IDS))
        for item in IDS:
            with self.subTest(requirement=item):
                self.assertTrue(proof[item], f"{item}: missing offline verification; UNKNOWN")
                self.assertIn(states[item], ("NO_VERIFICABLE", UNKNOWN))
                self.assertEqual(production_status(item, proof[item]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: datos ausentes, repetidos o manipulados no promueven estados."""
        negative = {
            "CB-0137": run("rights", "deterministic")["first"]["decision"] == "deny",
            "CB-0138": run("rights", "cross-venture")["result"]["decision"] == "deny",
            "CB-0139": run("rights", "restrictions")["budget"]["decision"] != "allow",
            "CB-0140": run("rights", "restrictions")["policy"]["decision"] == "deny",
            "CB-0144": run("recovery", "bad-rpo")["blocked"],
            "CB-0145": run("recovery", "bad-strategy")["blocked"],
            "CB-0146": run("recovery", "sensitive-manifest-ref")["blocked"],
            "CB-0147": run("recovery", "unknown-with-provenance")["blocked"],
            "CB-0149": run("recovery", "unknown-field")["blocked"],
        }
        for item, failed_closed in negative.items():
            with self.subTest(requirement=item):
                self.assertTrue(failed_closed)
        matrix = MATRIX.read_text(encoding="utf-8")
        for item in IDS:
            rows = [line for line in matrix.splitlines()
                    if line.startswith(f"| {item} |")]
            without = "\n".join(line for line in matrix.splitlines() if line not in rows)
            self.assertEqual(matrix_states(without)[item], UNKNOWN)
            if rows:
                self.assertEqual(matrix_states(matrix + "\n" + rows[0])[item], UNKNOWN)
                corrupt = matrix.replace(
                    rows[0], rows[0].replace("NO_VERIFICABLE", "CUBIERTO", 1))
                self.assertEqual(matrix_states(corrupt)[item], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures permitidos y proyección sin secretos ni PII."""
        for script in SCRIPTS.values():
            self.assertTrue((ROOT / "tests" / script).is_file())
        untrusted = run("rights", "untrusted")
        self.assertFalse(untrusted["leaked"])
        self.assertEqual(untrusted["action_claims"]["decision"], "deny")
        self.assertEqual(untrusted["context_secret"]["decision"], "deny")
        p = run("recovery", "valid")["profile"]
        serialized = json.dumps(p, sort_keys=True).lower()
        for forbidden in ("password", "token", "api_key", "bearer ", "email",
                          "personal_data", "customer_payload", "backup_receipt"):
            self.assertNotIn(forbidden, serialized)
        for forbidden in ("live_ready", "deploy", "go_live", "restore_allowed"):
            self.assertNotIn(forbidden, p)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ningún contraste offline concede HEALTHY o estado live."""
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(states), set(IDS))
        self.assertEqual(states["CB-0141"], "NO_VERIFICABLE")
        for case in ("stale", "unknown", "missing-profile"):
            self.assertEqual(run("recovery", case)["effective_status"], "unknown")
        self.assertTrue(all(s in (UNKNOWN, "NO_VERIFICABLE") for s in states.values()))
        actual = {item: production_status(item, states[item]) for item in IDS}
        self.assertEqual(set(actual), set(IDS))
        self.assertEqual(set(actual.values()), {UNKNOWN})


if __name__ == "__main__":
    unittest.main()
