"""#979 — regressions for every real ProductionOperation/BackupGate capability.

All fixtures synthetic. Running these tests never touches a remote host.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / "tests/backup_gate_blocks_mutations_scenarios.php"


def run_case(name: str) -> dict:
    proc = subprocess.run(
        ["php", str(SCENARIO), name], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=20,
    )
    if proc.returncode:
        raise AssertionError(f"{name}: {proc.stdout} {proc.stderr}")
    value = json.loads(proc.stdout)
    assert value["mode"] == "SYNTHETIC_ONLY"
    assert value["production_executed"] is False
    assert value["backup_verified"] is False
    assert value["readiness"] == "UNKNOWN"
    return value


class Cb45BackupGateContratoTests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        doc = run_case("principal")
        self.assertGreaterEqual(len(doc["protected"]), 5)
        self.assertEqual(doc["protected"], doc["gate_types"])
        self.assertEqual([], doc["unprotected"], "New unprotected mutating operation")
        self.assertEqual([], doc["exception_violations"])
        self.assertEqual(
            "database.backup", doc["production_writes"]["database.backup"],
        )
        for capability in doc["protected"]:
            with self.subTest(capability=capability):
                missing = doc["checks"][capability]["missing"]
                self.assertIs(missing["allowed"], False)
                self.assertEqual("backup_receipt_required", missing["reason"])
                # A valid *synthetic* shape is only a positive unit-test control.
                valid = doc["checks"][capability]["fixture"]
                self.assertIs(valid["allowed"], True)
                self.assertEqual("backup_verified", valid["reason"])

    def test_falla_cerrado(self) -> None:
        checks = run_case("negatives")["checks"]
        reasons = {
            "missing": "backup_receipt_required",
            "not_ready": "backup_receipt_invalid",
            "extra": "backup_receipt_invalid",
            "missing_integrity": "backup_receipt_invalid",
            "wrong_type": "backup_type_mismatch",
            "expired": "backup_not_usable_for_write",
            "unbounded": "backup_not_usable_for_write",
            "stale": "backup_not_usable_for_write",
            "future": "backup_not_usable_for_write",
            "project": "backup_scope_mismatch",
            "run": "backup_scope_mismatch",
            "issue": "backup_scope_mismatch",
            "owner": "owner_approval_required",
            "forbidden": "policy_denied",
        }
        self.assertGreaterEqual(len(checks), 5)
        for capability, cases in checks.items():
            self.assertEqual(set(reasons), set(cases))
            for case, result in cases.items():
                with self.subTest(capability=capability, case=case):
                    self.assertIs(result["allowed"], False)
                    self.assertEqual(reasons[case], result["reason"])
                    self.assertIsNone(result["evidence"])

    def test_sin_datos_reales(self) -> None:
        checks = run_case("privacy")["checks"]
        self.assertGreaterEqual(len(checks), 5)
        for capability, result in checks.items():
            with self.subTest(capability=capability):
                self.assertIs(result["leaks_ref"], False)
                self.assertIs(result["leaks_digest"], False)
                self.assertEqual("ready", result["evidence"]["status"])
                self.assertNotIn("artifact_ref", result["evidence"])
                self.assertNotIn("integrity", result["evidence"])
        source = SCENARIO.read_text(encoding="utf-8")
        for forbidden in ("curl_exec(", "shell_exec(", "file_put_contents(",
                          "mysqli_connect(", "new PDO(", "system("):
            self.assertNotIn(forbidden, source)

    def test_estados_unknown_explicitos(self) -> None:
        doc = run_case("unknown")
        self.assertIs(doc["checks"]["unknown"]["allowed"], False)
        self.assertEqual("policy_denied", doc["checks"]["unknown"]["reason"])
        self.assertIsNone(doc["checks"]["unknown"]["evidence"])
        self.assertIs(doc["checks"]["read"]["allowed"], True)
        self.assertIs(doc["checks"]["read"]["backup_required"], False)
        self.assertGreaterEqual(len(doc["prohibitions"]), 6)
        for capability, policy in doc["prohibitions"].items():
            with self.subTest(capability=capability):
                result = doc["checks"][capability]
                self.assertIs(result["allowed"], False)
                self.assertEqual(
                    "owner_approval_required" if policy == "owner_required" else "policy_denied",
                    result["reason"],
                )
                self.assertIsNone(result["evidence"])


if __name__ == "__main__":
    unittest.main()
