#!/usr/bin/env python3
"""#1213: cobertura conductual offline de CB-0490..CB-0505.

Cada comprobacion A ejecuta PHP real mediante escenarios con fixtures sinteticas.
Ni el perfil configurado ni un intento de pausa prueban backup o ejecucion live.
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
ALL_IDS = {
    "CB-0490", "CB-0491", "CB-0492", "CB-0493", "CB-0494",
    "CB-0495", "CB-0496", "CB-0497", "CB-0498", "CB-0500",
    "CB-0501", "CB-0502", "CB-0503", "CB-0504", "CB-0505",
}
# B: verificacion externa. C: declaracion historica o limite, no funcion.
NON_OFFLINE = {
    "CB-0493": ("B", "Backup/rollback del release de GrindFlow es externo"),
    "CB-0494": ("B", "Restore integral DB, media y release requiere mundo real"),
    "CB-0497": ("C", "Alcance negativo y limites de integracion documentados"),
    "CB-0498": ("C", "Fase operativa futura, no comportamiento actual"),
    "CB-0505": ("C", "Declaracion de ausencia de efectos externos"),
}
OFFLINE = ALL_IDS - set(NON_OFFLINE)


@lru_cache(maxsize=64)
def php_scenario(filename: str, *args: str) -> dict:
    """Ejecutar el PHP existente, no duplicar la implementacion en Python."""
    path = ROOT / "tests" / filename
    completed = subprocess.run(
        ["php", str(path), *args], cwd=ROOT, check=True, text=True,
        capture_output=True, timeout=60,
    )
    return json.loads(completed.stdout)


def rollout() -> dict:
    return php_scenario("grindflow_recovery_rollout_scenarios.php")


def infrastructure() -> dict:
    return php_scenario("guardrail_infrastructure_classification_scenarios.php")


def integration() -> dict:
    return php_scenario("guardrail_integration_scenarios.php")


class CoberturaControlbot30Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: las 10 filas A ejercen perfiles y guardrails PHP reales."""
        self.assertIsNotNone(shutil.which("php"), "PHP CLI obligatorio")
        recovery = rollout()
        profile = recovery["profile"]
        evidence_unknown = php_scenario(
            "recovery_evidence_scenarios.php", "unknown-checksum"
        )
        evidence_stale = php_scenario(
            "recovery_evidence_scenarios.php", "stale-source"
        )
        infra = infrastructure()
        guard = integration()
        waiting, safe = guard["waiting"], guard["safe"]
        blocked = infra["blocked"]
        canary = infra["recovery"]["canary_work_item"]
        checks = {
            "CB-0490": (
                profile["project_ref"] == "controlbot:project/project-grindflow"
                and profile["manifest_ref"] ==
                    "controlbot:recovery-manifest/project-grindflow-v1"
                and recovery["profile_status"] == "configured"
            ),
            "CB-0491": (
                profile["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                and profile["retention"] ==
                    {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12}
                and profile["restore_drill_cadence_hours"] == 168
                and set(profile["sources"].values()) == {"required"}
                and profile["strategy"] ==
                    {"copies_required": 3, "media_types_required": 2,
                     "offsite_required": True, "immutable_required": True,
                     "undetected_restore_failures_target": 0}
                and profile["encryption_required"] is True
            ),
            "CB-0492": (
                profile["source_ref"].endswith("/issues/465")
                and recovery["profile_status"] == "configured"
                and evidence_unknown["controls"]["checksum"]["state"] == "unknown"
                and evidence_unknown["controls"]["checksum"]["evidence_ref"] is None
            ),
            "CB-0495": (
                evidence_unknown["controls"]["checksum"]["state"] == "unknown"
                and evidence_unknown["controls"]["checksum"]["freshness"] == "unknown"
                and evidence_stale["sources"][2]["state"] == "unknown"
                and recovery["profile_status"] != "HEALTHY"
            ),
            "CB-0496": (
                recovery["profile_status"] == "configured"
                and php_scenario("recovery_profile_scenarios.php", "stale")
                    ["effective_status"] == "unknown"
                and php_scenario("recovery_profile_scenarios.php", "missing-profile")
                    ["effective_status"] == "unknown"
            ),
            "CB-0500": (
                infra["one_pre"]["classification"] == "unknown"
                and blocked["classification"] == "blocked_by_infrastructure"
                and blocked == infra["blocked_reversed"]
                and infra["public_failed"]["classification"] == "unknown"
                and infra["public_not_started"]["classification"] == "unknown"
            ),
            "CB-0501": (
                blocked["retry_intent"]["action"] == "suppress_retry"
                and blocked["handoff"]["status"] == "waiting_dependency"
                and infra["deduped"]["retry_intent"]["deduplicated"] is True
                and infra["recovery"]["classification"] != "blocked_by_infrastructure"
                and canary["type"] == "verification"
                and canary == infra["recovery_reversed"]["canary_work_item"]
                and infra["recovery"]["queue_release_allowed"] is False
                and infra["same_timestamp_recovery"]["canary_work_item"] is None
            ),
            "CB-0502": (
                waiting["execution_status"] == "waiting_safe_point"
                and waiting["pause_intent"]["type"] == "runtime.session.pause"
                and waiting["pause_intent"]["action"] == "wait_safe_point"
                and waiting["checkpoint"] is None
                and safe["pause_intent"]["action"] == "request_activation"
                and safe["pause_intent"]["state"]["state"] == "unknown"
                and safe["pause_intent"]["state"]["activated_at"] is None
            ),
            "CB-0503": (
                guard["stale_assignment"] is True
                and guard["stale_session"] is True
                and guard["wrong_session_target"] is True
                and safe["requeue_intent"]["type"] == "scheduler.work_requeue"
                and safe["requeue_intent"]["work_item"]["generation"] == 5
                and safe["requeue_intent"]["work_item"]["attempt"] == 3
                and safe["requeue_intent"]["work_item"]["reservation_id"] is None
                and safe["requeue_intent"]["work_item"]["assigned_session_id"] is None
            ),
            "CB-0504": (
                guard["partial"]["complete"] is False
                and guard["partial"]["recoverable"] is True
                and guard["partial"]["success"] is False
                and guard["partial"]["phase"] == "checkpoint_pending"
                and guard["done"]["success"] is True
                and guard["done"]["complete"] is True
                and guard["invalid_order"] is True
            ),
        }
        self.assertEqual(set(checks), OFFLINE)
        self.assertEqual(set(checks) | set(NON_OFFLINE), ALL_IDS)
        for row, passed in checks.items():
            with self.subTest(row=row):
                self.assertTrue(passed, row + " sin evidencia conductual PHP")

    def test_falla_cerrado(self) -> None:
        """AC-02: falta de provenance, permisos y orden invalida bloquean."""
        for case in (
            "bad-rpo", "bad-strategy", "empty-retention",
            "encryption-disabled", "unknown-with-provenance",
            "stale-without-provenance", "sensitive-manifest-ref",
        ):
            with self.subTest(profile=case):
                out = php_scenario("recovery_profile_scenarios.php", case)
                self.assertTrue(out["blocked"])
        for case in (
            "cross-project", "producer-project-mismatch",
            "required-not-applicable", "unknown-with-provenance",
            "duplicate-source", "secret-ref",
        ):
            with self.subTest(evidence=case):
                out = php_scenario("recovery_evidence_scenarios.php", case)
                self.assertTrue(out["blocked"])

        infra = infrastructure()
        for case in (
            "one_pre", "stale", "older_external", "duplicate_same_repo",
            "same_repository_different_issues", "conflict",
            "public_failed", "public_not_started", "public_unknown",
        ):
            with self.subTest(infrastructure=case):
                self.assertEqual(infra[case]["classification"], "unknown")
        for case in (
            "same_timestamp_recovery", "unrelated_recovery",
            "mismatched_active_recovery", "unchanged_fingerprint_recovery",
            "older_recovery",
        ):
            with self.subTest(recovery=case):
                self.assertIsNone(infra[case]["canary_work_item"])

        guard = integration()
        self.assertTrue(guard["stale_assignment"])
        self.assertTrue(guard["stale_session"])
        self.assertTrue(guard["wrong_session_target"])
        self.assertTrue(guard["invalid_order"])
        self.assertFalse(guard["old_guard"]["allowed"])
        self.assertIsNone(guard["non_preemptible"]["pause_intent"])
        self.assertIsNone(guard["non_preemptible"]["requeue_intent"])
        self.assertEqual(
            guard["safe"]["requeue_intent"]["requires_receipts"],
            ["pause", "checkpoint"],
        )

    def test_sin_datos_reales(self) -> None:
        """AC-03: limites B/C honestos, sin secretos ni claims productivos."""
        self.assertEqual(15, len(ALL_IDS))
        self.assertEqual(10, len(OFFLINE))
        self.assertEqual(5, len(NON_OFFLINE))
        self.assertEqual({kind for kind, _ in NON_OFFLINE.values()}, {"B", "C"})
        self.assertTrue(all(reason for _, reason in NON_OFFLINE.values()))
        for filename in (
            "src/RecoveryProfile.php",
            "src/RecoveryEvidence.php",
            "src/GuardrailInfrastructureClassification.php",
            "src/GuardrailIntegration.php",
            "tests/grindflow_recovery_rollout_scenarios.php",
            "tests/guardrail_infrastructure_classification_scenarios.php",
            "tests/guardrail_integration_scenarios.php",
        ):
            with self.subTest(path=filename):
                self.assertTrue((ROOT / filename).is_file())

        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            source,
            r"ghp_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{16,}",
        )
        self.assertFalse(rollout()["execution"])
        self.assertFalse(rollout()["restorable"])
        self.assertFalse(infrastructure()["blocked"]["queue_release_allowed"])
        self.assertEqual(
            integration()["safe"]["pause_intent"]["state"]["state"], "unknown"
        )


if __name__ == "__main__":
    unittest.main()
