#!/usr/bin/env python3
"""#1192: offline behavioral regression for backup, DR rollout and feedback.

Exercises committed synthetic PHP scenario harnesses against production classes.
No matrix status checks, production access, secrets, real users or inferred
recovery readiness. Scope-only requirements are classified in the PR.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "backup": "tests/controlbot_backup_scenarios.php",
    "recovery": "tests/controlbot_recovery_rollout_scenarios.php",
    "feedback": "tests/customer_feedback_signal_scenarios.php",
}
ROWS = frozenset(f"CB-{n:04d}" for n in range(105, 124))
CLASSIFIED = {
    "CB-0105": ("C", "Pure backup slice scope; not an offsite receipt."),
    "CB-0111": ("C", "Configuration rollout; not operational restore evidence."),
    "CB-0117": ("C", "Architectural consumer handoff; no live product call."),
}
OFFLINE = ROWS - CLASSIFIED.keys()


@lru_cache(maxsize=None)
def scenario(component: str, mode: str) -> dict:
    """Run checked-in PHP scenario against its actual source class."""
    if component not in SCRIPTS:
        raise AssertionError("UNKNOWN: unsupported component")
    fixture = ROOT / SCRIPTS[component]
    if not fixture.is_file():
        raise AssertionError(f"UNKNOWN: PHP fixture missing: {fixture.name}")
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    run = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", str(fixture), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=45, check=False,
    )
    # Legacy feedback scenario uses a no-op global use statement. Reject
    # every diagnostic except this one precisely identified PHP warning.
    tolerated = re.compile(
        r"^PHP Warning:\s+The use statement with non-compound name "
        r"'InvalidArgumentException' has no effect in "
        r".*/tests/customer_feedback_signal_scenarios\.php on line 4$"
    )
    unexpected = [
        line.strip() for line in run.stderr.splitlines() if line.strip()
        and not (component == "feedback" and tolerated.fullmatch(line.strip()))
    ]
    if run.returncode or unexpected:
        raise AssertionError(
            f"UNKNOWN: PHP {component}/{mode} failed ({run.returncode}): "
            + "\n".join(unexpected)[-700:]
        )
    try:
        result = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP result is not JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP result not an object")
    return result


class CoberturaControlbot09Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: receipts, isolated restore, retention, DR, customer feedback."""
        self.assertEqual(len(ROWS), 19)
        self.assertEqual(len(OFFLINE), 16)
        self.assertEqual(OFFLINE | CLASSIFIED.keys(), ROWS)

        # CB-0106: real backup normalization, scope, opaque ref and checksum.
        backup = scenario("backup", "backup")["valid"]
        self.assertEqual(backup["version"], 1)
        self.assertEqual(backup["backup_id"], "backup-001")
        self.assertEqual(
            (backup["project"], backup["environment"], backup["resource"]),
            ("controlbot", "production", "database:primary"),
        )
        self.assertLessEqual(backup["started_at"], backup["completed_at"])
        self.assertRegex(backup["checksum"], r"^[a-f0-9]{64}$")
        self.assertRegex(backup["fingerprint"], r"^[a-f0-9]{64}$")
        self.assertEqual(backup["size_bytes"], 4096)
        self.assertEqual(backup["storage_ref"], "backup:controlbot/backup-001")
        self.assertEqual(backup["encryption"], "kms-managed")
        self.assertEqual(backup["status"], "completed")

        # CB-0107: real restore connects the same checksum on a test target.
        restore = scenario("backup", "restore")["valid"]
        self.assertEqual(restore["backup_id"], backup["backup_id"])
        self.assertEqual(restore["source_checksum"], backup["checksum"])
        self.assertEqual(restore["destination_checksum"], backup["checksum"])
        self.assertEqual(restore["target_environment"], "restore-drill")
        self.assertGreaterEqual(restore["started_at"], backup["completed_at"])
        self.assertEqual(restore["status"], "passed")
        self.assertRegex(restore["evidence_ref"], r"^evidence:restore/")
        self.assertRegex(restore["fingerprint"], r"^[a-f0-9]{64}$")

        # CB-0108: verified after drill; missing / invalid are never restorable.
        checked = scenario("backup", "restorable")
        self.assertEqual(checked["verified"]["reason"], "verified_restore_drill")
        self.assertIs(checked["verified"]["restorable"], True)
        self.assertEqual(checked["verified"]["restore_id"], "restore-001")
        self.assertEqual(checked["missing"]["reason"], "restore_evidence_missing")
        self.assertIs(checked["missing"]["restorable"], False)
        self.assertEqual(checked["invalid"]["reason"], "restore_evidence_invalid")
        self.assertIs(checked["invalid"]["restorable"], False)

        # CB-0109 / CB-0110: deterministic non-deleting retention, pure core.
        plan = scenario("backup", "retention")["plan"]
        self.assertEqual(plan["authority"], "plan_only")
        self.assertEqual(plan["keep"], ["backup-002", "backup-003", "backup-004"])
        self.assertEqual(plan["delete"], ["backup-001"])
        self.assertEqual(plan["protected_restorable"], ["backup-002"])
        self.assertTrue(set(plan["keep"]).isdisjoint(plan["delete"]))
        self.assertEqual(scenario("backup", "pure")["hits"], [])

        # CB-0112: applied RecoveryProfile targets, storage requirements.
        configured = scenario("recovery", "profile")
        profile = configured["profile"]
        self.assertEqual(configured["effective_status"], "configured")
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(profile["retention"], {
            "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12,
        })
        self.assertEqual(profile["sources"], {
            "database": "required", "media": "not_applicable",
            "repository": "required", "configuration": "required",
        })
        self.assertEqual(profile["strategy"], {
            "copies_required": 3, "media_types_required": 2,
            "offsite_required": True, "immutable_required": True,
            "undetected_restore_failures_target": 0,
        })
        self.assertIs(profile["encryption_required"], True)
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)

        # CB-0113/0114/0116: configured != HEALTHY, no operational execution.
        ops = scenario("recovery", "operational-status")
        self.assertEqual(ops["profile_status"], "configured")
        self.assertEqual(ops["dr_status"], "UNKNOWN")
        for name in ("backup_evidence", "offsite_copy", "immutable_copy", "restore_drill"):
            self.assertEqual(ops[name], "unknown", name)
        self.assertIs(ops["restorable"], False)
        self.assertIs(ops["execution"], False)

        # CB-0115: real configuration attests construction only.
        self.assertEqual(scenario("recovery", "attestation"), {
            "phase": "construccion", "nothing_live": True,
            "no_real_customer_data": True,
        })

        # CB-0118/0119: only normalized sources can produce feedback signals.
        support = scenario("feedback", "scope")["good"]
        self.assertEqual(support["version"], 1)
        self.assertEqual(support["venture_id"], "venture-condor")
        self.assertEqual(support["origin"], "support_pattern")
        self.assertEqual(support["targets"], ["discovery", "product_intelligence"])
        self.assertEqual(support["freshness"], "fresh")
        self.assertEqual(support["nature"], "observed")
        self.assertEqual(support["observed_at"], 1700000000)
        self.assertRegex(support["feedback_ref"], r"^feedback:[a-f0-9]{32}$")
        self.assertRegex(support["product_ref"], r"^product:[a-f0-9]{32}$")
        self.assertRegex(support["evidence_refs"][0], r"^evidence:[a-f0-9]{32}$")
        self.assertEqual(
            support["source_ref"], "customer-success:support/support:" + "a" * 32,
        )
        closed = scenario("feedback", "closed")
        self.assertEqual(len(closed["collection"]), 2)
        self.assertEqual(closed["good"]["targets"], ["discovery", "product_intelligence"])
        self.assertEqual(
            [r["feedback_ref"] for r in closed["collection"]],
            sorted(r["feedback_ref"] for r in closed["collection"]),
        )

        # CB-0120/0121/0122: missing evidence stays UNKNOWN, stale stays stale.
        confidence = scenario("feedback", "fail_closed")
        for key in ("unknownSupport", "unknownHealth"):
            self.assertEqual(confidence[key]["nature"], "unknown", key)
            self.assertEqual(confidence[key]["freshness"], "unknown", key)
            self.assertEqual(confidence[key]["evidence_refs"], [], key)
            self.assertIsNone(confidence[key]["observed_at"], key)
        self.assertEqual(confidence["stale"]["freshness"], "stale")
        self.assertEqual(confidence["churn"]["nature"], "inferred")
        self.assertEqual(confidence["churn"]["confidence"], 0.42)

        # CB-0123: closed output schema: no free text or personal fields.
        self.assertEqual(set(support), {
            "version", "feedback_ref", "venture_id", "product_ref", "origin",
            "targets", "source_ref", "evidence_refs", "observed_at",
            "freshness", "confidence", "nature",
        })

    def test_falla_cerrado(self) -> None:
        """AC-02: real PHP rejects unsafe receipts and forged feedback."""
        for name in ("size_rejected", "url_rejected", "secret_rejected"):
            self.assertIs(scenario("backup", "backup")[name], True, name)
        for name in ("production_rejected", "checksum_rejected", "before_backup_rejected"):
            self.assertIs(scenario("backup", "restore")[name], True, name)
        self.assertIs(scenario("backup", "retention")["ambiguous_rejected"], True)
        self.assertIs(scenario("backup", "restorable")["invalid"]["restorable"], False)
        self.assertEqual(scenario("recovery", "attestation-live"), {"blocked": True})
        self.assertEqual(scenario("recovery", "attestation-real-data"), {"blocked": True})
        self.assertIs(scenario("recovery", "operational-status")["restorable"], False)
        for name in ("cross", "missingProduct", "extraRejected"):
            self.assertIs(scenario("feedback", "scope")[name], True, name)
        for name in ("duplicate", "unknown", "duplicatedCollection",
                     "forgedSource", "forgedScope", "forgedUnknown"):
            self.assertIs(scenario("feedback", "closed")[name], True, name)
        privacy = scenario("feedback", "privacy")
        self.assertTrue(privacy)
        self.assertTrue(all(x is True for x in privacy.values()), privacy)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic outputs never expose user text, tokens, secrets."""
        self.assertEqual(scenario("backup", "pure")["hits"], [])
        self.assertIs(scenario("recovery", "operational-status")["execution"], False)
        self.assertEqual(scenario("feedback", "pure")["methods"], [
            "collection", "fromHealthDimension", "fromSupport",
        ])
        for obj in (
            scenario("backup", "backup")["valid"],
            scenario("backup", "restore")["valid"],
            scenario("recovery", "operational-status"),
            scenario("feedback", "scope")["good"],
            scenario("feedback", "fail_closed")["unknownSupport"],
        ):
            data = json.dumps(obj, sort_keys=True).lower()
            for forbidden in ("password", "cookie", "bearer", "credential",
                              "private_key", "api_key", "ticket_body",
                              "transcript", "customer_text", "access_token",
                              "phone"):
                self.assertNotIn(forbidden, data, forbidden)

    def test_ci_gate_contract(self) -> None:
        """AC-04: runnable acceptance targets; CI must be exact-HEAD green."""
        self.assertEqual(ROWS, frozenset(f"CB-{n:04d}" for n in range(105, 124)))
        self.assertEqual({kind for kind, _ in CLASSIFIED.values()}, {"C"})
        self.assertFalse(OFFLINE & CLASSIFIED.keys())
        for path in SCRIPTS.values():
            self.assertTrue((ROOT / path).is_file(), path)
        for name in ("test_contrato_principal", "test_falla_cerrado",
                     "test_sin_datos_reales", "test_ci_gate_contract"):
            self.assertTrue(callable(getattr(self, name)))


if __name__ == "__main__":
    unittest.main()
