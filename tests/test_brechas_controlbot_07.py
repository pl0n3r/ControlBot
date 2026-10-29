#!/usr/bin/env python3
"""ControlBot #1014: CB-0092..CB-0106, synthetic fail-closed regressions.

LOCAL_CONTRACT is not a CI attestation, financial approval, real backup,
durable revocation, production authority or permission to execute payments.
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
IDS = tuple(f"CB-{number:04d}" for number in range(92, 107))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
PARTITIONS = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-01.md")
SCRIPTS = {
    "venture": "venture_access_runtime_scenarios.php",
    "revocation": "capability_grant_revoker_scenarios.php",
    "capital": "capital_policy_scenarios.php",
    "backup": "controlbot_backup_scenarios.php",
}
CASES = {
    "venture": {"audit", "replay", "restrictions", "secrets"},
    "revocation": {"finish", "deny", "existing", "privacy"},
    "capital": {"base", "restrictions", "owner-gates", "shared-costs",
                "no-payments", "deterministic"},
    "backup": {"backup", "restore", "pure"},
}
DOCS = {
    "CB-0092": "docs/business-os-integration.md",
    **{f"CB-{i:04d}": "docs/capability-grant-revocation.md"
       for i in range(93, 99)},
    **{f"CB-{i:04d}": "docs/capital-policy.md"
       for i in range(99, 105)},
    **{f"CB-{i:04d}": "docs/controlbot-backup-v1.md"
       for i in range(105, 107)},
}
ANCHORS = (
    "La auditoría de la fachada", "Instanciar un", "Usar",
    "Para verificar autorización", "La auditoría solo contiene",
    "El mapa de revocación", "Reversión: retirar",
    "A request is evaluated", "one capital allocation proposal",
    "All nested budget", "CAPITAL never raises",
    "The schema is closed", "Normalization is deterministic",
    "Slice puro de", "BackupReceipt",
)
ANCHOR = dict(zip(IDS, ANCHORS))


@lru_cache(maxsize=25)
def scenario(group: str, case: str) -> dict:
    """Run only allowlisted, existing PHP fixtures with no network."""
    if group not in CASES or case not in CASES[group]:
        raise ValueError("UNKNOWN: untrusted fixture")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: local PHP unavailable")
    result = subprocess.run(
        [php, str(ROOT / "tests" / SCRIPTS[group]), case],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: local synthetic fixture failed")
    try:
        output = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid local evidence") from exc
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: local evidence must be an object")
    return output


def matrix_parts() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8")
            for path in PARTITIONS}


def matrix_rows(parts: object) -> dict[str, tuple[str, ...]]:
    if (not isinstance(parts, dict) or set(parts) != set(PARTITIONS)
            or any(not isinstance(value, str) or not value.strip()
                   for value in parts.values())):
        raise ValueError("UNKNOWN: incomplete matrix")
    rows: dict[str, tuple[str, ...]] = {}
    for path, source in parts.items():
        for line in source.splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(cell.strip()
                          for cell in line.strip().strip("|").split("|"))
            norm = re.sub(r"[\x60*]", "", cells[2]) if len(cells) > 2 else ""
            if (key in rows or len(cells) != 6 or cells[0] != key
                    or not cells[1].startswith("[" + DOCS[key] + ":L")
                    or ANCHOR[key].casefold() not in norm.casefold()
                    or cells[3] != "NO_VERIFICABLE"
                    or "Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN"
                    not in cells[4]):
                raise ValueError("UNKNOWN: contradictory row " + key)
            expected = PARTITIONS[0] if key in (
                "CB-0093", "CB-0099", "CB-0105") else PARTITIONS[1]
            if path != expected:
                raise ValueError("UNKNOWN: wrong partition " + key)
            rows[key] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing matrix requirements")
    return rows


def evidence() -> dict:
    return {group: {case: scenario(group, case) for case in sorted(cases)}
            for group, cases in CASES.items()}


def classify(rows: dict, data: dict) -> dict[str, dict[str, str]]:
    """No incomplete fixture or promoted row can become local evidence."""
    verdicts = {key: {status: UNKNOWN for status in
                     ("code", "ci", "live", "production")} for key in IDS}
    try:
        v, r, c, b = (data[g] for g in ("venture", "revocation",
                                      "capital", "backup"))
        audit = v["audit"]
        finish, deny, old, private = (
            r[k] for k in ("finish", "deny", "existing", "privacy"))
        base, restrict, owner, costs, payment, det = (
            c[k] for k in ("base", "restrictions", "owner-gates",
                           "shared-costs", "no-payments", "deterministic"))
        backup, restore, pure = (b[k] for k in ("backup", "restore", "pure"))
        audit_privacy = json.dumps(audit, sort_keys=True).casefold()
        revocation_privacy = json.dumps(private, sort_keys=True).casefold()
        budget = base["budget"]
        reserve = base["reserve"]
        proposal = base["proposal"]
        checks = {
            "CB-0092": (
                [audit[key]["kind"] for key in
                 ("grant", "revoke", "override", "deny")]
                == ["grant", "revoke", "override", "deny"]
                and all(audit[key]["actor_identity_id"] == "identity-admin"
                        and audit[key]["scope"] == "venture:alpha"
                        for key in ("grant", "revoke", "override", "deny"))
                and all(token not in audit_privacy for token in
                        ("password", "cookie", "token", "otp", "secret"))
                and v["replay"]["first"]["status"] == "applied"
                and v["replay"]["replay"]["status"] == "already_applied"
                and v["replay"]["replay"]["audit"]["outcome"] == "applied"
                and v["replay"]["replay"]["audit"]["occurred_at"]
                == v["replay"]["first"]["audit"]["occurred_at"]
            ),
            "CB-0093": (
                old["guard_read"] == old["read"]
                and old["read"]["authorized"] is True
                and old["guard_strict"] == {
                    "authorized": False, "reason": "policy_denied"}
                and old["scope_wrong"] == {
                    "authorized": False, "reason": "project_mismatch"}
                and old["restricted"] == {
                    "authorized": False, "reason": "grant_expired"}
            ),
            "CB-0094": (
                finish["success"]["status"] == "executed"
                and finish["ambiguous"]["status"] == "ambiguous"
                and finish["ambiguous"]["error_code"] ==
                    "fake_execution_unconfirmed"
                and finish["failed"]["status"] == "failed"
                and finish["failed"]["error_code"] == "fake_execution_failed"
                and old["fake_strict"]["reason"] == "policy_denied"
                and old["fake_calls"] == 0
                and finish["calls"] == 1
            ),
            "CB-0095": (
                finish["replay"] == finish["success"]
                and finish["restricted_replay"]["reason"] ==
                    "idempotency_collision"
                and finish["after"] == {
                    "authorized": False, "reason": "grant_consumed"}
                and finish["failed_after"]["reason"] == "grant_consumed"
                and deny["reentrant"]["reason"] == "grant_in_flight"
                and deny["collision"]["reason"] == "idempotency_collision"
                and deny["scope_collision"]["reason"] ==
                    "idempotency_collision"
            ),
            "CB-0096": (
                set(private["failed"]["audit"]) == {
                    "event", "status", "at"}
                and private["failed"]["error_code"] ==
                    "fake_execution_failed"
                and finish["ambiguous"]["error_code"] ==
                    "fake_execution_unconfirmed"
                and all(token not in revocation_privacy for token in (
                    "password", "cookie", "private", "hidden", "bearer",
                    "token=", "stack"))
            ),
            "CB-0097": (
                old["read"]["authorized"] is True
                and old["original_revoked_at"] is None
                and finish["after"]["authorized"] is False
                and finish["success"]["revoked_at"] ==
                    "2026-09-28T08:10:00Z"
            ),
            "CB-0098": (
                finish["success"]["status"] == "executed"
                and deny["expired"]["reason"] == "grant_expired"
                and old["guard_strict"]["reason"] == "policy_denied"
                and v["secrets"]["blocked"]
                == {key: True for key in (
                    "password", "password_hash", "token", "cookie",
                    "otp", "recovery_code", "two_factor_secret")}
            ),
            "CB-0099": (
                base["scope"] == "venture:condor"
                and base["currency"] == "COP"
                and base["decision"] == "allow"
                and base["execution"] is False
                and all(isinstance(field, int) and not isinstance(field, bool)
                        for field in (
                            proposal["amount_minor"], budget["limit_minor"],
                            reserve["cash_available_minor"]))
            ),
            "CB-0100": (
                proposal["amount_minor"] == 5_000_000
                and budget["after_proposal_minor"] == 12_000_000
                and reserve["cash_after_proposal_minor"] == 45_000_000
                and owner["irreversible"]["decision"] ==
                    "owner_decision_required"
                and owner["l4"]["decision"] == "owner_decision_required"
            ),
            "CB-0101": (
                det["unknown"]["decision"] == "deny"
                and "unknown_financial_state" in det["unknown"]["reasons"]
                and det["mismatch"]["decision"] == "deny"
                and det["mismatch"]["reasons"] == ["invalid_input"]
                and det["mismatch"]["execution"] is False
                and base["scope"] == "venture:condor"
                and base["currency"] == "COP"
                and det["invalid_gate"]["decision"] == "deny"
            ),
            "CB-0102": (
                restrict["baseline"]["decision"] == "allow"
                and restrict["budget_guard"]["decision"] == "deny"
                and "budget_guard_restricted"
                    in restrict["budget_guard"]["reasons"]
                and restrict["decision_rights"]["decision"] == "deny"
                and "decision_rights_denied"
                    in restrict["decision_rights"]["reasons"]
                and all(owner[key]["decision"] == "owner_decision_required"
                        and owner[key]["execution"] is False
                        for key in ("over_limit", "l4", "irreversible",
                                    "reserve_floor"))
            ),
            "CB-0103": (
                payment["evaluated"]["decision"] == "deny"
                and payment["evaluated"]["execution"] is False
                and payment["leaked"] is False
                and "does not execute payments" in payment["payment"]
                and det["invalid_gate"]["decision"] == "deny"
                and det["invalid_gate"]["execution"] is False
                and "invalid_input" in det["invalid_gate"]["reasons"]
            ),
            "CB-0104": (
                det["same"] is True
                and det["first"] == det["second"]
                and det["unknown"]["decision"] == "deny"
                and det["mismatch"]["execution"] is False
                and costs["execution"] is False
                and any(item["status"] == "unallocated"
                        and item["target_scope"] is None
                        for item in costs["shared_costs"])
            ),
            "CB-0105": (
                restore["valid"]["status"] == "passed"
                and restore["valid"]["target_environment"] == "restore-drill"
                and restore["valid"]["source_checksum"] ==
                    restore["valid"]["destination_checksum"]
                and all(restore[key] is True for key in (
                    "production_rejected", "checksum_rejected",
                    "before_backup_rejected"))
                and pure["hits"] == []
            ),
            "CB-0106": (
                backup["valid"]["project"] == "controlbot"
                and backup["valid"]["environment"] == "production"
                and backup["valid"]["resource"] == "database:primary"
                and backup["valid"]["status"] == "completed"
                and backup["valid"]["size_bytes"] > 0
                and len(backup["valid"]["checksum"]) == 64
                and re.fullmatch(r"[0-9a-f]{64}",
                                 backup["valid"]["checksum"]) is not None
                and backup["valid"]["encryption"] == "kms-managed"
                and backup["valid"]["storage_ref"].startswith(
                    "backup:controlbot/")
                and all(backup[key] is True for key in (
                    "size_rejected", "url_rejected", "secret_rejected"))
            ),
        }
    except (KeyError, ValueError, TypeError, AttributeError, IndexError):
        return verdicts
    for key in IDS:
        if (key in rows and len(rows[key]) == 6
                and rows[key][3] == "NO_VERIFICABLE"
                and DOCS[key] in rows[key][1]
                and checks[key]):
            verdicts[key]["code"] = LOCAL
    return verdicts


class BrechasControlbot07Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = matrix_rows(matrix_parts())
        self.assertEqual(set(rows), set(IDS))
        report = classify(rows, evidence())
        for key in IDS:
            with self.subTest(requirement=key):
                self.assertEqual(rows[key][3], "NO_VERIFICABLE")
                self.assertEqual(report[key]["code"], LOCAL)

    def test_falla_cerrado(self) -> None:
        texts = matrix_parts()
        rows = matrix_rows(texts)
        data = evidence()
        duplicate = dict(texts)
        source_row = next(line for line in duplicate[PARTITIONS[1]].splitlines()
                          if line.startswith("| CB-0092 |"))
        duplicate[PARTITIONS[1]] += "\n" + source_row
        with self.assertRaisesRegex(ValueError, "duplicate|contradictory"):
            matrix_rows(duplicate)
        for key in IDS:
            with self.subTest(missing=key):
                missing = dict(rows)
                del missing[key]
                self.assertEqual(classify(missing, data)[key]["code"], UNKNOWN)
                promoted = dict(rows)
                cells = list(rows[key])
                cells[3] = "CUBIERTO"
                promoted[key] = tuple(cells)
                self.assertEqual(classify(promoted, data)[key]["code"], UNKNOWN)
        for group, case, path, value, identifier in (
            ("venture", "replay", ("replay", "audit"), {}, "CB-0092"),
            ("revocation", "existing", ("guard_strict", "reason"),
             "eligible", "CB-0093"),
            ("revocation", "finish", ("calls",), 101, "CB-0094"),
            ("revocation", "finish", ("replay", "status"),
             "rejected", "CB-0095"),
            ("revocation", "privacy", ("failed", "error_code"),
             "private-token", "CB-0096"),
            ("capital", "deterministic", ("same",), False, "CB-0104"),
            ("capital", "no-payments", ("leaked",), True, "CB-0103"),
            ("backup", "backup", ("valid", "size_bytes"), 0, "CB-0106"),
            ("backup", "restore", ("valid", "target_environment"),
             "production", "CB-0105"),
        ):
            corrupted = copy.deepcopy(data)
            target = corrupted[group][case]
            for part in path[:-1]:
                target = target[part]
            target[path[-1]] = value
            self.assertEqual(classify(rows, corrupted)[identifier]["code"],
                             UNKNOWN)
        self.assertTrue(all(classify(rows, {})[key]["code"] == UNKNOWN
                            for key in IDS))

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(SCRIPTS), set(CASES))
        for script in SCRIPTS.values():
            self.assertTrue((ROOT / "tests" / script).is_file())
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            source, r"(?m)^(?:import|from)\s+(?:socket|requests|httpx|urllib)\b")
        self.assertNotIn("https://api.", source)
        observations = evidence()
        self.assertEqual(observations["backup"]["pure"]["hits"], [])
        self.assertTrue(all(observations["venture"]["secrets"]["blocked"].values()))
        self.assertFalse(observations["capital"]["no-payments"]["leaked"])
        for data in (observations["venture"]["audit"],
                     observations["revocation"]["privacy"]):
            encoded = json.dumps(data, sort_keys=True).casefold()
            for forbidden in ("password=", "token=", "cookie=", "secret="):
                self.assertNotIn(forbidden, encoded)

    def test_estados_unknown_explicitos(self) -> None:
        rows = matrix_rows(matrix_parts())
        results = classify(rows, evidence())
        for key in IDS:
            with self.subTest(requirement=key):
                self.assertEqual(results[key]["code"], LOCAL)
                self.assertEqual(results[key]["ci"], UNKNOWN)
                self.assertEqual(results[key]["live"], UNKNOWN)
                self.assertEqual(results[key]["production"], UNKNOWN)
        for bad in ({}, {"revocation": {}}, {"capital": None}):
            result = classify(rows, bad)
            self.assertTrue(all(result[key]["code"] == UNKNOWN for key in IDS))
        with self.assertRaisesRegex(ValueError, "incomplete"):
            matrix_rows({PARTITIONS[0]: matrix_parts()[PARTITIONS[0]]})


if __name__ == "__main__":
    unittest.main()
