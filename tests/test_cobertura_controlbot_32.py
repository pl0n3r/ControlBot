#!/usr/bin/env python3
"""ControlBot #1215: executable offline D-059 backup contract coverage.

Only existing runtime behavior counts. Synthetic receipts are not real
Hostinger backups, restores, owner approvals, deployment or live evidence.
Do not inspect the coverage matrix: reconciliation is a separate leaf.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import runpy
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SHA = "a" * 40
TARGET = "control.condorapp.com.co"
GATES = (
    "owner_approval", "hostinger_operator", "legal_privacy", "backup_restore",
    "rollback", "artifact_provenance", "owner_only_https", "production_observation",
)
IDS = {f"CB-{n:04d}" for n in range(514, 532)}
# B requires real operator/Hostinger evidence; C states policy or scope, not
# executable behavior. Do not promote these rows based on synthetic tests.
NON_OFFLINE = {
    "CB-0514": ("C", "D-059 scope and non-authorization are stated policy"),
    "CB-0515": ("B", "PR draft status and #627 evidence need real GitHub/operator state"),
    "CB-0516": ("B", "A pre-merge public_html snapshot requires actual provenance"),
    "CB-0517": ("C", "No-live-database exception is a scope note"),
    "CB-0520": ("B", "Actual backup creation time requires a Hostinger receipt"),
    "CB-0523": ("B", "Restore procedure must reference a real restore point"),
    "CB-0527": ("B", "Post-revert private HTTP 403/404 requires live observation"),
    "CB-0528": ("B", "Fallback restoration requires real restore verification"),
    "CB-0530": ("B", "A real backup cannot be inferred from a local fixture"),
}
OFFLINE = IDS - set(NON_OFFLINE)
ALLOWED_CASES = frozenset({
    "missing", "scope", "causality", "readonly", "freshness", "evidence",
})


@lru_cache(maxsize=1)
def real_plan() -> dict:
    """Load the real, pure Hostinger gate evaluator without invoking main."""
    return runpy.run_path(str(ROOT / "scripts" / "hostinger_go_live_plan.py"))


def evaluate(payload: object) -> dict:
    return real_plan()["evaluate"](payload)


def fixture() -> dict:
    """Only opaque, synthetic evidence, never a deploy-capable claim."""
    return {
        "version": 1, "target": TARGET, "sha": SHA, "phase": "construccion",
        "evidence": [
            {"gate": gate, "source_ref": f"controlbot:fixture/{gate}",
             "scope": TARGET, "sha": SHA, "verified": True, "fresh": True}
            for gate in GATES
        ],
    }


@lru_cache(maxsize=6)
def php_case(case: str) -> dict:
    """Exercise actual PHP BackupGate/BackupReceipt via existing fixtures."""
    if case not in ALLOWED_CASES:
        raise ValueError("Unapproved fixture")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP unavailable; no synthetic pass permitted")
    source = ROOT / "tests" / "backup_gate_scenarios.php"
    if not source.is_file():
        raise AssertionError("Actual PHP scenario file absent")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(source), case],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=15,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("Real PHP scenario failed: " + result.stderr[-400:])
    return json.loads(result.stdout)


class CoberturaControlbot32Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each offline clause invokes real PHP or Python runtime."""
        self.assertEqual(18, len(IDS))
        self.assertEqual(9, len(OFFLINE))
        self.assertEqual(IDS, OFFLINE | set(NON_OFFLINE))
        self.assertTrue(all(kind in {"B", "C"} and reason
                            for kind, reason in NON_OFFLINE.values()))

        evidence = php_case("evidence")
        readonly = php_case("readonly")
        scope = php_case("scope")
        missing = php_case("missing")
        causality = php_case("causality")
        report = evaluate(fixture())
        no_backup = fixture()
        no_backup["evidence"] = [row for row in no_backup["evidence"]
                                 if row["gate"] != "backup_restore"]
        denied = evaluate(no_backup)
        bad_ref = fixture()
        bad_ref["evidence"][0]["source_ref"] = "https://example.invalid/backup"
        ref_denied = evaluate(bad_ref)

        assertions = {
            # CB-0518: real PHP evidence never serializes backup material.
            "CB-0518": (
                evidence["allowed"] is True
                and not evidence["contains_payload_key"]
                and not evidence["contains_digest"]
                and not evidence["contains_artifact_ref"]
                and ref_denied["reason"] == "invalid_evidence"
            ),
            # CB-0519: read permissions do not grant destructive access.
            "CB-0519": (
                readonly["read"]["allowed"] is True
                and readonly["owner"]["allowed"] is False
                and readonly["owner"]["reason"] == "owner_approval_required"
            ),
            # CB-0521: opaque refs accepted for manual review, URLs denied.
            "CB-0521": (
                report["decision"] == "REVIEW_ONLY"
                and ref_denied["decision"] == "NO_GO"
            ),
            # CB-0522: wrong resource, project or run cannot establish scope.
            "CB-0522": (
                set(scope) == {"project", "environment", "resource", "run_id", "issue"}
                and all(not row["allowed"]
                        and row["reason"] == "backup_scope_mismatch"
                        for row in scope.values())
            ),
            # CB-0524: assertion alone or incomplete receipt not sufficient.
            "CB-0524": (
                not missing["allowed"]
                and missing["reason"] == "backup_receipt_required"
                and not causality["not_ready"]["allowed"]
                and denied["decision"] == "NO_GO"
            ),
            # CB-0525: a missing backup gate prevents even offline review.
            "CB-0525": (
                "backup_restore" in denied["missing_gates"]
                and denied["gate_status"]["backup_restore"] == "MISSING"
            ),
            # CB-0526: other operational gates remain mandatory.
            "CB-0526": (
                report["decision"] == "REVIEW_ONLY"
                and report["deploy_authorized"] is False
                and report["go_live_authorized"] is False
            ),
            # CB-0529: owner decision cannot be inferred from backup receipt.
            "CB-0529": (
                not readonly["owner"]["allowed"]
                and report["execution"] is False
                and report["runtime_authorized"] is False
            ),
            # CB-0531: all synthetic gates are NOT D-059/live approval.
            "CB-0531": (
                report["decision"] != "GO"
                and report["go_live_authorized"] is False
                and denied["decision"] == "NO_GO"
            ),
        }
        self.assertEqual(OFFLINE, set(assertions))
        for clause, result in assertions.items():
            with self.subTest(clause=clause):
                self.assertTrue(result, clause + ": no executable evidence")

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, ambiguous, stale and unauthorized evidence denies."""
        for gate in GATES:
            payload = fixture()
            payload["evidence"] = [row for row in payload["evidence"]
                                   if row["gate"] != gate]
            denied = evaluate(payload)
            self.assertEqual("NO_GO", denied["decision"])
            self.assertIn(gate, denied["missing_gates"])
            self.assertIs(denied["execution"], False)
        for ref in (
            "https://example.invalid/backup", "controlbot:fixture/token",
            "controlbot:fixture/secret", "controlbot:",
        ):
            payload = fixture()
            payload["evidence"][0]["source_ref"] = ref
            self.assertEqual("NO_GO", evaluate(payload)["decision"])
        for mutation in (
            {"target": "other.invalid"}, {"phase": "live"},
            {"sha": "not-a-sha"}, {"version": 2},
        ):
            self.assertEqual("NO_GO", evaluate({**fixture(), **mutation})["decision"])
        for field, invalid in (
            ("scope", "other.invalid"), ("sha", "b" * 40),
            ("verified", False), ("fresh", False),
        ):
            payload = fixture()
            payload["evidence"][0][field] = invalid
            self.assertEqual("NO_GO", evaluate(payload)["decision"])
        for key in ("project", "resource", "run_id", "issue", "environment"):
            denied = php_case("scope")[key]
            self.assertIs(denied["allowed"], False)
            self.assertEqual("backup_scope_mismatch", denied["reason"])
        for key in ("expired", "stale_unexpired", "no_expiration"):
            denied = php_case("freshness")[key]
            self.assertIs(denied["allowed"], False)
            self.assertEqual("backup_not_usable_for_write", denied["reason"])
        self.assertEqual(
            "owner_approval_required", php_case("readonly")["owner"]["reason"],
        )

    def test_sin_datos_reales(self) -> None:
        """AC-03: no live IO, secrets or personal identities in fixture."""
        payload = fixture()
        report = evaluate(payload)
        self.assertEqual("construccion", payload["phase"])
        self.assertEqual("a" * 40, payload["sha"])
        self.assertTrue(all(row["source_ref"].startswith("controlbot:fixture/")
                            for row in payload["evidence"]))
        for flag in ("runtime_authorized", "deploy_authorized",
                     "go_live_authorized", "execution"):
            self.assertIs(report[flag], False)
        self.assertEqual("REVIEW_ONLY", report["decision"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            own, r"(?m)^\s*(?:from|import)\s+(?:requests|socket|httpx|urllib)\b",
        )
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[A-Za-z0-9_.+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}")
        self.assertNotIn("secret=", json.dumps(payload))
        self.assertFalse(php_case("evidence")["contains_payload_key"])
        self.assertFalse(php_case("evidence")["contains_digest"])

    def test_ci_contract(self) -> None:
        """AC-04: CI wiring only; exact HEAD status is checked externally."""
        workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text(
            encoding="utf-8",
        )
        self.assertIn("name: CI ControlBot", workflow)
        self.assertIn("name: Pruebas de contrato e integración", workflow)
        self.assertIn("run: python3 scripts/generate_coverage.py", workflow)
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertEqual(18, len(OFFLINE | set(NON_OFFLINE)))
        self.assertEqual("REVIEW_ONLY", evaluate(fixture())["decision"])


if __name__ == "__main__":
    unittest.main()
