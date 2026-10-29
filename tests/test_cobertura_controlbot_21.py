"""Contratos de salud pública y recuperación de ControlBot (#1204).

Prueba el PHP de producción con runners preexistentes y transporte ficticio.
No abre conexiones, cambia configuraciones ni consulta la matriz de cobertura.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
HEALTH_RUNNER = ROOT / "tests/factory_public_health_scenarios.php"
RECOVERY_RUNNER = ROOT / "tests/factory_recovery_rollout_scenarios.php"
PROFILE_RUNNER = ROOT / "tests/recovery_profile_scenarios.php"
EXPECTED_REPOS = (
    "pl0n3r/Condor", "pl0n3r/brvtal", "pl0n3r/GrindFlow", "pl0n3r/ControlBot",
)


@lru_cache(maxsize=25)
def _php(runner: str, mode: str = "") -> dict:
    """Ejecuta scripts reales con fixtures DNS/HTTP falsos, sin red."""
    if shutil.which("php") is None:
        raise RuntimeError("AC ejecutable requiere php CLI; no se omite el test")
    script = {"health": HEALTH_RUNNER, "recovery": RECOVERY_RUNNER,
              "profile": PROFILE_RUNNER}[runner]
    command = ["php", str(script)]
    if mode:
        command.append(mode)
    result = subprocess.run(command, cwd=ROOT, check=False, capture_output=True,
                            text=True, timeout=15)
    if result.returncode != 0:
        raise AssertionError(f"Runner PHP {runner}/{mode} falló (exit={result.returncode})")
    return json.loads(result.stdout)


class CoberturaControlbot21Tests(unittest.TestCase):
    """AC-01..04: pruebas de comportamiento, límites y CI sobre HEAD exacto."""

    def test_contrato_principal(self):
        # CB-0329/0330: interfaz privada, solo observación, nunca prod verde.
        normal = _php("health", "normal")
        rows = normal["rows"]
        self.assertEqual(tuple(r["repository_ref"] for r in rows), EXPECTED_REPOS)
        self.assertEqual([r["status"] for r in rows],
                         ["reachable", "unreachable", "unreachable", "reachable"])
        self.assertEqual([r["http_status"] for r in rows], [200, 403, 500, 200])
        self.assertEqual(normal["has_cards"], 4)
        self.assertTrue(normal["has_unknown_gate"])
        self.assertEqual((normal["requests"], normal["dns_calls"]), (4, 4))
        self.assertTrue(all(r["latency_ms"] == 25 and
                            r["source_age_seconds"] == 0 for r in rows))

        # CB-0331: dos mapas independientes; no approved/configured, no red.
        missing = _php("health", "unconfigured")
        self.assertEqual((missing["requests"], missing["dns_calls"]), (0, 0))
        self.assertEqual([r["reason"] for r in missing["rows"]],
                         ["no_public_endpoint"] * 4)
        hostile = _php("health", "hostile")
        self.assertEqual((hostile["requests"], hostile["dns_calls"]), (0, 0))
        self.assertEqual(hostile["rows"][0]["reason"], "endpoint_not_allowlisted")

        # CB-0333/0334: guards del transporte y fallos tipados como UNKNOWN.
        timeout = _php("health", "timeout")
        self.assertEqual(timeout["rows"][0]["reason"], "probe_failed")
        self.assertEqual(timeout["rows"][0]["status"], "unknown")
        self.assertIsNone(timeout["rows"][0]["http_status"])
        for case in ("oversize", "redirect", "private_dns"):
            with self.subTest(CB="0334", case=case):
                self.assertEqual(_php("health", case)["rows"][0]["status"], "unknown")
        stale = _php("health", "stale")
        self.assertTrue(all(r["status"] == "stale" and
                            r["source_age_seconds"] == 500 for r in stale["rows"]))

        # CB-0335: HTML privado escapado sin mutaciones ni credenciales.
        ui = _php("health", "ui_escape")
        self.assertTrue(ui["is_escaped"] and ui["has_script_text"])
        self.assertTrue(ui["has_no_actions"])
        self.assertNotIn("<script>alert(1)</script>", ui["raw_html"])
        self.assertNotIn("<form", ui["raw_html"])
        self.assertNotIn("<button", ui["raw_html"])

        # CB-0336/0337/0338: RecoveryProfile normalize real, 3-2-1-1-0.
        rollout = _php("recovery")
        profile = rollout["profile"]
        self.assertEqual(rollout["profile_status"], "configured")
        self.assertEqual(profile["project_ref"], "controlbot:project/project-factory")
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(profile["retention"],
                         {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12})
        self.assertEqual(profile["sources"],
                         {"database": "not_applicable", "media": "not_applicable",
                          "repository": "required", "configuration": "required"})
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)
        self.assertTrue(profile["encryption_required"])
        self.assertEqual(profile["strategy"],
                         {"copies_required": 3, "media_types_required": 2,
                          "offsite_required": True, "immutable_required": True,
                          "undetected_restore_failures_target": 0})

        # CB-0340/0341/0342: legacy no se promueve a recibos canónicos.
        legacy = rollout["legacy_restore"]
        state = rollout["recovery"]
        self.assertTrue(legacy["restored_from_external_copy"])
        self.assertFalse(any(legacy["canonical_contracts"].values()))
        for key in ("canonical_backup_receipt", "canonical_recovery_evidence",
                    "canonical_restore_drill", "encryption", "immutability"):
            with self.subTest(CB="0341", field=key):
                self.assertEqual(state[key], "unknown")
        self.assertIsNone(state["demonstrated_rto_minutes"])
        self.assertEqual(state["dr_status"], "UNKNOWN")
        self.assertFalse(state["restorable"] or state["execution"])
        self.assertNotEqual(state["dr_status"], "HEALTHY")

    def test_falla_cerrado(self):
        # Allowlist, DNS, invalid transport, missing endpoints and stale data.
        for mode in ("unconfigured", "hostile", "private_dns", "timeout",
                     "oversize", "redirect"):
            with self.subTest(mode=mode):
                result = _php("health", mode)
                self.assertEqual(result["rows"][0]["status"], "unknown")
                self.assertEqual(result["requests"], 0 if mode in
                                 ("unconfigured", "hostile", "private_dns") else 1)
        for case in ("invalid-ui-reachable-reason",
                     "invalid-ui-reachable-timestamp",
                     "invalid-ui-unreachable-success",
                     "invalid-ui-stale-no-evidence",
                     "invalid-ui-stale-too-recent",
                     "invalid-ui-unknown-reason"):
            with self.subTest(mode=case):
                self.assertTrue(_php("health", case)["rejected"])
        self.assertEqual([r["reason"] for r in _php("health", "stale")["rows"]],
                         ["evidence_stale"] * 4)
        self.assertEqual(_php("health", "future_invalid")["has_cards"], 4)

        # RecoveryProfile: no inventar datos/autoridad con perfil malformado.
        for case in ("bad-rpo", "empty-retention", "unknown-field",
                     "unknown-source-state", "all-not-applicable", "bad-strategy",
                     "sensitive-manifest-ref", "encryption-disabled",
                     "unknown-with-provenance", "stale-without-provenance"):
            with self.subTest(profile=case):
                self.assertTrue(_php("profile", case)["blocked"])
        for case in ("stale", "unknown", "missing-profile"):
            with self.subTest(profile=case):
                self.assertEqual(_php("profile", case)["effective_status"], "unknown")

    def test_sin_datos_reales(self):
        # El runner real usa únicamente dominios example.org y DNS/GET fakes.
        observation = _php("health", "normal")
        self.assertTrue(all(r["endpoint"].endswith(".example.org/health")
                            for r in observation["rows"]))
        self.assertEqual((observation["requests"], observation["dns_calls"]), (4, 4))
        data = json.dumps(_php("health", "timeout"), sort_keys=True)
        self.assertNotIn("CREDENTIAL_SECRET_NEVER_PERSIST", data)
        self.assertNotIn('"body"', data)
        self.assertNotIn('"cookie"', data)
        self.assertNotIn('"headers"', data)
        self.assertNotIn("production_green", data)
        rollout = _php("recovery")
        self.assertFalse(rollout["recovery"]["execution"])
        self.assertFalse(rollout["recovery"]["restorable"])
        self.assertFalse(any(rollout["legacy_restore"]["canonical_contracts"].values()))

    def test_gate_validar_presente(self):
        # AC-04: una regresión del test falla la aceptación; el check de rama
        # 'Validar' sigue verificándose en GitHub contra el HEAD exacto.
        self.assertEqual(_php("health", "normal")["has_cards"], 4)
        self.assertEqual(_php("recovery")["profile_status"], "configured")
        self.assertEqual(_php("recovery")["recovery"]["dr_status"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
