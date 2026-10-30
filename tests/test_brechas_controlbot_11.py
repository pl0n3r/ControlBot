#!/usr/bin/env python3
"""ControlBot #1018: CB-0152..0166, recovery governance offline.

Only synthetic local profiles and PHP normalization are exercised. A passing
test never certifies a backup, provider authorization, restore, RPO/RTO or live.
"""
from __future__ import annotations

import copy
import json
import re
import subprocess
import unittest
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "matriz-cobertura-parte-01.md"
CONTRACT = ROOT / "docs" / "disaster-recovery-contract.md"
RECOVERY = ROOT / "config" / "recovery"
IDS = tuple(f"CB-{number:04d}" for number in range(152, 167))
GROUP = frozenset({
    "autofactory", "brvtal", "condor", "controlbot",
    "factory", "factoryrunner", "grindflow",
})
DECLARED = GROUP - {"factoryrunner"}
UNKNOWN = "UNKNOWN"

# The PHP class under test is the actual repository implementation. Its only
# input is a JSON-encoded synthetic copy of a declared recovery profile.
PHP_VALIDATE = r"""
require 'src/InfrastructureProvider.php';
require 'src/RecoveryProfile.php';
$cases = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$out = [];
foreach ($cases as $name => $raw) {
    try {
        if ($raw === null) {
            $out[$name] = ['accepted' => false,
                'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus(null)];
            continue;
        }
        $profile = \ControlBot\Infrastructure\RecoveryProfile::normalize($raw);
        $out[$name] = ['accepted' => true,
            'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus($raw),
            'profile' => $profile];
    } catch (\Throwable $e) {
        $out[$name] = ['accepted' => false, 'status' => 'unknown'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def profiles() -> dict[str, dict]:
    """Only versioned repository-local metadata, never provider state."""
    return {
        path.stem: json.loads(path.read_text(encoding="utf-8"))
        for path in sorted(RECOVERY.glob("*.json"))
    }


def validate(cases: dict[str, dict | None]) -> dict:
    result = subprocess.run(
        ["php", "-r", PHP_VALIDATE], input=json.dumps(cases), cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=30,
    )
    if result.returncode:
        raise AssertionError(f"Local RecoveryProfile harness failed: {result.stderr[:400]}")
    output = json.loads(result.stdout)
    if not isinstance(output, dict) or set(output) != set(cases):
        raise AssertionError("Invalid or incomplete PHP scenario response")
    return output


def matrix_states(text: str) -> dict[str, str]:
    """Absent, duplicated, altered or prematurely promoted rows are UNKNOWN."""
    found: dict[str, list[list[str]]] = {clause: [] for clause in IDS}
    for line in text.splitlines():
        if not line.startswith("| CB-"):
            continue
        fields = [field.strip() for field in line.strip("|").split("|")]
        if fields and fields[0] in found:
            found[fields[0]].append(fields)
    states = {}
    for clause, rows in found.items():
        if (len(rows) == 1 and len(rows[0]) == 6
                and rows[0][0] == clause
                and "docs/disaster-recovery-contract.md" in rows[0][1]
                and credible_offline_coverage_row(rows[0])):
            states[clause] = rows[0][3]
        else:
            states[clause] = UNKNOWN
    return states


def operational_state(_clause: str, _evidence: object = None) -> str:
    """A declarative profile or an asserted approval is not a signed receipt."""
    return UNKNOWN


class BrechasControlbot11Tests(unittest.TestCase):
    """Each ID receives a distinct observable regression and its own verdict."""

    def test_contrato_principal(self) -> None:
        declared = profiles()
        result = validate({**declared, "factoryrunner": None})
        doc = CONTRACT.read_text(encoding="utf-8")
        condor = result["condor"]["profile"]
        factory = result["factory"]["profile"]
        canonical = {
            "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12,
        }
        strategy = {
            "copies_required": 3,
            "media_types_required": 2,
            "offsite_required": True,
            "immutable_required": True,
            "undetected_restore_failures_target": 0,
        }
        forbidden_receipt_fields = {
            "backup_receipt", "restore_receipt", "observed_rpo",
            "observed_rto", "object_lock_verified", "backup_verified",
            "provider_credentials", "authorization",
        }
        verdicts = {
            "CB-0152": (
                set(declared) == DECLARED and len(GROUP) == 7
                and all(result[name]["status"] == "configured"
                        for name in DECLARED)
            ),
            "CB-0153": (
                "factoryrunner" not in declared
                and result["factoryrunner"] == {
                    "accepted": False, "status": "unknown"
                }
            ),
            "CB-0154": (
                condor["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                and condor["retention"] == canonical
                and condor["source_ref"] == "https://github.com/pl0n3r/Factory/issues/305"
                and "Factory #305" in doc
            ),
            "CB-0155": (
                condor["sources"] == {
                    "database": "required", "media": "required",
                    "repository": "required", "configuration": "not_applicable",
                }
                and factory["sources"]["configuration"] == "required"
                and all(result[name]["profile"]["targets"]["rpo_minutes"] > 0
                        and result[name]["profile"]["targets"]["rto_minutes"] > 0
                        for name in DECLARED)
            ),
            "CB-0156": (
                all(result[name]["profile"]["strategy"] == strategy
                        and result[name]["profile"]["encryption_required"] is True
                        and set(result[name]["profile"]["retention"]) == set(canonical)
                        for name in DECLARED)
                and result["condor"]["status"] == "configured"
            ),
            "CB-0157": (
                all(not (forbidden_receipt_fields & set(result[name]["profile"]))
                        for name in DECLARED)
                and operational_state("CB-0157", result) == UNKNOWN
                and "No confirma backups" in doc
            ),
            "CB-0158": (
                "decisión B histórica de #577" in doc
                and "opción A del OWNER en #858" in doc
                and "journal `factory-human-decision` v2 autenticado" in doc
                and operational_state("CB-0158", {"option": "A"}) == UNKNOWN
            ),
            "CB-0159": (
                "preflight read-only de versioning/Object Lock" in doc
                and "credenciales mínimas por canal privado" in doc
                and operational_state("CB-0159", {"preflight": "configured"}) == UNKNOWN
            ),
            "CB-0160": (
                "no autoriza provisión" in doc
                and all("provider" not in p and "storage_ref" not in p for p in declared.values())
                and operational_state("CB-0160", {"bucket": "declared"}) == UNKNOWN
            ),
            "CB-0161": (
                "no autoriza alta de cuenta" in doc
                and "aceptar términos, pagos o gasto" in doc
                and operational_state("CB-0161", {"owner_decision": "A"}) == UNKNOWN
            ),
            "CB-0162": (
                "en GitHub/chat, uso de datos reales" in doc
                and "escritura de backup, restore, destrucción" in doc
                and "o go-live" in doc
                and operational_state("CB-0162", {"dry_run": True}) == UNKNOWN
            ),
            "CB-0163": (
                "Factory y FactoryRunner son productores" in doc
                and "checksum, cifrado, offsite, Object Lock, freshness" in doc
                and all(not forbidden_receipt_fields.intersection(declared[name])
                        for name in DECLARED)
            ),
            "CB-0164": (
                "Hasta tener receipts reales, se conserva `UNKNOWN`" in doc
                and result["factoryrunner"]["status"] == "unknown"
                and operational_state("CB-0164", {"receipt": None}) == UNKNOWN
            ),
            "CB-0165": (
                "no HEALTHY" in doc
                and "RPO/RTO observados siguen sin demostrarse" in doc
                and operational_state("CB-0165", result["condor"]) == UNKNOWN
            ),
            "CB-0166": (
                condor["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                and "estado **operativo** permanece UNKNOWN" in doc
                and operational_state("CB-0166", result["condor"]) == UNKNOWN
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(verdicts[clause], f"{clause}: contrato local no demostrado")
                self.assertIn(states[clause], ("NO_VERIFICABLE", "CUBIERTO"))
                self.assertEqual(operational_state(clause, verdicts[clause]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """Corrupted matrix rows and incomplete synthetic profiles are never green."""
        raw = MATRIX.read_text(encoding="utf-8")
        current = profiles()["controlbot"]
        mutations = {}
        missing = copy.deepcopy(current)
        del missing["targets"]
        mutations["missing_targets"] = missing
        no_provenance = copy.deepcopy(current)
        no_provenance["source_ref"] = None
        mutations["missing_provenance"] = no_provenance
        empty_retention = copy.deepcopy(current)
        empty_retention["retention"] = {name: 0 for name in current["retention"]}
        mutations["no_retention"] = empty_retention
        no_encryption = copy.deepcopy(current)
        no_encryption["encryption_required"] = False
        mutations["no_encryption"] = no_encryption
        no_immutability = copy.deepcopy(current)
        no_immutability["strategy"]["immutable_required"] = False
        mutations["no_immutability"] = no_immutability
        secret = copy.deepcopy(current)
        secret["api_key"] = "synthetic-forbidden-field"
        mutations["unknown_secret_field"] = secret
        stale = copy.deepcopy(current)
        stale["freshness"] = "stale"
        mutations["stale"] = stale
        checked = validate(mutations)
        for name in mutations:
            with self.subTest(synthetic_profile=name):
                self.assertEqual(checked[name]["status"], "unknown")
                if name != "stale":
                    self.assertFalse(checked[name]["accepted"])
        for clause in IDS:
            rows = [line for line in raw.splitlines()
                    if line.startswith(f"| {clause} |")]
            self.assertEqual(len(rows), 1, clause)
            row = rows[0]
            altered = (
                raw.replace(row + "\n", "", 1),
                raw + "\n" + row,
                raw.replace(row, (row.replace("MERGED", "PENDING", 1) if "CUBIERTO" in row
                                  else row.replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1),
            )
            for candidate in altered:
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(candidate)[clause], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """Only deterministic local JSON/PHP, no providers or credentials."""
        self.assertTrue(MATRIX.is_file())
        self.assertTrue(CONTRACT.is_file())
        self.assertEqual(set(profiles()), DECLARED)
        for name, profile in profiles().items():
            with self.subTest(profile=name):
                self.assertEqual(profile["project_ref"],
                                 f"controlbot:project/project-{name}")
                self.assertEqual(profile["version"], 1)
                self.assertEqual(set(profile), {
                    "version", "project_ref", "manifest_ref", "targets", "retention",
                    "sources", "strategy", "encryption_required",
                    "restore_drill_cadence_hours", "source_ref",
                    "observed_at", "freshness",
                })
                serialized = json.dumps(profile).lower()
                for secret in ("password", "bearer ", "api_key", "access_token",
                               "private_key", "credentials", "signed_url",
                               "backup_payload", "person@example"):
                    self.assertNotIn(secret, serialized)
        self.assertNotIn("curl_", PHP_VALIDATE)
        self.assertNotIn("file_put_contents", PHP_VALIDATE)

    def test_estados_unknown_explicitos(self) -> None:
        """The 15 operational claims stay UNKNOWN even after successful CI."""
        recorded = matrix_states(MATRIX.read_text(encoding="utf-8"))
        missing = validate({"factoryrunner": None})["factoryrunner"]
        self.assertEqual(missing, {"accepted": False, "status": "unknown"})
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIn(recorded[clause], ("NO_VERIFICABLE", "CUBIERTO"))
                for claim in (None, {}, True, "success", "HEALTHY",
                              {"ci": "success"}, {"option": "A"},
                              {"configured": True}, {"restore": "claimed"},
                              {"provider": "claimed"}, {"rpo_observed": 15}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)
        stale = copy.deepcopy(profiles()["controlbot"])
        stale["freshness"] = "stale"
        self.assertEqual(validate({"stale": stale})["stale"]["status"], "unknown")


if __name__ == "__main__":
    unittest.main()
