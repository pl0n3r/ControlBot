#!/usr/bin/env python3
"""ControlBot #1012: offline, fail-closed CB-0062..CB-0076 regression tests.

Passing these local assertions never proves a live backup, provider, restore,
deployment, authenticated receipt, CI check or recovery HEALTHY status.
"""
from __future__ import annotations

import copy
import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple("CB-%04d" % n for n in range(62, 77))
UNKNOWN = "UNKNOWN"
PARTITIONS = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-01.md")
START = (
    "más de 24 horas", "tipo y causalidad", "para inspección",
    "por compatibilidad", "estructural local", "restaurable ni HEALTHY",
    "restore drill", "de expiry", "Este slice aplica", "Targets iniciales",
    "BRVTAL #389", "Google Drive permanece", "ControlBot #470",
    "La ausencia de esa decisión", "canonical backup receipt",
)
ROW_START = dict(zip(IDS, START))


@lru_cache(maxsize=16)
def scenario(script: str, case: str | None = None) -> dict:
    if script not in ("backup_gate_scenarios.php",
                      "brvtal_recovery_rollout_scenarios.php"):
        raise AssertionError("UNKNOWN: unauthorized scenario")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: php absent")
    command = [php, str(ROOT / "tests" / script)]
    if case:
        command.append(case)
    result = subprocess.run(command, cwd=ROOT, capture_output=True,
                            text=True, timeout=25, check=False)
    if result.returncode:
        raise AssertionError("UNKNOWN: local PHP scenario failed")
    try:
        answer = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP JSON") from exc
    if not isinstance(answer, dict):
        raise AssertionError("UNKNOWN: non-object PHP evidence")
    return answer


def parts() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8")
            for path in PARTITIONS}


def matrix_rows(texts: dict[str, str]) -> dict[str, tuple[str, ...]]:
    if set(texts) != set(PARTITIONS):
        raise ValueError("UNKNOWN: matrix partitions")
    rows: dict[str, tuple[str, ...]] = {}
    for path, source in texts.items():
        if not isinstance(source, str):
            raise ValueError("UNKNOWN: matrix source")
        for line in source.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if not match or match.group(1) not in IDS:
                continue
            key = match.group(1)
            if key in rows:
                raise ValueError("UNKNOWN: duplicate row " + key)
            cells = tuple(cell.strip()
                          for cell in line.strip().strip("|").split("|"))
            if len(cells) != 6 or cells[0] != key:
                raise ValueError("UNKNOWN: malformed row " + key)
            expected = PARTITIONS[0] if key == "CB-0070" else PARTITIONS[1]
            if path != expected:
                raise ValueError("UNKNOWN: unexpected partition " + key)
            rows[key] = cells
    return rows


def evidence() -> dict:
    gate = "backup_gate_scenarios.php"
    return {
        "freshness": scenario(gate, "freshness"),
        "readonly": scenario(gate, "readonly"),
        "missing": scenario(gate, "missing"),
        "scope": scenario(gate, "scope"),
        "causality": scenario(gate, "causality"),
        "safe": scenario(gate, "evidence"),
        "rollout": scenario("brvtal_recovery_rollout_scenarios.php"),
    }


def classify(rows: dict[str, tuple[str, ...]], data: dict) -> dict:
    """Never promote missing/ambiguous evidence to LOCAL_CONTRACT."""
    result = {key: {"code": UNKNOWN, "ci": UNKNOWN,
                    "live": UNKNOWN, "production": UNKNOWN} for key in IDS}
    try:
        f, ro, missing, scope, causal, safe, b = (
            data[k] for k in ("freshness", "readonly", "missing", "scope",
                              "causality", "safe", "rollout"))
        profile, old, provider, recovery = (
            b[k] for k in ("profile", "legacy_capability",
                            "provider_decision", "recovery"))
        freshness = (
            f["at_24h"]["allowed"] is True
            and f["at_24h"]["reason"] == "backup_verified"
            and f["stale_unexpired"]["allowed"] is False
            and f["stale_unexpired"]["reason"] == "backup_not_usable_for_write"
        )
        expiry = all(f[k]["allowed"] is False
                     and f[k]["reason"] == "backup_not_usable_for_write"
                     for k in ("no_expiration", "expired", "stale_unexpired"))
        protected = (
            missing["allowed"] is False
            and missing["reason"] == "backup_receipt_required"
            and all(x["allowed"] is False
                    and x["reason"] == "backup_scope_mismatch"
                    for x in scope.values())
            and causal["not_ready"]["allowed"] is False
            and causal["verified_after_write"]["allowed"] is False
        )
        operational_unknown = (
            all(recovery[k] == "unknown" for k in (
                "canonical_backup_receipt", "offsite_copy", "immutable_copy",
                "canonical_restore_drill"))
            and recovery["observed_rpo_minutes"] is None
            and recovery["demonstrated_rto_minutes"] is None
            and recovery["dr_status"] == UNKNOWN
            and recovery["restorable"] is False
            and recovery["execution"] is False
        )
        historical = (
            old["source_issue"] == 389
            and old["scheduled_backup_capability"] is True
            and old["google_drive_optional"] is True
            and old["primary_immutable_verified"] is False
            and old["canonical_contracts"] == {
                "backup_receipt": False, "recovery_evidence": False,
                "restore_drill": False}
        )
        decision = (
            provider == {"issue": 470, "state": "pending",
                         "primary_offsite_immutable": "unknown"}
            and "storage_ref" not in b
            and "storage_ref" not in recovery
        )
        checks = {
            "CB-0062": freshness,
            "CB-0063": expiry,
            "CB-0064": ro["read_unbounded"]["allowed"] is True
                and ro["read_unbounded"]["backup_required"] is False
                and f["no_expiration"]["allowed"] is False,
            "CB-0065": f["fresh"]["reason"] == "backup_verified"
                and safe["evidence"]["status"] == "ready"
                and "healthy" not in safe["evidence"],
            "CB-0066": safe["allowed"] is True
                and safe["contains_artifact_ref"] is False
                and safe["contains_digest"] is False
                and safe["contains_payload_key"] is False
                and all(k not in safe["evidence"] for k in (
                    "payload", "provider", "offsite", "restorable")),
            "CB-0067": operational_unknown
                and safe["evidence"]["status"] == "ready"
                and "dr_status" not in safe["evidence"],
            "CB-0068": protected and expiry and operational_unknown,
            "CB-0069": freshness and expiry and protected
                and f["future_verification"]["allowed"] is False,
            "CB-0070": b["profile_status"] == "configured"
                and profile["project_ref"] ==
                    "controlbot:project/project-brvtal"
                and profile["manifest_ref"] ==
                    "controlbot:recovery-manifest/project-brvtal-v1"
                and operational_unknown,
            "CB-0071": profile["targets"] == {
                    "rpo_minutes": 15, "rto_minutes": 60}
                and profile["retention"] == {
                    "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12}
                and profile["restore_drill_cadence_hours"] == 168
                and profile["sources"] == {
                    "database": "required", "media": "required",
                    "repository": "required", "configuration": "required"}
                and profile["strategy"] == {
                    "copies_required": 3, "media_types_required": 2,
                    "offsite_required": True, "immutable_required": True,
                    "undetected_restore_failures_target": 0}
                and profile["encryption_required"] is True,
            "CB-0072": historical,
            "CB-0073": historical and decision,
            "CB-0074": decision,
            "CB-0075": decision and operational_unknown,
            "CB-0076": operational_unknown,
        }
    except (KeyError, ValueError, TypeError, IndexError, AttributeError):
        return result

    for key in IDS:
        row = rows.get(key)
        doc = ("docs/backup-receipt-integrity.md" if key <= "CB-0069"
               else "docs/brvtal-recovery-rollout.md")
        if (row and len(row) == 6 and row[3] == "NO_VERIFICABLE"
                and doc in row[1]
                and ROW_START[key].lower()
                    in row[2].replace(chr(96), "").lower()
                and checks[key]):
            result[key]["code"] = "LOCAL_CONTRACT"
    return result


class BrechasControlbot05Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = matrix_rows(parts())
        self.assertEqual(set(rows), set(IDS))
        for key, verdict in classify(rows, evidence()).items():
            with self.subTest(requirement=key):
                self.assertEqual(rows[key][3], "NO_VERIFICABLE")
                self.assertEqual(verdict["code"], "LOCAL_CONTRACT")

    def test_falla_cerrado(self) -> None:
        fragments = parts()
        rows = matrix_rows(fragments)
        sample = evidence()
        duplicated = dict(fragments)
        row = next(x for x in fragments[PARTITIONS[1]].splitlines()
                   if x.startswith("| CB-0062 |"))
        duplicated[PARTITIONS[1]] += "\n" + row
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(duplicated)
        for key in IDS:
            with self.subTest(requirement=key):
                missing = dict(rows)
                del missing[key]
                self.assertEqual(classify(missing, sample)[key]["code"], UNKNOWN)
                replaced = dict(rows)
                cells = list(rows[key])
                cells[3] = "CUBIERTO"
                replaced[key] = tuple(cells)
                self.assertEqual(classify(replaced, sample)[key]["code"], UNKNOWN)
        for group, slot, field, value, key in (
            ("freshness", "at_24h", "allowed", False, "CB-0062"),
            ("freshness", "no_expiration", "allowed", True, "CB-0063"),
            ("safe", None, "contains_digest", True, "CB-0066"),
            ("rollout", "provider_decision", "state", "chosen", "CB-0074"),
            ("rollout", "recovery", "dr_status", "HEALTHY", "CB-0076"),
        ):
            tampered = copy.deepcopy(sample)
            target = tampered[group] if slot is None else tampered[group][slot]
            target[field] = value
            self.assertEqual(classify(rows, tampered)[key]["code"], UNKNOWN)
        for key in IDS:
            self.assertEqual(classify(rows, {})[key]["code"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        for script in ("backup_gate_scenarios.php",
                       "brvtal_recovery_rollout_scenarios.php"):
            fixture = ROOT / "tests" / script
            self.assertTrue(fixture.is_file())
            source = fixture.read_text(encoding="utf-8").lower()
            for command in ("curl_", "fsockopen", "stream_socket_client",
                            "mysqli_connect", "shell_exec", "proc_open"):
                self.assertNotIn(command, source)
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        sample = evidence()
        for field in ("contains_artifact_ref", "contains_digest",
                      "contains_payload_key"):
            self.assertFalse(sample["safe"][field])
        serialized = json.dumps(sample["rollout"], sort_keys=True).lower()
        for forbidden in ('"password"', '"access_token"', '"api_key"',
                          '"storage_ref"', '"backup_id"', '"restore_id"'):
            self.assertNotIn(forbidden, serialized)

    def test_estados_unknown_explicitos(self) -> None:
        observed = evidence()
        results = classify(matrix_rows(parts()), observed)
        for key in IDS:
            with self.subTest(requirement=key):
                for field in ("ci", "live", "production"):
                    self.assertEqual(results[key][field], UNKNOWN)
        self.assertEqual(observed["rollout"]["profile_status"], "configured")
        recovery = observed["rollout"]["recovery"]
        self.assertEqual(recovery["dr_status"], UNKNOWN)
        self.assertFalse(recovery["restorable"])
        self.assertIsNone(recovery["observed_rpo_minutes"])
        self.assertIsNone(recovery["demonstrated_rto_minutes"])


if __name__ == "__main__":
    unittest.main()
