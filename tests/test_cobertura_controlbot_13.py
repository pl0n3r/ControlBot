#!/usr/bin/env python3
"""ControlBot #1196: real, offline behavior for recovery and capacity contracts.

The source matrix is intentionally not read: promotion is a separate review.
Synthetic PHP projections neither perform backups nor approve production.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "policy": ("disaster_recovery_policy_scenarios.php", frozenset({
        "valid", "evidence", "permuted", "bad-rpo", "bad-rto",
        "empty-retention", "missing-field", "bad-secret-strategy",
        "drive-primary", "drive-server", "icloud-primary", "icloud-server",
        "duplicate-destination", "sensitive-ref",
    })),
    "ui": ("recovery_center_ui_scenarios.php", frozenset({
        "valid", "missing-health", "blocked-health", "stale",
        "cross-health", "cross-evidence", "unsafe-authority",
        "execute-true", "sensitive-health", "caller-action",
    })),
    "web": ("recovery_center_web_scenarios.php", frozenset({
        "owner", "guards",
    })),
    "work": ("recovery_work_origin_scenarios.php", frozenset({
        "valid", "explicit", "unknown", "order-a", "order-b",
        "healthy", "class-absent", "unknown-class", "expanded-health",
        "incoherent-drill", "sensitive-health",
    })),
    "capacity": ("dynamic_capacity_e2e_scenarios.php", frozenset({"lifecycle"})),
    "signal": ("runtime_capacity_signal_scenarios.php", frozenset({
        "states", "secrets", "clock",
    })),
}

IDS = {f"CB-{n:04d}" for n in range(184, 204)}
NON_OFFLINE = {
    "CB-0188": ("C", "No network, storage or backup execution is a documented boundary; not proof of future integrations."),
    "CB-0192": ("B", "Actual owner/provider approval, commercial terms, credentials and live backup writes need external authority."),
    "CB-0201": ("C", "Global degraded status must not be interpreted as all other accounts absent; system-wide proof needs broader orchestration."),
}
OFFLINE = IDS - set(NON_OFFLINE)


def run_php(group: str, case: str) -> object:
    if group not in SCRIPTS or case not in SCRIPTS[group][1]:
        raise ValueError("UNKNOWN: scenario not allowlisted")
    php = shutil.which("php")
    script = ROOT / "tests" / SCRIPTS[group][0]
    if php is None or not script.is_file():
        raise AssertionError("UNKNOWN: PHP runtime or genuine scenario missing")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(script), case],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP scenario failed: " + result.stderr[-400:])
    return json.loads(result.stdout)


@lru_cache(maxsize=40)
def observed(group: str, case: str) -> object:
    return run_php(group, case)


class CoberturaControlbot13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each offline clause asserts actual PHP return values."""
        policy = observed("policy", "valid")
        evidence = observed("policy", "evidence")
        ui = observed("ui", "valid")
        missing = observed("ui", "missing-health")
        stale = observed("ui", "stale")
        web = observed("web", "owner")
        work = observed("work", "valid")
        explicit = observed("work", "explicit")
        unknown_work = observed("work", "unknown")
        lifecycle = observed("capacity", "lifecycle")
        signals = observed("signal", "states")
        checks = {
            "CB-0184": (
                policy["policy"]["version"] == 1
                and policy["policy"]["project_id"] == "controlbot"
                and len(policy["fingerprint"]) == 64
            ),
            "CB-0185": (
                (policy["policy"]["rpo_seconds"], policy["policy"]["rto_seconds"]) == (900, 3600)
                and policy["policy"]["retention"] == {
                    "recent": 24, "daily": 7, "weekly": 8, "monthly": 12,
                }
                and policy["policy"]["strategies"] == {
                    "database": "consistent_backup", "media": "versioned_backup",
                    "code": "repository_mirror", "secrets": "vault_reference_only",
                }
                and policy["policy"]["capabilities"]["restore_drill_required"] is True
            ),
            "CB-0186": evidence == {
                "missing": "unknown", "stale_healthy": "degraded",
                "incomplete_healthy": "degraded", "unknown": "unknown",
                "blocked": "blocked", "healthy": "healthy",
            },
            "CB-0187": (
                {"provider": "google_drive", "role": "offsite_encrypted_copy"}
                in policy["policy"]["destinations"]
                and observed("policy", "drive-primary")["blocked"]
                and observed("policy", "icloud-server")["blocked"]
            ),
            "CB-0189": (
                ui["dr_status"] == "DEGRADED"
                and observed("ui", "blocked-health")["dr_status"] == "BLOCKED"
                and missing["dr_status"] == "UNKNOWN"
                and ui["execution"] is False and ui["actions"] == []
            ),
            "CB-0190": (
                ui["signals"]["database_backup"]["state"] == "verified"
                and ui["signals"]["offsite_copy"]["state"] == "verified"
                and ui["signals"]["immutable_copy"]["state"] == "verified"
                and ui["signals"]["media_versioning"]["state"] == "not_applicable"
                and (ui["restore_drill"]["rpo_seconds"],
                     ui["restore_drill"]["rto_seconds"]) == (600, 4000)
            ),
            "CB-0191": (
                web["status"] == 200 and "UNKNOWN" in web["body"]
                and "RPO objetivo" in web["body"] and "RTO objetivo" in web["body"]
                and "HEALTHY" not in web["body"]
                and "<form" not in web["body"].lower()
            ),
            "CB-0193": (
                stale["signals"]["offsite_copy"]["state"] == "unknown"
                and stale["restore_drill"]["status"] == "UNKNOWN"
                and missing["reasons"] == ["RECOVERY_HEALTH_MISSING"]
            ),
            "CB-0194": (
                missing["health"]["source"] == "factory:recovery-health-v1"
                and observed("ui", "blocked-health")["dr_status"] == "BLOCKED"
                and unknown_work["project_id"] == "controlbot"
            ),
            "CB-0195": (
                work["origin_mode"] == "automatic"
                and work["origin_system"] == "aegis"
                and work["producer_ref"] == "controlbot:aegis/recovery"
                and observed("work", "class-absent")["blocked"] is True
            ),
            "CB-0196": (
                explicit["authority_level"] == "owner_reviewed"
                and explicit["priority_class"] == "critical"
                and explicit["work_type"] == "operations"
                and explicit["requested_capabilities"] == ["incident.repair"]
                and explicit["required_roles"] == ["sre"]
                and explicit["policy_ref"] == "factory:recovery-critical"
            ),
            "CB-0197": (
                observed("work", "order-a")["idempotency_key"]
                == observed("work", "order-b")["idempotency_key"]
                and observed("work", "order-a")["observed_at"]
                == observed("work", "order-b")["observed_at"]
            ),
            "CB-0198": (
                observed("work", "healthy")["blocked"] is True
                and observed("work", "unknown-class")["blocked"] is True
                and unknown_work["origin_mode"] == "automatic"
                and not any(k in unknown_work for k in ("ready", "approved", "authorized"))
            ),
            "CB-0199": all(
                lifecycle[k]["idle_capacity"] == 0
                and lifecycle[k]["sessions"][0]["assignment_id"] == "assignment-217"
                and lifecycle["dispatch"][k]["dispatchable_capacity"] == 0
                for k in ("rate_limited", "stale")
            ),
            "CB-0200": (
                lifecycle["handoff"]["from_session_id"] == "session-a"
                and lifecycle["handoff"]["to_session_id"] == "session-b"
                and lifecycle["readiness"]["previous"]["ready"] is True
                and lifecycle["readiness"]["current"]["ready"] is True
            ),
            "CB-0202": (
                lifecycle["readiness"]["stale_owner"]["ready"] is False
                and lifecycle["readiness"]["duplicate_owners_rejected"] is True
                and lifecycle["readiness"]["current"]["generation"] == 2
                and lifecycle["readiness"]["current"]["reservation_owner"] == "session-b"
                and lifecycle["old_recovery_guard"]["allowed"] is False
                and lifecycle["current_recovery_guard"]["allowed"] is True
            ),
            "CB-0203": (
                lifecycle == run_php("capacity", "lifecycle")
                and signals["stale"]["observed_at"] == 1000
                and lifecycle["signals"]["joined"]["observed_at"] == 1000
            ),
        }
        self.assertEqual(20, len(IDS))
        self.assertEqual(17, len(OFFLINE))
        self.assertEqual(OFFLINE, set(checks))
        self.assertEqual(IDS, set(checks) | set(NON_OFFLINE))
        for clause, status in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(status, clause + ": genuine runtime contract failed")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid, stale, forged authority and wrong owners are denied."""
        for case in (
            "bad-rpo", "bad-rto", "empty-retention", "missing-field",
            "bad-secret-strategy", "drive-primary", "drive-server",
            "icloud-primary", "icloud-server", "duplicate-destination",
            "sensitive-ref",
        ):
            with self.subTest(policy=case):
                self.assertIs(observed("policy", case)["blocked"], True)
        for case in (
            "cross-health", "cross-evidence", "unsafe-authority",
            "execute-true", "sensitive-health", "caller-action",
        ):
            with self.subTest(ui=case):
                self.assertIs(observed("ui", case), True)
        for case in (
            "healthy", "class-absent", "unknown-class", "expanded-health",
            "incoherent-drill", "sensitive-health",
        ):
            with self.subTest(work=case):
                self.assertIs(observed("work", case)["blocked"], True)
        guards = observed("web", "guards")
        self.assertEqual(403, guards["anonymous"]["status"])
        self.assertEqual(405, guards["post"]["status"])
        self.assertNotEqual(200, guards["forged_user"]["status"])
        for response in guards.values():
            self.assertNotIn("RPO objetivo", response["body"])
        self.assertTrue(all(observed("signal", "secrets").values()))
        clock = observed("signal", "clock")
        for case in ("future_heartbeat", "bad_generation", "bad_attempt",
                     "over_occupied", "assigned_without_ref", "idle_with_ref"):
            with self.subTest(signal=case):
                self.assertIs(clock[case], True)
        # The genuine dynamic-capacity E2E executes SchedulerCore::readiness
        # and demonstrates generation/owner fencing without depending on the
        # unrelated legacy scheduler scenario's harmless PHP use-warning.
        lifecycle = observed("capacity", "lifecycle")
        self.assertIs(lifecycle["readiness"]["duplicate_owners_rejected"], True)
        self.assertIs(lifecycle["readiness"]["stale_owner"]["ready"], False)
        self.assertIn("stale_owner", lifecycle["readiness"]["stale_owner"]["reasons"])
        self.assertIs(lifecycle["stale_current_guard"]["allowed"], False)

    def test_sin_datos_reales(self) -> None:
        """AC-03: explicit synthetic fixtures, no network clients or secrets."""
        import ast
        source = Path(__file__).read_text(encoding="utf-8")
        parsed = ast.parse(source)
        imports = set()
        for node in ast.walk(parsed):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "socket", "urllib", "httpx"})
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        for group, case in (
            ("policy", "valid"), ("work", "valid"),
            ("capacity", "lifecycle"), ("signal", "states"),
        ):
            with self.subTest(group=group):
                output = json.dumps(observed(group, case)).lower()
                for secret in ("password:", "cookie:", "token:", "authorization:",
                               "-----begin private key-----", "chain-of-thought"):
                    self.assertNotIn(secret, output)
                self.assertNotIn("@", output)
        self.assertFalse(observed("ui", "valid")["execution"])
        self.assertEqual([], observed("ui", "valid")["actions"])
        self.assertEqual(3, len(NON_OFFLINE))
        self.assertTrue(all(kind in {"B", "C"} and reason
                            for kind, reason in NON_OFFLINE.values()))

    def test_ci_contract(self) -> None:
        """AC-04: check CI wiring locally; authoritative exact-HEAD CI is external."""
        workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text(
            encoding="utf-8",
        )
        self.assertIn("name: CI ControlBot", workflow)
        self.assertIn("name: Pruebas de contrato e integración", workflow)
        self.assertIn("run: python3 scripts/generate_coverage.py", workflow)
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertEqual(IDS, OFFLINE | set(NON_OFFLINE))


if __name__ == "__main__":
    unittest.main()
