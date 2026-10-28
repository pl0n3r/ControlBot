#!/usr/bin/env python3
"""ControlBot #1015: synthetic, fail-closed CB-0107 through CB-0121 tests.

A local contract is not a backup receipt, completed restore, CI result or
production attestation. The matrix itself is not modified by this test.
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
IDS = tuple("CB-%04d" % i for i in range(107, 122))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
SCRIPTS = {
    "backup": "controlbot_backup_scenarios.php",
    "rollout": "controlbot_recovery_rollout_scenarios.php",
    "policy": "disaster_recovery_policy_scenarios.php",
    "feedback": "customer_feedback_signal_scenarios.php",
}
PARTITIONS = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-01.md")
ROW_START = {
    "CB-0107": "RestoreReceipt",
    "CB-0108": "restorable",
    "CB-0109": "retentionPlan",
    "CB-0110": "URLs públicas/firmadas",
    "CB-0111": "Este slice aplica",
    "CB-0112": "ControlBot declara",
    "CB-0113": "profile_status=configured",
    "CB-0114": "Por diseño",
    "CB-0115": "El test de rollout",
    "CB-0116": "No ejecuta backups",
    "CB-0117": "CustomerFeedbackSignal",
    "CB-0118": "El caller solo aporta",
    "CB-0119": "inyectar scope",
    "CB-0120": "Una fuente",
    "CB-0121": "observed_at",
}


@lru_cache(maxsize=40)
def scenario(group: str, name: str) -> dict:
    """Execute existing PHP contract harnesses using local synthetic fixtures."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    run = subprocess.run(
        [php, str(ROOT / "tests" / SCRIPTS[group]), name],
        cwd=ROOT, text=True, capture_output=True, timeout=25, check=False,
    )
    if run.returncode:
        raise AssertionError(
            f"UNKNOWN: synthetic {group}/{name} failed: {run.stderr[-400:]}"
        )
    try:
        answer = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid scenario JSON") from exc
    if not isinstance(answer, dict):
        raise AssertionError("UNKNOWN: invalid scenario object")
    return answer


def parts() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in PARTITIONS}


def matrix_rows(fragments: dict[str, str]) -> dict[str, tuple[str, ...]]:
    """Enforce unique IDs, exact partition and six-column markdown rows."""
    found: dict[str, tuple[str, ...]] = {}
    for path, body in fragments.items():
        if path not in PARTITIONS or not isinstance(body, str):
            raise ValueError("UNKNOWN: matrix partition is untrusted")
        for line in body.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if match is None or match.group(1) not in IDS:
                continue
            identifier = match.group(1)
            if identifier in found:
                raise ValueError("UNKNOWN: duplicated row " + identifier)
            cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            if len(cells) != 6 or cells[0] != identifier:
                raise ValueError("UNKNOWN: malformed row " + identifier)
            found[identifier] = cells
    return found


def evidence() -> dict[str, dict]:
    cases = {
        "backup": ("backup", "restore", "restorable", "retention", "pure"),
        "rollout": ("profile", "attestation", "attestation-live",
                    "attestation-real-data", "operational-status"),
        "policy": ("valid", "evidence", "bad-rpo", "bad-rto", "missing-field"),
        "feedback": ("scope", "closed", "fail_closed", "privacy", "pure"),
    }
    return {group: {name: scenario(group, name) for name in names}
            for group, names in cases.items()}


def classify(rows: dict[str, tuple[str, ...]], data: dict) -> dict[str, dict[str, str]]:
    """No missing, ambiguous or altered evidence can become LOCAL_CONTRACT."""
    verdicts = {key: dict.fromkeys(("code", "ci", "live", "production"), UNKNOWN)
                for key in IDS}
    try:
        b, r, p, f = (data[x] for x in ("backup", "rollout", "policy", "feedback"))
        restore = b["restore"]
        operational = r["operational-status"]
        profile = r["profile"]["profile"]
        feed = f["scope"]["good"]
        closed = f["closed"]
        fail = f["fail_closed"]
        checks = {
            "CB-0107": (
                restore["valid"]["status"] == "passed"
                and restore["valid"]["target_environment"] == "restore-drill"
                and restore["valid"]["backup_id"] == b["backup"]["valid"]["backup_id"]
                and restore["valid"]["source_checksum"] ==
                    restore["valid"]["destination_checksum"]
                and restore["valid"]["evidence_ref"].startswith("evidence:")
                and all(restore[k] for k in
                        ("production_rejected", "checksum_rejected", "before_backup_rejected"))
            ),
            "CB-0108": (
                b["restorable"]["verified"]["restorable"] is True
                and b["restorable"]["missing"]["restorable"] is False
                and b["restorable"]["invalid"]["restorable"] is False
            ),
            "CB-0109": (
                b["retention"]["plan"]["authority"] == "plan_only"
                and set(b["retention"]["plan"]["keep"]) >=
                    {"backup-002", "backup-003", "backup-004"}
                and "backup-002" in b["retention"]["plan"]["protected_restorable"]
                and b["retention"]["plan"]["delete"] == ["backup-001"]
                and b["retention"]["ambiguous_rejected"] is True
            ),
            "CB-0110": (
                b["backup"]["url_rejected"] is True
                and b["backup"]["secret_rejected"] is True
                and b["pure"]["hits"] == []
            ),
            "CB-0111": (
                profile["project_ref"] == "controlbot:project/project-controlbot"
                and profile["manifest_ref"] ==
                    "controlbot:recovery-manifest/project-controlbot-v1"
                and r["profile"]["effective_status"] == "configured"
            ),
            "CB-0112": (
                profile["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                and profile["retention"] ==
                    {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12}
                and profile["restore_drill_cadence_hours"] == 168
                and profile["sources"] ==
                    {"database": "required", "media": "not_applicable",
                     "repository": "required", "configuration": "required"}
                and profile["strategy"] ==
                    {"copies_required": 3, "media_types_required": 2,
                     "offsite_required": True, "immutable_required": True,
                     "undetected_restore_failures_target": 0}
                and profile["encryption_required"] is True
            ),
            "CB-0113": (
                operational["profile_status"] == "configured"
                and all(operational[k] == "unknown" for k in
                        ("backup_evidence", "offsite_copy", "immutable_copy", "restore_drill"))
                and operational["dr_status"] == UNKNOWN
            ),
            "CB-0114": (
                operational["restorable"] is False
                and operational["execution"] is False
                and operational["dr_status"] == UNKNOWN
                and p["evidence"]["missing"] == "unknown"
                and p["evidence"]["stale_healthy"] == "degraded"
                and p["evidence"]["incomplete_healthy"] == "degraded"
            ),
            "CB-0115": (
                r["attestation"] == {
                    "phase": "construccion", "nothing_live": True,
                    "no_real_customer_data": True,
                }
                and r["attestation-live"]["blocked"] is True
                and r["attestation-real-data"]["blocked"] is True
            ),
            "CB-0116": (
                operational["execution"] is False
                and b["pure"]["hits"] == []
                and p["valid"]["policy"]["project_id"] == "controlbot"
            ),
            "CB-0117": (
                feed["venture_id"] == "venture-condor"
                and feed["origin"] == "support_pattern"
                and feed["product_ref"].startswith("product:")
                and f["pure"]["methods"] ==
                    ["collection", "fromHealthDimension", "fromSupport"]
            ),
            "CB-0118": (
                f["scope"]["extraRejected"] is True
                and closed["good"]["targets"] == ["discovery", "product_intelligence"]
                and closed["duplicate"] is True
                and closed["unknown"] is True
            ),
            "CB-0119": (
                f["scope"]["cross"] is True
                and f["scope"]["missingProduct"] is True
                and closed["forgedSource"] is True
                and closed["forgedScope"] is True
                and closed["forgedUnknown"] is True
                and all(f["privacy"].values())
            ),
            "CB-0120": (
                fail["unknownSupport"]["freshness"] == "unknown"
                and fail["unknownSupport"]["nature"] == "unknown"
                and fail["unknownSupport"]["evidence_refs"] == []
                and fail["unknownSupport"]["observed_at"] is None
                and fail["unknownHealth"]["nature"] == "unknown"
                and fail["unknownHealth"]["evidence_refs"] == []
                and fail["unknownHealth"]["observed_at"] is None
            ),
            "CB-0121": (
                fail["stale"]["freshness"] == "stale"
                and fail["churn"]["nature"] == "inferred"
                and fail["churn"]["confidence"] == 0.42
                and fail["unknownHealth"]["nature"] == "unknown"
            ),
        }
    except (KeyError, TypeError, ValueError, IndexError, AttributeError):
        return verdicts
    for key in IDS:
        row = rows.get(key)
        if (row is not None and len(row) == 6
                and row[3] == "NO_VERIFICABLE"
                and ROW_START[key].lower() in row[2].replace(chr(96), "").lower()
                and checks[key]):
            verdicts[key]["code"] = LOCAL
    return verdicts


class BrechasControlbot08Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = matrix_rows(parts())
        self.assertEqual(set(IDS), set(rows))
        results = classify(rows, evidence())
        for key in IDS:
            with self.subTest(requirement=key):
                self.assertEqual("NO_VERIFICABLE", rows[key][3])
                self.assertEqual(LOCAL, results[key]["code"])

    def test_falla_cerrado(self) -> None:
        fragments = parts()
        rows = matrix_rows(fragments)
        observations = evidence()
        first = next(x for x in fragments[PARTITIONS[1]].splitlines()
                     if x.startswith("| CB-0107 |"))
        duplicate = dict(fragments)
        duplicate[PARTITIONS[1]] += "\n" + first
        with self.assertRaisesRegex(ValueError, "duplicated"):
            matrix_rows(duplicate)
        for key in IDS:
            with self.subTest(requirement=key):
                missing = dict(rows)
                missing.pop(key)
                self.assertEqual(UNKNOWN, classify(missing, observations)[key]["code"])
                promoted = dict(rows)
                new_row = list(rows[key])
                new_row[3] = "CUBIERTO"
                promoted[key] = tuple(new_row)
                self.assertEqual(UNKNOWN, classify(promoted, observations)[key]["code"])
        altered = copy.deepcopy(observations)
        altered["backup"]["restore"]["valid"]["target_environment"] = "production"
        self.assertEqual(UNKNOWN, classify(rows, altered)["CB-0107"]["code"])
        altered = copy.deepcopy(observations)
        altered["rollout"]["operational-status"]["dr_status"] = "HEALTHY"
        self.assertEqual(UNKNOWN, classify(rows, altered)["CB-0114"]["code"])
        altered = copy.deepcopy(observations)
        altered["feedback"]["fail_closed"]["unknownSupport"]["evidence_refs"] = ["forged"]
        self.assertEqual(UNKNOWN, classify(rows, altered)["CB-0120"]["code"])
        for case in ("bad-rpo", "bad-rto", "missing-field"):
            self.assertTrue(scenario("policy", case)["blocked"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8").lower()
        for name in SCRIPTS.values():
            with self.subTest(harness=name):
                self.assertTrue((ROOT / "tests" / name).is_file())
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("begin " + "private key", own)
        self.assertEqual([], scenario("backup", "pure")["hits"])
        profile = scenario("rollout", "profile")["profile"]
        serialized = json.dumps(profile).lower()
        for field in ("storage_ref", "backup_id", "restore_id",
                      "credential", "password", "private_key"):
            self.assertNotIn(field, serialized)
        self.assertTrue(all(scenario("feedback", "privacy").values()))
        metadata = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", metadata["phase"])

    def test_estados_unknown_explicitos(self) -> None:
        status = scenario("rollout", "operational-status")
        self.assertEqual("configured", status["profile_status"])
        self.assertEqual(UNKNOWN, status["dr_status"])
        self.assertFalse(status["restorable"])
        self.assertFalse(status["execution"])
        self.assertEqual([], scenario("feedback", "fail_closed")["unknownSupport"]["evidence_refs"])
        decisions = classify(matrix_rows(parts()), evidence())
        for key in IDS:
            with self.subTest(requirement=key):
                for scope in ("ci", "live", "production"):
                    self.assertEqual(UNKNOWN, decisions[key][scope])


if __name__ == "__main__":
    unittest.main()
