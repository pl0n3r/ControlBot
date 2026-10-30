"""ControlBot #1244: evidencia offline sobre el runbook BRVTAL real.

Las cuatro filas CB-1014..CB-1017 tienen implementación PHP comprobable.
CB-1013 es una frase introductoria del runbook sin fila en las cuatro
partes de la matriz: se documenta aparte, no se inventa cobertura.

Este test solo solicita proyecciones inertes a PHP, nunca operaciones live.
"""
import json
import subprocess
import unittest

from test_brvtal_reconcile_runbook import RUN_ID, SHA, project, receipt


OPERATIONS = (
    "migration.status",
    "database.backup",
    "migration.registry.reconcile",
    "migration.verify",
    "health.check",
    "smoke.run",
)
EFFECTS = ("read", "backup", "write", "read", "read", "read")


class CoberturaControlbot61Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """CB-1014/1015: secuencia real, BackupGate y evidencia de salud."""
        completed = []
        for step, effect in zip(OPERATIONS, EFFECTS):
            with self.subTest(operation=step):
                result = project(
                    completed_steps=completed,
                    backup_receipt=receipt() if len(completed) >= 2 else None,
                )
                self.assertEqual(result["status"], "planned")
                self.assertEqual(result["reason"], "next_step")
                self.assertEqual(result["next_intent"]["operation"], step)
                self.assertEqual(result["next_intent"]["effect"], effect)
                self.assertEqual(result["next_intent"]["run_id"], RUN_ID)
                self.assertEqual(result["next_intent"]["issue"], "pl0n3r/brvtal#681")
                self.assertFalse(result["revoke_grant"])
                if step == "migration.registry.reconcile":
                    proof = result["next_intent"]["backup_evidence"]
                    self.assertEqual(proof["backup_type"], "database_dump")
                    self.assertEqual(proof["status"], "ready")
                    self.assertEqual(proof["run_id"], RUN_ID)
                elif step == "migration.verify":
                    self.assertEqual(result["next_intent"]["expect"]["pending_migrations"], [])
                elif step == "health.check":
                    self.assertIs(
                        result["next_intent"]["expect"]["schema_up_to_date"], True
                    )
                elif step == "smoke.run":
                    self.assertEqual(result["next_intent"]["expect"], {
                        "sha": SHA, "run_id": RUN_ID,
                    })
                completed = [*completed, step]

        # "revoke" es una bandera terminal, no una ejecución de credenciales.
        terminal = project(completed_steps=completed, backup_receipt=receipt())
        self.assertEqual(terminal["status"], "success")
        self.assertEqual(terminal["reason"], "verified")
        self.assertIsNone(terminal["next_intent"])
        self.assertTrue(terminal["revoke_grant"])
        self.assertIs(terminal["evidence"]["schema_up_to_date"], True)
        self.assertEqual(terminal["evidence"]["sha"], SHA)
        self.assertEqual(terminal["evidence"]["run_id"], RUN_ID)

        # La rama clean omite operaciones de backup/write; no eleva permisos.
        clean = project(schema_state="clean", completed_steps=["migration.status"])
        self.assertEqual(clean["next_intent"]["operation"], "migration.verify")
        self.assertEqual(clean["next_intent"]["effect"], "read")

    def test_falla_cerrado(self):
        """CB-1016 y guardas: nunca confundir bloqueo/recovery con success."""
        base = ["migration.status", "database.backup"]
        missing = project(completed_steps=base)
        self.assertEqual(missing["status"], "blocked")
        self.assertEqual(missing["reason"], "backup_receipt_required")
        self.assertIsNone(missing["next_intent"])
        self.assertTrue(missing["revoke_grant"])
        self.assertEqual(missing["continuation"]["resume_from"], "database.backup")

        pending = project(completed_steps=base, backup_receipt=receipt("pending"))
        self.assertEqual(pending["status"], "blocked")
        self.assertIsNone(pending["next_intent"])
        wrong_scope = receipt()
        wrong_scope["run_id"] = "123e4567-e89b-42d3-a456-426614174099"
        mismatch = project(completed_steps=base, backup_receipt=wrong_scope)
        self.assertEqual(mismatch["status"], "blocked")
        self.assertEqual(mismatch["reason"], "backup_scope_mismatch")
        self.assertIsNone(mismatch["next_intent"])

        ambiguous = project(
            schema_state="ambiguous", completed_steps=["migration.status"]
        )
        self.assertEqual(ambiguous["status"], "blocked")
        self.assertIs(ambiguous["evidence"]["baseline_allowed"], False)
        self.assertIsNone(ambiguous["next_intent"])

        failure = project(
            completed_steps=base + ["migration.registry.reconcile", "migration.verify"],
            failed_step="health.check",
            backup_receipt=receipt(),
        )
        self.assertEqual(failure["status"], "recoverable")
        self.assertEqual(failure["reason"], "step_failed")
        self.assertEqual(failure["continuation"], {"resume_from": "health.check"})
        self.assertIsNone(failure["next_intent"])
        self.assertTrue(failure["revoke_grant"])
        self.assertNotEqual(failure["status"], "success")

        for kwargs in (
            {"sha": "unverified"},
            {"now": 0},
            {"completed_steps": ["health.check"]},
            {"completed_steps": ["migration.status", "migration.status"]},
            {"shell": "unsupported"},
        ):
            with self.subTest(invalid=tuple(kwargs)):
                with self.assertRaises(subprocess.CalledProcessError):
                    project(**kwargs)

    def test_sin_datos_reales(self):
        """CB-1017: la proyección del código PHP devuelve solo evidencia safe."""
        fake = receipt()
        fake["artifact_ref"] = "backup:fictional:opaque-artifact"
        fake["integrity"]["digest"] = "b" * 64
        fake["restore_capability"] = "manual"
        result = project(
            completed_steps=["migration.status", "database.backup"],
            backup_receipt=fake,
        )
        self.assertEqual(result["status"], "planned")
        self.assertEqual(result["next_intent"]["operation"], "migration.registry.reconcile")
        self.assertEqual(result["next_intent"]["effect"], "write")
        self.assertEqual(result["next_intent"]["backup_evidence"]["receipt_id"],
                         fake["receipt_id"])
        payload = json.dumps(result, sort_keys=True).lower()
        for sensitive in (
            "artifact_ref", "opaque-artifact", "integrity", "digest",
            "password", "private_key", "ssh", "dsn", "cookie",
            "authorization", "bearer", "shell", "dump_payload",
        ):
            with self.subTest(secret=sensitive):
                self.assertNotIn(sensitive, payload)
        self.assertNotIn("hostinger", payload)
        self.assertNotIn("executor", payload)
        self.assertFalse(result["revoke_grant"])


if __name__ == "__main__":
    unittest.main()
