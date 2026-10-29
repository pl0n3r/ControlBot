#!/usr/bin/env python3
"""#1218: behavioral, offline PHP checks for ControlBot CB-0560..CB-0579.

Only a subset is executable offline. In particular, dry-run adapters do not
certify Hostinger transport, real backups, restores, HTTPS health, or go-live.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
ALL_IDS = {f"CB-{n:04d}" for n in range(560, 577)} | {"CB-0578", "CB-0579"}
# C: statements and non-executable config requirements. B: real operator,
# infrastructure or deployment observations. Neither is a passing behavioral
# assertion, and none of these labels promotes the underlying matrix row.
NON_OFFLINE = {
    "CB-0560": ("C", "Binding description is configuration scope, not a runtime operation"),
    "CB-0561": ("C", "Statement that the binding grants no deployment authorization"),
    "CB-0562": ("C", "Workflow fallback none is a declarative GitHub configuration"),
    "CB-0563": ("B", "Hostinger operator, SSH capability and authority need real evidence"),
    "CB-0564": ("B", "D-059 restore-point provenance and allowed protected variables need real verification"),
    "CB-0565": ("C", "Secrets-vs-input policy is declarative; live secret provenance is out of scope"),
    "CB-0566": ("B", "Adapters exist as synthetic preflight only; operational delivery requires real hosting"),
    "CB-0567": ("B", "Real backup and rollback have no verified functional hosting receipt"),
    "CB-0568": ("B", "Latest healthy release, restore and rollback need external current SHA evidence"),
    "CB-0569": ("B", "Owner-only HTTPS Basic Auth needs live hosting observation"),
    "CB-0570": ("B", "DNS, SSH, production credentials and owner authorization are external gates"),
    "CB-0571": ("B", "Exact deployed version and release_sha need authenticated host evidence"),
    "CB-0572": ("C", "A successful CI or merge cannot itself prove deployment"),
}
OFFLINE = ALL_IDS - set(NON_OFFLINE)
SCENARIOS = {
    "identity": (
        "identity_center_scenarios.php",
        {"commands", "secrets", "revoke", "role-change", "requests",
         "mfa", "deterministic"},
    ),
    "lesson": (
        "incident_lesson_scenarios.php",
        {"valid", "deterministic", "secret", "uncertain", "independent", "incident78"},
    ),
}


@lru_cache(maxsize=20)
def real_php(domain: str, case: str):
    """Only approved scripts that require real project PHP classes."""
    if domain not in SCENARIOS or case not in SCENARIOS[domain][1]:
        raise AssertionError("Unapproved case; no arbitrary script execution")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI absent; no simulated pass is permitted")
    filename = ROOT / "tests" / SCENARIOS[domain][0]
    if not filename.is_file():
        raise AssertionError("Actual PHP fixture absent")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(filename), case],
        cwd=ROOT, text=True, capture_output=True, timeout=25, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("PHP fixture rejected: " + result.stderr[-350:])
    return json.loads(result.stdout)


def synthetic_deploy(**overrides):
    """Call the real offline adapter, never an SSH/Hostinger deploy."""
    payload = {
        "kind": "synthetic", "phase": "construccion", "transport": "none",
        "candidate_sha": "a" * 40, "expected_candidate_sha": "a" * 40,
        "artifact_sha256": "c" * 64,
        "baseline_sha": "b" * 40, "expected_baseline_sha": "b" * 40,
        "baseline_verified": True, "artifact_verified": True,
        "backup_verified": True, "rollback_verified": True,
    }
    payload.update(overrides)
    path = ROOT / "ops" / "factory" / "deploy"
    if not path.is_file():
        raise AssertionError("Real offline adapter missing")
    result = subprocess.run(
        [sys.executable, str(path), "--offline-dry-run"],
        input=json.dumps(payload), text=True, capture_output=True,
        cwd=ROOT, timeout=12, check=False,
    )
    if result.stderr:
        raise AssertionError("Unexpected offline adapter diagnostic")
    return result.returncode, json.loads(result.stdout)


class CoberturaControlbot35Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: assert real identity and lesson domain behavior per offline row."""
        self.assertEqual(19, len(ALL_IDS))
        self.assertEqual(6, len(OFFLINE))
        demonstrated = set()

        # CB-0573: the command parser closes its operation vocabulary.
        cmds = real_php("identity", "commands")
        operations = {row["operation"] for row in cmds}
        self.assertEqual(len(cmds), 11)
        self.assertEqual(operations, {
            "invite", "create", "suspend", "reactivate", "grant_scope",
            "revoke_scope", "change_role", "change_capability",
            "request_reauth", "request_reset", "set_mfa_required",
        })
        self.assertTrue(all(row["idempotency_key"].startswith("idem_")
                            for row in cmds))
        demonstrated.add("CB-0573")

        # CB-0574: DecisionRights gates role/capability mutation.
        role = real_php("identity", "role-change")
        self.assertEqual("owner_decision_required", role["denied"]["status"])
        self.assertEqual("operator", role["denied"]["state"]["grants"][0]["role"])
        self.assertEqual("viewer", role["allowed"]["state"]["grants"][0]["role"])
        self.assertEqual("L1_OPERATOR",
                         role["allowed"]["state"]["grants"][0]["authority_level"])
        self.assertEqual("owner_decision_required", role["capability"]["status"])
        demonstrated.add("CB-0574")

        # CB-0575: reset/reauth only create bounded requests, MFA is metadata.
        req = real_php("identity", "requests")
        self.assertEqual("reset", req["reset"]["state"]["requests"][0]["kind"])
        self.assertEqual("reauth", req["reauth"]["state"]["requests"][0]["kind"])
        mfa = real_php("identity", "mfa")
        self.assertEqual({"required": True, "status": "required"},
                         mfa["state"]["mfa"])
        self.assertTrue(all(real_php("identity", "secrets")["blocked"].values()))
        demonstrated.add("CB-0575")

        # CB-0576: revoke only selected grant, keep identity and append audit.
        revoke = real_php("identity", "revoke")
        self.assertEqual("applied", revoke["status"])
        self.assertEqual("identity-user", revoke["state"]["identity"]["identity_id"])
        self.assertEqual(["grant-other"],
                         [grant["grant_id"] for grant in revoke["state"]["grants"]])
        self.assertEqual(1, len(revoke["state"]["audit"]))
        demonstrated.add("CB-0576")

        # CB-0578: uncertain/contradictory facts never become supported causes.
        uncertain = real_php("lesson", "uncertain")
        self.assertEqual({"incomplete", "unknown", "contradictory"},
                         set(uncertain))
        for condition, entry in uncertain.items():
            with self.subTest(evidence=condition):
                self.assertTrue(entry["owner_action_required"])
                self.assertEqual("pending", entry["publication_state"])
                self.assertNotIn("Billing hard stop mechanism remains unverified.",
                                 entry["root_cause_facts"])
        independent = real_php("lesson", "independent")
        bug = "Coordination caller lacked checks write permission."
        self.assertIn(bug, independent["independent_bugs"])
        self.assertNotIn(bug, independent["root_cause_facts"])
        self.assertNotIn(bug, independent["preventive_rules"])
        demonstrated.add("CB-0578")

        # CB-0579: sensitive summaries are rejected; never auto-publish.
        rejected = real_php("lesson", "secret")
        self.assertEqual({"token": True, "email": True}, rejected)
        lesson = real_php("lesson", "valid")
        self.assertEqual("pending", lesson["publication_state"])
        self.assertRegex(lesson["candidate_fingerprint"], r"^[0-9a-f]{64}$")
        self.assertEqual("incident-lesson-v1:" + lesson["candidate_fingerprint"],
                         lesson["dedupe_marker"])
        demonstrated.add("CB-0579")

        self.assertEqual(OFFLINE, demonstrated)

    def test_falla_cerrado(self) -> None:
        """AC-02: real PHP guards reject ambiguous, missing or weak authority."""
        replay = real_php("identity", "deterministic")
        self.assertTrue(replay["same"])
        self.assertTrue(replay["actor_scope_blocked"])
        self.assertTrue(replay["audit_full_blocked"])
        self.assertEqual("already_applied", replay["replay"]["status"])
        self.assertEqual("owner_decision_required",
                         replay["denied_replay"]["status"])
        self.assertEqual("applied", replay["expired_suspend"]["status"])
        self.assertEqual([], replay["expired_revoke"]["state"]["grants"])
        self.assertEqual(real_php("lesson", "deterministic")["first"],
                         real_php("lesson", "deterministic")["permuted"])
        for evidence in real_php("lesson", "uncertain").values():
            self.assertTrue(evidence["owner_action_required"])

        # Guardrail uses code, not a doc quote; never treats dry-run as live.
        code, safe = synthetic_deploy()
        self.assertEqual(0, code)
        self.assertEqual("SYNTHETIC_ONLY", safe["status"])
        self.assertIs(safe["deploy_executed"], False)
        self.assertIs(safe["production_ready"], False)
        self.assertEqual("UNKNOWN", safe["readiness"])
        for invalid in ({"transport": "hostinger-ssh"},
                        {"backup_verified": False},
                        {"expected_candidate_sha": "d" * 40}):
            code, denied = synthetic_deploy(**invalid)
            self.assertEqual(2, code)
            self.assertEqual("REJECTED", denied["status"])
            self.assertIs(denied["deploy_executed"], False)
            self.assertEqual("UNKNOWN", denied["readiness"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic local fixtures; no real identities, tokens or APIs."""
        self.assertTrue(all(real_php("identity", "secrets")["blocked"].values()))
        self.assertEqual({"token": True, "email": True},
                         real_php("lesson", "secret"))
        for raw in (real_php("identity", "requests")["serialized"],
                    real_php("identity", "deterministic")["serialized"]):
            self.assertFalse(any(word in raw.lower() for word in (
                "password", "cookie", "otp", "secret", "credential",
            )))
        canonical = real_php("lesson", "valid")
        self.assertEqual("pending", canonical["publication_state"])
        self.assertNotIn("access_token", json.dumps(canonical))

    def test_ci_gate_contract(self) -> None:
        """AC-04 smoke: executable tests exist; only CI proves exact-HEAD green."""
        self.assertEqual(19, len(ALL_IDS))
        self.assertEqual(19, len(NON_OFFLINE) + len(OFFLINE))
        self.assertEqual({"B", "C"}, {kind for kind, _ in NON_OFFLINE.values()})
        self.assertEqual(11, len(real_php("identity", "commands")))
        self.assertEqual("pending", real_php("lesson", "valid")["publication_state"])


if __name__ == "__main__":
    unittest.main()
