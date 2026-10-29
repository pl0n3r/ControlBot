#!/usr/bin/env python3
"""#1195: offline evidence for genuine RecoveryDrillProjection/RecoveryEvidence PHP."""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "drill": ("recovery_drill_projection_scenarios.php", frozenset({
        "valid", "cross-project", "producer-mismatch", "evidence-mismatch",
        "stale", "unknown", "breached", "production-target", "failed-check",
        "authority-expanded", "execute-true", "sensitive-ref", "extra-field",
        "target-mismatch", "bad-observed-at",
    })),
    "evidence": ("recovery_evidence_scenarios.php", frozenset({
        "valid", "cross-project", "producer-project-mismatch",
        "cross-project-profile-ref", "stale-checksum", "unknown-checksum",
        "stale-source", "duplicate-source", "required-not-applicable",
        "top-level-digest", "payload-field", "provider-url", "secret-ref",
        "unknown-with-provenance",
    })),
}
ROWS = {f"CB-{n:04d}" for n in range(167, 184)}
# These eight institutional responsibilities cannot be exercised end-to-end
# by the local ControlBot projector; no fictitious backup or restore test.
POLICY = {
    "CB-0167": "C: Factory #330 owns live drill execution",
    "CB-0173": "C: Recovery Health, WorkItems and restore execution are external",
    "CB-0174": "C: this projection does not create or validate operational backups",
    "CB-0175": "C: Factory #305 owns manifest, pipeline and recovery health",
    "CB-0176": "C: FactoryRunner executes adapter jobs externally",
    "CB-0177": "C: BackupReceipt producer owns operational evidence",
    "CB-0179": "C: no duplicate backup engine is an architectural boundary",
    "CB-0183": "C: no provider I/O, backup, health or WorkItems in this slice",
}
OFFLINE = ROWS - set(POLICY)
DRILL_FIELDS = {
    "version", "project_ref", "recovery_evidence_ref", "backup_receipt_id",
    "target", "status", "reported_status", "observed", "checks", "reasons",
    "evidence_refs", "observed_at", "freshness", "authority", "execute",
}
EVIDENCE_FIELDS = {"version", "project_ref", "profile_ref", "controls", "sources"}


@lru_cache(maxsize=40)
def observed(group: str, name: str) -> dict:
    """Execute only a checked-in synthetic scenario calling production PHP."""
    if group not in SCRIPTS or name not in SCRIPTS[group][1]:
        raise AssertionError("UNKNOWN: PHP scenario not allowed")
    binary = shutil.which("php")
    script = ROOT / "tests" / SCRIPTS[group][0]
    if binary is None or not script.is_file():
        raise AssertionError("UNKNOWN: PHP runtime/scenario absent")
    run = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", str(script), name],
        cwd=ROOT, text=True, capture_output=True, timeout=40, check=False,
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: PHP scenario failed: " + run.stderr[-600:])
    try:
        data = json.loads(run.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: PHP scenario output not JSON") from error
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP scenario did not return an object")
    return data


class CoberturaControlbot12Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: real behavior predicates for all nine offline clauses."""
        drill = observed("drill", "valid")
        breached = observed("drill", "breached")
        stale = observed("drill", "stale")
        unknown = observed("drill", "unknown")
        evidence = observed("evidence", "valid")
        sources = evidence["sources"]
        checksum_stale = observed("evidence", "stale-checksum")["controls"]["checksum"]
        checksum_unknown = observed("evidence", "unknown-checksum")["controls"]["checksum"]
        source_stale = observed("evidence", "stale-source")["sources"][2]
        checks = {
            "CB-0168": (
                drill["project_ref"] == "controlbot:project/project-controlbot"
                and drill["status"] == "PASSED" and drill["freshness"] == "fresh"
                and stale["status"] == "UNKNOWN"
            ),
            "CB-0169": (
                drill["backup_receipt_id"] == "11111111-1111-4111-8111-111111111111"
                and all(observed("drill", x)["blocked"] for x in
                        ("cross-project", "producer-mismatch", "evidence-mismatch"))
            ),
            "CB-0170": (
                drill["observed"] == {
                    "rpo_seconds": 600, "rto_seconds": 1680,
                    "rpo_target_seconds": 900, "rto_target_seconds": 3600,
                }
                and breached["status"] == breached["reported_status"] == "BREACHED"
                and breached["reasons"] == ["RTO_EXCEEDED"]
                and breached["observed"]["rto_seconds"] == 4000
            ),
            "CB-0171": all(
                item["status"] == "UNKNOWN"
                and item["reported_status"] == "PASSED"
                and item["observed"] == drill["observed"]
                for item in (stale, unknown)
            ),
            "CB-0172": (
                drill["target"]["kind"] == "disposable"
                and drill["checks"] == {"health": True, "smoke": True, "integrity": True}
                and drill["authority"] == "unchanged" and drill["execute"] is False
                and all(observed("drill", x)["blocked"] for x in (
                    "production-target", "failed-check", "authority-expanded",
                    "execute-true", "sensitive-ref", "extra-field",
                ))
            ),
            "CB-0178": (
                set(evidence) == EVIDENCE_FIELDS
                and evidence["profile_ref"] ==
                    "controlbot:recovery-profile/project-controlbot-v1"
                and all(row["evidence_ref"] is None or
                        row["evidence_ref"].startswith("controlbot:evidence/")
                        for row in sources)
            ),
            "CB-0180": (
                checksum_stale["state"] == "unknown"
                and checksum_stale["reported_state"] == "verified"
                and checksum_stale["freshness"] == "stale"
                and checksum_unknown["state"] == "unknown"
                and checksum_unknown["evidence_ref"] is None
                and checksum_unknown["observed_at"] is None
                and source_stale["state"] == "unknown"
                and sources[1]["state"] == "not_applicable"
                and sources[1]["reported_state"] == "not_applicable"
            ),
            "CB-0181": (
                evidence["project_ref"] == drill["project_ref"]
                and all(observed("evidence", x)["blocked"] for x in (
                    "cross-project", "producer-project-mismatch",
                    "cross-project-profile-ref",
                ))
            ),
            "CB-0182": (
                [row["source"] for row in sources] ==
                    ["database", "media", "repository", "configuration"]
                and all(observed("evidence", x)["blocked"] for x in (
                    "top-level-digest", "payload-field", "provider-url",
                    "secret-ref", "duplicate-source",
                    "required-not-applicable", "unknown-with-provenance",
                ))
            ),
        }
        self.assertEqual(len(ROWS), 17)
        self.assertEqual(len(OFFLINE), 9)
        self.assertEqual(set(checks), OFFLINE)
        self.assertFalse(OFFLINE & set(POLICY))
        for clause, passed in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(passed, clause + ": genuine projection unproven")

    def test_falla_cerrado(self) -> None:
        """AC-02: reject forged projects, unsafe authority and secret inputs."""
        cases = {
            "drill": (
                "cross-project", "producer-mismatch", "evidence-mismatch",
                "production-target", "failed-check", "authority-expanded",
                "execute-true", "sensitive-ref", "extra-field",
                "target-mismatch", "bad-observed-at",
            ),
            "evidence": (
                "cross-project", "producer-project-mismatch",
                "cross-project-profile-ref", "duplicate-source",
                "required-not-applicable", "top-level-digest", "payload-field",
                "provider-url", "secret-ref", "unknown-with-provenance",
            ),
        }
        for group, names in cases.items():
            for name in names:
                with self.subTest(group=group, name=name):
                    denial = observed(group, name)
                    self.assertIs(denial["blocked"], True)
                    for secret in (
                        "token-secret-value", "backup-material",
                        "opaque-backup-payload", "bucket.example.invalid",
                    ):
                        self.assertNotIn(secret, denial["message"] or "")
        for name in ("stale", "unknown"):
            projected = observed("drill", name)
            self.assertEqual(projected["status"], "UNKNOWN")
            self.assertEqual(projected["reported_status"], "PASSED")
        self.assertEqual(
            observed("evidence", "stale-source")["sources"][2]["state"], "unknown"
        )

    def test_sin_datos_reales(self) -> None:
        """AC-03: no operational backup, provider data or network credentials."""
        drill = observed("drill", "valid")
        evidence = observed("evidence", "valid")
        self.assertEqual(set(drill), DRILL_FIELDS)
        self.assertEqual(set(evidence), EVIDENCE_FIELDS)
        self.assertIs(drill["execute"], False)
        self.assertEqual([row["source"] for row in evidence["sources"]],
                         ["database", "media", "repository", "configuration"])
        self.assertFalse({"payload", "secret", "authorization", "backup"} & set(evidence))
        own = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({alias.name.split(".")[0] for alias in node.names} &
                                 {"requests", "socket", "urllib", "httpx", "pymysql"})
        self.assertNotIn("matriz" + "-cobertura", own)
        self.assertEqual(set(SCRIPTS), {"drill", "evidence"})


if __name__ == "__main__":
    unittest.main()
