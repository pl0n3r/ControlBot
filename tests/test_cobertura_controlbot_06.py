#!/usr/bin/env python3
"""#1189: contracts observed in real ControlBot PHP with disposable fixtures.

These assertions exercise the committed B2 preflight, backup receipt and backup
gate through their synthetic PHP scenarios. They NEVER certify a provider,
offsite object, authorized operator, real restore or production HEALTH.
No state from docs/matriz-cobertura*.md is read or asserted here.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset(
    {"CB-0050"} | {f"CB-{n:04d}" for n in range(51, 58)}
    | {f"CB-{n:04d}" for n in range(59, 70)}
)
NON_OFFLINE = {
    "CB-0050": ("C", "El permiso de preparacion A de #858 no autoriza proveedor ni escrituras"),
    "CB-0056": ("B", "Solo el operador autorizado y la API B2 real pueden acreditar bucket, object lock o restore"),
    "CB-0057": ("C", "La instruccion de ejecucion de tests y rollback es guia operativa, no una funcion"),
    "CB-0060": ("C", "Limite documental: el checksum offline no autentica proveedor o emisor"),
    "CB-0061": ("C", "Comandos de regresion y necesidad futura de streaming son guia de proceso"),
    "CB-0066": ("C", "No certificacion de bytes, proveedor ni backup offsite es limite de autoridad"),
    "CB-0067": ("C", "Restaurabilidad y HEALTH real exigen evidencia externa autorizada"),
    "CB-0068": ("B", "Verificacion de contenido/proveedor y restore drill requieren ejecucion real autorizada"),
}
OFFLINE = IDS - NON_OFFLINE.keys()
SCENARIOS = {
    "b2_recovery_preflight_scenarios.php": frozenset({
        "positive", "public", "no-retention", "lock-disabled", "redacted",
        "encryption-redacted", "encryption-off", "missing", "malformed",
        "secret-injection", "id-injection", "nested-injection",
        "unreadable-with-value",
    }),
    "backup_integrity_scenarios.php": frozenset({
        "shape", "content", "guard", "privacy",
    }),
    "backup_gate_scenarios.php": frozenset({
        "missing", "scope", "causality", "freshness", "readonly", "evidence",
    }),
}
# In CI these keys enable coverage of *actual PHP source*, not mock behavior.
SAFE_ENV = (
    "PATH", "LANG", "LC_ALL", "CONTROLBOT_REAL_PHP",
    "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
    "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
)


@lru_cache(maxsize=40)
def scenario(script: str, mode: str) -> dict:
    """Execute the already-committed PHP implementation, never provider APIs."""
    if mode not in SCENARIOS.get(script, ()):
        raise AssertionError("Unapproved fixture scenario")
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP CLI required; real behavior not exercised")
    fixture = ROOT / "tests" / script
    if not fixture.is_file():
        raise AssertionError("Real PHP scenario is absent")
    env = {key: os.environ[key] for key in SAFE_ENV if key in os.environ}
    env["APP_ENV"] = "testing"
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(fixture), mode],
        cwd=ROOT, env=env, text=True, capture_output=True,
        timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("Synthetic PHP contract failed: "
                             + script + "/" + mode + ": " + result.stderr[-500:])
    try:
        output = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("PHP failed to supply structured evidence") from exc
    if not isinstance(output, dict):
        raise AssertionError("PHP scenario response must be an object")
    return output


def b2(mode: str) -> dict:
    return scenario("b2_recovery_preflight_scenarios.php", mode)


def integrity(mode: str) -> dict:
    return scenario("backup_integrity_scenarios.php", mode)


def gate(mode: str) -> dict:
    return scenario("backup_gate_scenarios.php", mode)


class CoberturaControlbot06Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: prove every offline row by executing the real PHP methods."""
        self.assertEqual(len(IDS), 19)
        self.assertEqual(OFFLINE | NON_OFFLINE.keys(), IDS)
        self.assertFalse(OFFLINE & NON_OFFLINE.keys())
        self.assertTrue(all(kind in ("B", "C", "D") and len(reason) > 28
                            for kind, reason in NON_OFFLINE.values()))
        seen: set[str] = set()

        def proved(key: str, condition: bool) -> None:
            self.assertIn(key, OFFLINE)
            self.assertTrue(condition, "Real PHP behavior missing for " + key)
            seen.add(key)

        good = b2("positive")
        expected = {
            "version": 1, "configuration_status": "consistent",
            "operational_status": "UNKNOWN", "reasons": [],
        }
        proved("CB-0051", good == expected
               and b2("id-injection").get("error_code") == "invalid_shape")
        proved("CB-0052", good["configuration_status"] == "consistent"
               and "private_bucket_required" in b2("public")["reasons"]
               and "object_lock_disabled" in b2("lock-disabled")["reasons"]
               and "default_retention_missing" in b2("no-retention")["reasons"])
        proved("CB-0053", good["configuration_status"] == "consistent"
               and "encryption_missing" in b2("encryption-off")["reasons"]
               and "encryption_unreadable" in b2("encryption-redacted")["reasons"])
        proved("CB-0054", "retention_unreadable" in b2("redacted")["reasons"]
               and b2("unreadable-with-value")["error_code"] == "invalid_shape"
               and "default_retention_missing" in b2("no-retention")["reasons"])
        no_metadata = b2("missing")
        proved("CB-0055", set(good) == {
                   "version", "configuration_status", "operational_status", "reasons"}
               and no_metadata["operational_status"] == "UNKNOWN"
               and no_metadata["configuration_status"] == "incomplete"
               and no_metadata["reasons"] == ["missing_metadata"])

        checks = integrity("content")
        proved("CB-0059", checks["match"] ==
               {"status": "MATCH", "reason": "checksum_match"}
               and checks["uppercase"] == checks["match"]
               and checks["mismatch"] ==
               {"status": "MISMATCH", "reason": "checksum_mismatch"}
               and integrity("guard")["scope"] ==
               {"status": "UNKNOWN", "reason": "scope_mismatch"})
        freshness = gate("freshness")
        proved("CB-0062", freshness["at_24h"]["allowed"] is True
               and freshness["at_24h"]["reason"] == "backup_verified"
               and freshness["stale_unexpired"]["allowed"] is False)
        proved("CB-0063", freshness["no_expiration"]["allowed"] is False
               and freshness["expired"]["allowed"] is False
               and freshness["stale_unexpired"]["reason"] ==
               "backup_not_usable_for_write")
        proved("CB-0064", freshness["read_unbounded"]["allowed"] is True
               and freshness["read_unbounded"]["backup_required"] is False
               and freshness["no_expiration"]["reason"] ==
               "backup_not_usable_for_write")
        kept = gate("causality")
        proved("CB-0065", kept["ready"]["allowed"] is True
               and kept["ready"]["reason"] == "backup_verified"
               and gate("evidence")["contains_digest"] is False)
        proved("CB-0069", freshness["at_24h"]["allowed"] is True
               and freshness["stale_unexpired"]["allowed"] is False
               and freshness["no_expiration"]["allowed"] is False
               and freshness["wrong_scope"]["allowed"] is False)
        self.assertEqual(seen, OFFLINE)

    def test_falla_cerrado(self) -> None:
        """AC-02: fail closed for permissions, tainted metadata and time."""
        for mode in ("public", "no-retention", "lock-disabled", "redacted",
                     "encryption-redacted", "encryption-off", "missing"):
            with self.subTest(mode=mode):
                data = b2(mode)
                self.assertEqual(data["configuration_status"], "incomplete")
                self.assertEqual(data["operational_status"], "UNKNOWN")
                self.assertTrue(data["reasons"])
        for mode in ("malformed", "secret-injection", "id-injection",
                     "nested-injection", "unreadable-with-value"):
            with self.subTest(mode=mode):
                self.assertEqual(b2(mode), {
                    "error_code": "invalid_shape", "operational_status": "UNKNOWN"})
        guard = integrity("guard")
        for mode, why in (
            ("scope", "scope_mismatch"), ("premature", "receipt_unusable"),
            ("expired", "receipt_unusable"), ("stale", "receipt_stale"),
        ):
            self.assertEqual(guard[mode], {"status": "UNKNOWN", "reason": why})
        self.assertTrue(guard["invalid_scope"])
        self.assertTrue(guard["invalid_time"])
        for mode in ("short", "non_hex", "long"):
            self.assertTrue(integrity("shape")[mode], mode)
        self.assertFalse(gate("missing")["allowed"])
        for receipt in gate("scope").values():
            self.assertEqual(receipt["reason"], "backup_scope_mismatch")
            self.assertFalse(receipt["allowed"])
        for mode in ("no_expiration", "expired", "stale_unexpired",
                     "future_verification", "wrong_scope"):
            self.assertFalse(gate("freshness")[mode]["allowed"], mode)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network calls, credentials, actual backup or leaked bytes."""
        b2_good = b2("positive")
        self.assertEqual(b2_good["operational_status"], "UNKNOWN")
        self.assertEqual(set(b2_good), {
            "version", "configuration_status", "operational_status", "reasons"})
        evidence = integrity("privacy")
        self.assertEqual(set(evidence["result"]), {"status", "reason"})
        self.assertNotIn("integrity", evidence["safe_receipt"])
        self.assertNotIn("artifact_ref", evidence["safe_receipt"])
        self.assertNotIn("synthetic-backup-data-only/no-real-records",
                         json.dumps(evidence))
        decision = gate("evidence")
        self.assertFalse(decision["contains_artifact_ref"])
        self.assertFalse(decision["contains_digest"])
        self.assertFalse(decision["contains_payload_key"])
        self.assertEqual(decision["evidence"]["status"], "ready")
        self.assertNotIn("integrity", decision["evidence"])
        self.assertNotIn("HEALTHY", json.dumps((b2_good, decision, evidence)))
        # The runner's subprocess environment is a strict allowlist.
        self.assertFalse(set(SAFE_ENV) & {
            "GITHUB_TOKEN", "GH_TOKEN", "DB_PASSWORD", "MYSQL_PWD",
            "BACKBLAZE_APPLICATION_KEY", "B2_APPLICATION_KEY",
        })
        self.assertEqual(len(SCENARIOS), 3)

    def test_validar_exigido(self) -> None:
        """AC-04 checks CI wiring; GitHub exact-HEAD Validar is still mandatory."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        runner = (ROOT / "scripts/generate_coverage.py").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)
        self.assertIn('"unittest"', runner)
        self.assertIn('"discover"', runner)
        self.assertIn('"test_*.py"', runner)


if __name__ == "__main__":
    unittest.main()
