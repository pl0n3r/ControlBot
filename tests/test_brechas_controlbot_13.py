#!/usr/bin/env python3
"""ControlBot #1020: CB-0182..CB-0196, offline recovery evidence only.

These checks exercise existing synthetic PHP contracts, never a live backup,
Recovery Health attestation, production readiness, or a provider account.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(182, 197))
MATRICES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-01.md",
)
SCRIPTS = {
    "evidence": "recovery_evidence_scenarios.php",
    "policy": "disaster_recovery_policy_scenarios.php",
    "ui": "recovery_center_ui_scenarios.php",
    "web": "recovery_center_web_scenarios.php",
    "work": "recovery_work_origin_scenarios.php",
}
CASES = {
    "evidence": frozenset((
        "valid", "stale-checksum", "stale-source", "unknown-checksum",
        "duplicate-source", "required-not-applicable", "top-level-digest",
        "payload-field", "provider-url", "secret-ref",
        "unknown-with-provenance", "cross-project", "cross-project-profile-ref",
        "producer-project-mismatch",
    )),
    "policy": frozenset((
        "valid", "evidence", "bad-rpo", "bad-rto", "empty-retention",
        "missing-field", "extra-field", "bad-secret-strategy", "drive-primary",
        "drive-server", "icloud-primary", "icloud-server",
        "duplicate-destination", "duplicate-provenance", "sensitive-ref",
        "bad-policy-project", "permuted",
    )),
    "ui": frozenset((
        "valid", "missing-health", "blocked-health", "stale",
        "cross-health", "cross-evidence", "unsafe-authority", "execute-true",
        "sensitive-health", "caller-action",
    )),
    "web": frozenset(("owner", "guards", "invalid-profile")),
    "work": frozenset((
        "valid", "explicit", "unknown", "healthy", "class-absent",
        "unknown-class", "expanded-health", "incoherent-drill",
        "sensitive-health", "optionals", "order-a", "order-b",
    )),
}
UNKNOWN = "UNKNOWN"


@lru_cache(maxsize=60)
def scenario(domain: str, name: str) -> dict:
    """Only a known synthetic scenario is allowed; never interpolate commands."""
    if domain not in SCRIPTS or name not in CASES[domain]:
        raise ValueError("UNKNOWN: unapproved fixture")
    command = ["php", str(ROOT / "tests" / SCRIPTS[domain]), name]
    process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True,
                             timeout=25, check=False)
    if process.returncode != 0:
        raise AssertionError(f"UNKNOWN: offline fixture {domain}/{name} failed")
    try:
        parsed = json.loads(process.stdout)
    except (TypeError, ValueError):
        raise AssertionError("UNKNOWN: malformed synthetic fixture") from None
    if not isinstance(parsed, (dict, bool)):
        raise AssertionError("UNKNOWN: unexpected fixture type")
    return parsed


def matrix_rows() -> dict[str, list[list[str]]]:
    rows = {name: [] for name in IDS}
    for relative in MATRICES:
        content = (ROOT / relative).read_text(encoding="utf-8")
        for raw in content.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", raw)
            if match and match.group(1) in rows:
                rows[match.group(1)].append([col.strip() for col in raw.strip("|").split("|")])
    return rows


def no_live_assertion(value: object) -> str:
    """An observed synthetic value never establishes an operational status."""
    return "OFFLINE_ONLY" if value is True else UNKNOWN


class BrechasControlbot13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exactly one executable assertion for each of 15 distinct IDs."""
        evidence = scenario("evidence", "valid")
        policy = scenario("policy", "valid")["policy"]
        status = scenario("policy", "evidence")
        ui = scenario("ui", "valid")
        missing = scenario("ui", "missing-health")
        stale = scenario("ui", "stale")
        web = scenario("web", "owner")
        guards = scenario("web", "guards")
        work = scenario("work", "valid")
        explicit = scenario("work", "explicit")
        source = (ROOT / "src/DisasterRecoveryPolicy.php").read_text(encoding="utf-8")
        verdicts = {
            "CB-0182": (
                [row["source"] for row in evidence["sources"]] ==
                ["database", "media", "repository", "configuration"]
                and set(evidence["controls"]) ==
                {"checksum", "encryption", "offsite", "immutability"}
                and scenario("evidence", "top-level-digest")["blocked"]
                and scenario("evidence", "secret-ref")["blocked"]
            ),
            "CB-0183": (
                set(evidence) == {"version", "project_ref", "profile_ref", "controls", "sources"}
                and not any(k in json.dumps(evidence).lower() for k in
                            ("work_item", "authority", "recovery_health", "rpo_seconds"))
            ),
            "CB-0184": (
                policy["version"] == 1 and policy["project_id"] == "controlbot"
                and "function normalize" in source and "function evidenceStatus" in source
                and not any(k in source.lower() for k in ("curl_", "shell_exec", "file_put_contents"))
            ),
            "CB-0185": (
                policy["rpo_seconds"] == 900 and policy["rto_seconds"] == 3600
                and set(policy["retention"]) == {"recent", "daily", "weekly", "monthly"}
                and policy["strategies"] == {"database": "consistent_backup",
                    "media": "versioned_backup", "code": "repository_mirror",
                    "secrets": "vault_reference_only"}
                and set(policy["capabilities"]) == {"offsite", "versioned_or_immutable",
                    "checksum_required", "freshness_required", "restore_drill_required"}
            ),
            "CB-0186": (
                status == {"missing": "unknown", "stale_healthy": "degraded",
                    "incomplete_healthy": "degraded", "unknown": "unknown",
                    "blocked": "blocked", "healthy": "healthy"}
            ),
            "CB-0187": (
                {"provider": "google_drive", "role": "offsite_encrypted_copy"}
                in policy["destinations"]
                and all(scenario("policy", case)["blocked"] for case in
                    ("drive-primary", "drive-server", "icloud-primary", "icloud-server"))
            ),
            "CB-0188": (
                not any(x in source.lower() for x in
                    ("curl_", "mysqli", "pdo(", "file_put_contents", "shell_exec",
                     "proc_open", "passthru(", "traffic_switch", "schedule("))
                and set(policy) == {"version", "project_id", "rpo_seconds", "rto_seconds",
                    "retention", "strategies", "capabilities", "destinations",
                    "policy_ref", "provenance_refs"}
            ),
            "CB-0189": (
                ui["dr_status"] == "DEGRADED"
                and scenario("ui", "blocked-health")["dr_status"] == "BLOCKED"
                and missing["dr_status"] == UNKNOWN and ui["execution"] is False
                and ui["actions"] == [] and ui["health"]["source"] == "factory:recovery-health-v1"
            ),
            "CB-0190": (
                ui["signals"]["database_backup"]["state"] == "verified"
                and ui["signals"]["offsite_copy"]["state"] == "verified"
                and ui["signals"]["immutable_copy"]["state"] == "verified"
                and stale["signals"]["offsite_copy"]["state"] == "unknown"
                and stale["restore_drill"]["status"] == UNKNOWN
                and missing["reasons"] == ["RECOVERY_HEALTH_MISSING"]
            ),
            "CB-0191": (
                web["status"] == 200 and web["headers"]["Cache-Control"] == "no-store"
                and "RPO objetivo" in web["body"] and "15 min" in web["body"]
                and "RTO objetivo" in web["body"] and "60 min" in web["body"]
                and UNKNOWN in web["body"] and "HEALTHY" not in web["body"]
            ),
            "CB-0192": (
                guards["anonymous"]["status"] == 403 and guards["post"]["status"] == 405
                and guards["forged_user"]["status"] != 200
                and "<form" not in web["body"].lower()
                and "<script" not in web["body"].lower()
                and ui["execution"] is False and ui["actions"] == []
            ),
            "CB-0193": (
                missing["dr_status"] == UNKNOWN
                and missing["reasons"] == ["RECOVERY_HEALTH_MISSING"]
                and ui["profile"]["rpo_target_minutes"] == 15
                and ui["profile"]["rto_target_minutes"] == 60
                and ui["restore_drill"]["rpo_seconds"] == 600
                and ui["restore_drill"]["rto_seconds"] == 4000
            ),
            "CB-0194": (
                work["origin_system"] == "aegis"
                and work["producer_ref"] == "controlbot:aegis/recovery"
                and work["origin_mode"] == "automatic"
                and scenario("work", "unknown")["project_id"] == "controlbot"
            ),
            "CB-0195": (
                scenario("work", "class-absent")["blocked"]
                and scenario("work", "unknown-class")["blocked"]
                and work["idempotency_key"].startswith("recovery:rto_breached:")
            ),
            "CB-0196": (
                explicit["authority_level"] == "owner_reviewed"
                and explicit["priority_class"] == "critical"
                and explicit["work_type"] == "operations"
                and explicit["requested_capabilities"] == ["incident.repair"]
                and explicit["required_roles"] == ["sre"]
                and explicit["policy_ref"] == "factory:recovery-critical"
                and scenario("work", "order-a")["idempotency_key"]
                == scenario("work", "order-b")["idempotency_key"]
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        for clause, result in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(result, f"{clause}: offline UNKNOWN / predicate failed")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid provenance, authority, destination or receipt never succeeds."""
        rejected = {
            "evidence": ("duplicate-source", "required-not-applicable", "top-level-digest",
                         "payload-field", "provider-url", "secret-ref",
                         "unknown-with-provenance", "cross-project",
                         "cross-project-profile-ref", "producer-project-mismatch"),
            "policy": ("bad-rpo", "bad-rto", "empty-retention", "missing-field",
                       "extra-field", "bad-secret-strategy", "drive-primary",
                       "drive-server", "icloud-primary", "icloud-server",
                       "duplicate-destination", "duplicate-provenance", "sensitive-ref",
                       "bad-policy-project"),
            "ui": ("cross-health", "cross-evidence", "unsafe-authority",
                   "execute-true", "sensitive-health", "caller-action"),
            "work": ("healthy", "class-absent", "unknown-class", "expanded-health",
                     "incoherent-drill", "sensitive-health"),
        }
        for domain, names in rejected.items():
            for name in names:
                with self.subTest(domain=domain, case=name):
                    result = scenario(domain, name)
                    self.assertTrue(result["blocked"] if isinstance(result, dict)
                                    else result, f"{domain}/{name} not fail-closed")
        self.assertEqual(UNKNOWN, scenario("ui", "missing-health")["dr_status"])
        self.assertEqual("unknown", scenario("policy", "evidence")["missing"])
        self.assertEqual("unknown", scenario("evidence", "stale-source")["sources"][2]["state"])
        self.assertEqual(UNKNOWN, no_live_assertion(None))
        with self.assertRaises(ValueError):
            scenario("web", "unauthorized")

    def test_sin_datos_reales(self) -> None:
        """AC-03: local fixtures only; no credentials, provider calls or PII echo."""
        for filename in ("src/DisasterRecoveryPolicy.php", "src/RecoveryEvidence.php",
                         "src/RecoveryCenterUi.php", "src/RecoveryWorkOrigin.php"):
            with self.subTest(source=filename):
                src = (ROOT / filename).read_text(encoding="utf-8").lower()
                for forbidden in ("curl_", "file_put_contents", "shell_exec", "proc_open",
                                  "new pdo", "mysqli", "workflow_dispatch", "create_issue"):
                    self.assertNotIn(forbidden, src)
        examples = {"top-level-digest": "sha256:0123456789abcdef",
                    "payload-field": "opaque-backup-payload",
                    "provider-url": "bucket.example.invalid",
                    "secret-ref": "token-secret-value"}
        for case, sensitive in examples.items():
            result = scenario("evidence", case)
            self.assertTrue(result["blocked"])
            self.assertNotIn(sensitive, json.dumps(result))
        web = scenario("web", "owner")
        self.assertNotIn("gho_offline_recovery_fixture", web["body"])
        self.assertNotIn("<script", web["body"].lower())
        self.assertNotIn("<form", web["body"].lower())
        self.assertEqual(set(SCRIPTS), set(CASES))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: every matrix row remains NO_VERIFICABLE and live UNKNOWN."""
        rows = matrix_rows()
        self.assertEqual(set(rows), set(IDS))
        for clause, matches in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(1, len(matches), f"{clause} missing or duplicated")
                self.assertTrue(credible_offline_coverage_row(matches[0]), clause)
                self.assertTrue(matches[0][3] == "CUBIERTO" or "offline=unknown" in matches[0][4].lower())
                self.assertIn("live=unknown", matches[0][4].lower())
                self.assertIn("operando=unknown", matches[0][4].lower())
        self.assertEqual(UNKNOWN, scenario("ui", "missing-health")["dr_status"])
        self.assertEqual(["RECOVERY_HEALTH_MISSING"],
                         scenario("ui", "missing-health")["reasons"])
        self.assertEqual("unknown", scenario("policy", "evidence")["missing"])
        self.assertEqual(UNKNOWN, no_live_assertion("healthy"))
        self.assertEqual(UNKNOWN, no_live_assertion({"state": "HEALTHY"}))
        self.assertEqual(UNKNOWN, no_live_assertion(False))


if __name__ == "__main__":
    unittest.main()
