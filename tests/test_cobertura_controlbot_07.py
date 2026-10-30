#!/usr/bin/env python3
"""#1190: contratos reales de Recovery BRVTAL e identidad Business OS.

Los escenarios PHP existentes invocan RecoveryProfile y VentureIdentity con
fixtures sinteticos. Esta hoja no lee la matriz ni acredita Recovery live.
No se ejecutan requests, proveedores, despliegues, pagos ni datos personales.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import unittest
import urllib.request
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "recovery": ROOT / "tests" / "brvtal_recovery_rollout_scenarios.php",
    "identity": ROOT / "tests" / "venture_identity_scenarios.php",
}
MODES = frozenset({
    "distinct-admins", "authority", "identity-kinds", "invalid-grants",
    "revoke", "events", "optional-fields", "history-capacity",
    "deterministic",
})

# A: ejecuta el contrato PHP real. B: requiere comprobacion externa.
# C: delimitacion de autoridad/historia sin comportamiento local independiente.
A = frozenset({"CB-0070", "CB-0071", "CB-0081", "CB-0082",
               "CB-0083", "CB-0084", "CB-0085"})
B = {
    "CB-0074": "eleccion OWNER del storage inmutable #470 y recibos live",
    "CB-0075": "estado de Recovery live depende de proveedor y recibos reales",
    "CB-0076": "BackupReceipt canonico debe observarse en produccion",
    "CB-0077": "restore drill canonico requiere ejecucion y recibo real",
}
C = {
    "CB-0072": "historia BRVTAL #389 no es comportamiento PHP de este slice",
    "CB-0073": "Google Drive opcional es frontera arquitectonica de storage",
    "CB-0078": "ausencia declarativa de secretos e IDs externos en rollout",
    "CB-0079": "handoff futuro a productores canonicos de Recovery",
    "CB-0080": "control de autoridad entre repositorios, no grant runtime",
    "CB-0086": "atribucion de evaluacion server-side a #132, no esta hoja",
}


def php_environment() -> dict[str, str]:
    """Conservar solo el contrato del wrapper de coverage, nunca secretos."""
    result = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "LANG": "C",
        "HOME": os.environ.get("HOME", "/tmp"),
    }
    for key in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT",
        "XDEBUG_MODE",
    ):
        if key in os.environ:
            result[key] = os.environ[key]
    return result


@lru_cache(maxsize=None)
def actual(component: str, mode: str = "") -> dict:
    """Falla cerrado si el proceso PHP de producto no corre de verdad."""
    if component not in SCRIPTS or (component == "identity" and mode not in MODES):
        raise AssertionError("UNKNOWN: escenario fuera del contrato")
    if component == "recovery" and mode:
        raise AssertionError("UNKNOWN: el rollout no admite escenarios")
    php = shutil.which("php")
    if not php or not SCRIPTS[component].is_file():
        raise AssertionError("UNKNOWN: PHP o escenario del producto ausente")
    cmd = [php, "-d", "allow_url_fopen=0", str(SCRIPTS[component])]
    if mode:
        cmd.append(mode)
    result = subprocess.run(
        cmd, cwd=ROOT, capture_output=True, text=True, check=False, timeout=40,
        env=php_environment(),
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError(
            "UNKNOWN: fallo PHP del contrato: " + result.stderr[-600:]
        )
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: salida PHP no es JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: salida PHP no es un objeto")
    return value


PHP_NEGATIVE = r"""
require $argv[1].'/src/InfrastructureProvider.php';
require $argv[1].'/src/RecoveryProfile.php';
use ControlBot\Infrastructure\RecoveryProfile;
$profile=json_decode(file_get_contents($argv[1].'/config/recovery/brvtal.json'),
                     true,512,JSON_THROW_ON_ERROR);
$blocked=static function(callable $call):bool {
    try { $call(); return false; }
    catch (\InvalidArgumentException) { return true; }
};
$badRpo=$profile; $badRpo['targets']['rpo_minutes']=0;
$badRetention=$profile; $badRetention['retention']['monthly']=-1;
$badStrategy=$profile; $badStrategy['strategy']['immutable_required']=false;
$badSource=$profile; $badSource['sources']['repository']='optional';
$badExtra=$profile; $badExtra['token']='not-a-real-token';
$unknownWithProof=$profile; $unknownWithProof['freshness']='unknown';
$unknownWithoutProof=$profile; $unknownWithoutProof['freshness']='unknown';
$unknownWithoutProof['source_ref']=null;
$unknownWithoutProof['observed_at']=null;
echo json_encode([
 'rpo'=>$blocked(fn()=>RecoveryProfile::normalize($badRpo)),
 'retention'=>$blocked(fn()=>RecoveryProfile::normalize($badRetention)),
 'strategy'=>$blocked(fn()=>RecoveryProfile::normalize($badStrategy)),
 'source'=>$blocked(fn()=>RecoveryProfile::normalize($badSource)),
 'extra'=>$blocked(fn()=>RecoveryProfile::normalize($badExtra)),
 'unknown_with_proof'=>$blocked(fn()=>RecoveryProfile::normalize($unknownWithProof)),
 'no_profile'=>RecoveryProfile::effectiveStatus(null),
 'unknown_profile'=>RecoveryProfile::effectiveStatus($unknownWithoutProof),
 'configured'=>RecoveryProfile::effectiveStatus($profile)
], JSON_THROW_ON_ERROR),PHP_EOL;
"""


@lru_cache(maxsize=1)
def negative_profile() -> dict:
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: PHP no disponible para casos negativos")
    run = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-r", PHP_NEGATIVE, str(ROOT)],
        cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
        env=php_environment(),
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: prueba negativa PHP fallo: "
                             + run.stderr[-500:])
    try:
        value = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: resultado negativo ilegible") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: resultado negativo incompleto")
    return value


class CoberturaControlbot07Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: aserciones de comportamiento para siete unidades A."""
        self.assertEqual(len(A | B.keys() | C.keys()), 17)
        self.assertFalse((A & B.keys()) | (A & C.keys()) | (B.keys() & C.keys()))
        rollout = actual("recovery")
        profile = rollout["profile"]

        # CB-0070: adopcion declarativa real, sin simular un backup.
        with self.subTest(row="CB-0070"):
            self.assertEqual(profile["version"], 1)
            self.assertEqual(profile["project_ref"],
                             "controlbot:project/project-brvtal")
            self.assertEqual(rollout["profile_status"], "configured")
            self.assertFalse(rollout["recovery"]["restorable"])
            self.assertFalse(rollout["recovery"]["execution"])

        # CB-0071: validar configuracion en el codigo RecoveryProfile.
        with self.subTest(row="CB-0071"):
            self.assertEqual(profile["targets"], {
                "rpo_minutes": 15, "rto_minutes": 60,
            })
            self.assertEqual(profile["retention"], {
                "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12,
            })
            self.assertEqual(profile["restore_drill_cadence_hours"], 168)
            self.assertEqual(profile["sources"], {
                "database": "required", "media": "required",
                "repository": "required", "configuration": "required",
            })
            self.assertEqual(profile["strategy"], {
                "copies_required": 3, "media_types_required": 2,
                "offsite_required": True, "immutable_required": True,
                "undetected_restore_failures_target": 0,
            })
            self.assertIs(profile["encryption_required"], True)

        kinds = actual("identity", "identity-kinds")
        admins = actual("identity", "distinct-admins")
        authority = actual("identity", "authority")
        revoked = actual("identity", "revoke")
        events = actual("identity", "events")

        # CB-0081: identities tienen tipos cerrados y no exponen credenciales.
        with self.subTest(row="CB-0081"):
            self.assertEqual(kinds["kinds"], ["human", "agent", "service"])
            self.assertTrue(kinds["blocked_sensitive"])
            self.assertTrue(all(kinds["blocked_sensitive"].values()))

        # CB-0082: grant en el scope exacto, sin herencia cross-venture.
        with self.subTest(row="CB-0082"):
            for venture in ("condor", "grindflow", "brvtal"):
                self.assertEqual(admins["scoped"][venture],
                                 ["identity-" + venture + "-admin"])
                self.assertEqual(
                    admins["ventures"][venture]["responsible_identity_id"],
                    "identity-" + venture + "-admin",
                )
            self.assertEqual(authority["venture_admin"]["scope"],
                             "venture:grindflow")

        # CB-0083: autoridad canonica L4 (dueno) vs L2 (venture admin).
        with self.subTest(row="CB-0083"):
            self.assertEqual(authority["owner"]["authority_level"], "L4_OWNER")
            self.assertEqual(authority["owner"]["scope"], "group:group-pl0n3r")
            self.assertEqual(authority["venture_admin"]["authority_level"],
                             "L2_VENTURE_ADMIN")
            self.assertEqual(authority["venture_admin"]["role"], "venture_admin")

        # CB-0084: caducidad, autoridad desconocida y scope no coincidente.
        with self.subTest(row="CB-0084"):
            denied = actual("identity", "invalid-grants")["blocked"]
            self.assertTrue(denied["expired"])
            self.assertTrue(denied["unknown-authority"])
            self.assertTrue(denied["invalid-scope"])
            self.assertNotEqual(admins["scoped"]["condor"],
                                admins["scoped"]["grindflow"])

        # CB-0085: revocacion scoped conserva identidad y otro grant.
        with self.subTest(row="CB-0085"):
            self.assertEqual(revoked["identity"]["identity_id"], "identity-admin")
            self.assertEqual([g["grant_id"] for g in revoked["grants"]],
                             ["grant-condor-view"])
            self.assertEqual([e["event_id"] for e in revoked["history"]],
                             ["event-grant-grindflow", "event-revoke-grindflow"])
            self.assertEqual(revoked["history"][-1]["scope"], "venture:grindflow")
            self.assertEqual(revoked["history"][-1]["actor_identity_id"],
                             "identity-owner")
            self.assertTrue(events["future_history_blocked"])
            self.assertTrue(events["before_grant_revoke_blocked"])

    def test_falla_cerrado(self) -> None:
        """AC-02: entradas ambiguas, scopes y secretos no son permisos."""
        denied = actual("identity", "invalid-grants")["blocked"]
        self.assertEqual(set(denied), {
            "unknown-authority", "expired", "invalid-scope",
            "missing-capability", "secret-field",
        })
        self.assertTrue(all(denied.values()))
        sensitive = actual("identity", "identity-kinds")["blocked_sensitive"]
        self.assertTrue(all(sensitive.values()))
        deterministic = actual("identity", "deterministic")
        self.assertTrue(deterministic["same"])
        self.assertTrue(deterministic["extra_blocked"])
        self.assertTrue(deterministic["duplicate_blocked"])
        events = actual("identity", "events")
        self.assertTrue(events["bad_expiry_blocked"])
        self.assertTrue(actual("identity", "history-capacity")["blocked"])
        no_expiry = actual("identity", "optional-fields")
        self.assertIsNone(no_expiry["grant"]["budget_limit"])
        self.assertIsNone(no_expiry["grant"]["expires_at"])
        self.assertIsNone(no_expiry["event"]["expires_at"])
        bad = negative_profile()
        self.assertTrue(all(bad[key] for key in (
            "rpo", "retention", "strategy", "source", "extra",
            "unknown_with_proof",
        )))
        self.assertEqual(bad["no_profile"], "unknown")
        self.assertEqual(bad["unknown_profile"], "unknown")
        self.assertEqual(bad["configured"], "configured")
        with self.assertRaises(AssertionError):
            actual("identity", "unknown")
        with self.assertRaises(AssertionError):
            actual("recovery", "provider-live")

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo PHP local; ningun request ni recibo real se fabrica."""
        with (
            mock.patch.object(socket, "create_connection",
                              side_effect=AssertionError("network forbidden")),
            mock.patch.object(urllib.request, "urlopen",
                              side_effect=AssertionError("network forbidden")),
        ):
            rollout = actual("recovery")
            admins = actual("identity", "distinct-admins")
            identity = actual("identity", "identity-kinds")
        self.assertEqual(rollout["provider_decision"]["state"], "pending")
        self.assertEqual(rollout["recovery"]["dr_status"], "UNKNOWN")
        self.assertIs(rollout["recovery"]["restorable"], False)
        self.assertIs(rollout["recovery"]["execution"], False)
        self.assertTrue(all(v == "unknown" for v in (
            rollout["recovery"]["canonical_backup_receipt"],
            rollout["recovery"]["canonical_restore_drill"],
            rollout["recovery"]["immutable_copy"],
        )))
        for value in (
            rollout["recovery"]["observed_rpo_minutes"],
            rollout["recovery"]["demonstrated_rto_minutes"],
        ):
            self.assertIsNone(value)
        self.assertEqual(identity["kinds"], ["human", "agent", "service"])
        self.assertEqual(len(admins["scoped"]), 3)
        serialized = json.dumps(rollout, sort_keys=True).lower()
        self.assertFalse(any(term in serialized for term in (
            "access_token", "refresh_token", "api_key", "private_key",
            "client_secret", "storage_ref", "backup_id", "restore_id",
            "drive.google.com", "s3.amazonaws.com",
        )))


if __name__ == "__main__":
    unittest.main()
