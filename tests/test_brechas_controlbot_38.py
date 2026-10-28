"""ControlBot #1045: 15 brechas offline. Ninguna acredita Hostinger ni producción."""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import runpy
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(564, 577)) + ("CB-0578", "CB-0579")
UNKNOWN = "UNKNOWN"
HELPERS = {
    "backup": "test_factory_backup_preflight.py",
    "deploy": "test_factory_deploy_preflight.py",
    "rollback": "test_factory_rollback_preflight.py",
    "health": "test_brechas_controlbot_34.py",
}
SCENARIOS = {
    "identity": ("identity_center_scenarios.php", {
        "commands", "secrets", "revoke", "role-change", "requests",
        "mfa", "deterministic",
    }),
    "lesson": ("incident_lesson_scenarios.php", {
        "valid", "deterministic", "secret", "uncertain", "independent", "incident78",
    }),
}


@lru_cache(maxsize=None)
def helper(name):
    if name not in HELPERS:
        raise ValueError("UNKNOWN: helper no autorizado")
    return runpy.run_path(str(ROOT / "tests" / HELPERS[name]))


@lru_cache(maxsize=None)
def scenario(domain, case):
    if domain not in SCENARIOS or case not in SCENARIOS[domain][1]:
        raise ValueError("UNKNOWN: escenario no autorizado")
    process = subprocess.run(
        ["php", str(ROOT / "tests" / SCENARIOS[domain][0]), case],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=20,
    )
    if process.returncode or process.stderr.strip():
        raise AssertionError("escenario PHP sin evidencia offline")
    return json.loads(process.stdout)


def preflight(name, **changes):
    if name not in ("backup", "deploy", "rollback"):
        raise ValueError("UNKNOWN: adapter no autorizado")
    mod = helper(name)
    sample = mod["fixture"] if name == "backup" else mod["sample"]
    invoke = mod["execute"] if name == "backup" else mod["run"]
    process, report = invoke(sample(**changes))
    return process.returncode, report


def matrix_state(raw, ident):
    if ident not in IDS:
        return UNKNOWN
    rows = []
    for line in raw.splitlines():
        if not line.lstrip().startswith("|"):
            continue
        fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if fields and fields[0] == ident:
            rows.append(fields)
    if len(rows) != 1 or len(rows[0]) != 6:
        return UNKNOWN
    return "NO_VERIFICABLE" if rows[0][3] == "NO_VERIFICABLE" else UNKNOWN


def operational_status(_=None):
    return UNKNOWN


class BrechasControlbot38Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        def read(path):
            return (ROOT / path).read_text(encoding="utf-8")
        cls.workflow = read(".github/workflows/deploy.yml")
        cls.binding = read("docs/hostinger-ssh-deploy-binding.md")
        cls.backup_doc = read("docs/hostinger-d059-backup.md")
        cls.health_doc = read("docs/health-identity-endpoint.md")
        cls.identity_doc = read("docs/identity-center.md")
        cls.lesson_doc = read("docs/incident-lesson-v1.md")
        cls.matrix = read("docs/matriz-cobertura.md")

    def test_contrato_principal(self):
        """AC-01: 15/15 aserciones concretas de comportamiento y límites."""
        workflow = self.workflow
        bc, backup = preflight("backup")
        dc, deploy = preflight("deploy")
        rc, rollback = preflight("rollback")
        with tempfile.TemporaryDirectory(prefix="cb1045-health-") as temp:
            hp = helper("health")
            server, cfg, receipt = hp["health_fixture"](Path(temp))
            health = hp["health_response"](server, cfg)
            no_tls = hp["health_response"](
                {**server, "HTTPS": "off",
                 "HTTP_X_FORWARDED_PROTO": "https"}, cfg,
            )
            not_owner = hp["health_response"](
                {**server, "REMOTE_USER": "different"}, cfg,
            )
            not_basic = hp["health_response"](
                {**server, "AUTH_TYPE": "Digest"}, cfg,
            )
            receipt.write_text(hp["manifest"]("99.99.99"), encoding="utf-8")
            wrong_release = hp["health_response"](server, cfg)
            observed = json.loads(health["body"])
        version = json.loads(
            (ROOT / "config/version.json").read_text(encoding="utf-8")
        )["version"]
        cmds = scenario("identity", "commands")
        role = scenario("identity", "role-change")
        secrets = scenario("identity", "secrets")["blocked"]
        requests = scenario("identity", "requests")
        mfa = scenario("identity", "mfa")
        revoked = scenario("identity", "revoke")
        uncertain = scenario("lesson", "uncertain")
        incident_secrets = scenario("lesson", "secret")
        lesson = scenario("lesson", "valid")

        checks = {
            "CB-0564": (
                "vars.DEPLOY_ENABLED == 'true'" in workflow
                and all(k in workflow for k in (
                    "HOSTINGER_SSH_HOST", "HOSTINGER_SSH_USER",
                    "HOSTINGER_RELEASE_ROOT",
                ))
                and "restore-point **D-059**" in self.binding
            ),
            "CB-0565": (
                "HOSTINGER_KNOWN_HOSTS: " +
                "$" + "{{ secrets.HOSTINGER_KNOWN_HOSTS }}" in workflow
                and "DEPLOY_SSH_KEY: " + "$" + "{{ secrets.DEPLOY_SSH_KEY }}"
                    in workflow
                and "vars.HOSTINGER_KNOWN_HOSTS" not in workflow
                and "host-key verification" in self.binding
            ),
            "CB-0566": (
                all((ROOT / "ops/factory" / name).is_file()
                    for name in ("build", "backup", "deploy", "rollback"))
                and (bc, dc, rc) == (0, 0, 0)
                and all(r["status"] == "SYNTHETIC_ONLY"
                        for r in (backup, deploy, rollback))
            ),
            "CB-0567": (
                backup["backup_verified"] is False
                and backup["live_authorized"] is False
                and deploy["deploy_executed"] is False
                and rollback["rollback_executed"] is False
                and all(r["readiness"] == UNKNOWN
                        for r in (backup, deploy, rollback))
            ),
            "CB-0568": (
                preflight("rollback", expected_healthy_sha="c" * 40)[1][
                    "reason"] == "healthy_sha_mismatch"
                and preflight("backup", baseline_verified=False)[1][
                    "reason"] == "baseline_unverified"
                and all(s in self.backup_doc for s in (
                    "public_html", "D-059 satisfecho", "referencia opaca",
                ))
            ),
            "CB-0569": (
                health["status"] == 200
                and all(p["status"] == 403 for p in (
                    no_tls, not_owner, not_basic,
                ))
                and "owner-only" in self.health_doc
                and health["headers"]["Cache-Control"].startswith("no-store")
            ),
            "CB-0570": (
                "on:\n  workflow_dispatch:" in workflow
                and "\n  push:" not in workflow
                and preflight("deploy", transport="hostinger-ssh")[1][
                    "reason"] == "invalid_synthetic_fixture"
                and "autorización de despliegue/live" in self.binding
            ),
            "CB-0571": (
                observed == {
                    "status": "ok", "version": version,
                    "release_sha": "a" * 40,
                }
                and wrong_release["status"] == 503
                and "actually deployed artifact" in self.health_doc
            ),
            "CB-0572": (
                "CI verde o merge no prueban publicación" in self.binding
                and "EXPECTED_SHA: " + "$" + "{{ github.sha }}" in workflow
                and deploy["production_ready"] is False
                and operational_status(health) == UNKNOWN
            ),
            "CB-0573": (
                len(cmds) == 11
                and len({c["operation"] for c in cmds}) == 11
                and {c["operation"] for c in cmds} == {
                    "invite", "create", "suspend", "reactivate", "grant_scope",
                    "revoke_scope", "change_role", "change_capability",
                    "request_reauth", "request_reset", "set_mfa_required",
                }
                and all(c["idempotency_key"].startswith("idem_") for c in cmds)
            ),
            "CB-0574": (
                role["denied"]["status"] == "owner_decision_required"
                and role["denied"]["state"]["grants"][0]["role"] == "operator"
                and role["allowed"]["state"]["grants"][0]["role"] == "viewer"
                and role["allowed"]["state"]["grants"][0][
                    "authority_level"] == "L1_OPERATOR"
                and role["capability"]["status"] == "owner_decision_required"
            ),
            "CB-0575": (
                bool(secrets) and all(secrets.values())
                and requests["reset"]["state"]["requests"][0]["kind"] == "reset"
                and requests["reauth"]["state"]["requests"][0]["kind"] == "reauth"
                and mfa["state"]["mfa"] == {
                    "required": True, "status": "required",
                }
                and all(t not in requests["serialized"].lower()
                        for t in ("password", "cookie", "otp", "secret"))
            ),
            "CB-0576": (
                revoked["status"] == "applied"
                and revoked["state"]["identity"]["identity_id"]
                    == "identity-user"
                and [g["grant_id"] for g in revoked["state"]["grants"]]
                    == ["grant-other"]
                and len(revoked["state"]["audit"]) == 1
            ),
            "CB-0578": (
                set(uncertain) == {"unknown", "incomplete", "contradictory"}
                and all(x["owner_action_required"] is True
                        for x in uncertain.values())
                and all("Billing hard stop mechanism remains unverified."
                        not in x["root_cause_facts"]
                        for x in uncertain.values())
            ),
            "CB-0579": (
                incident_secrets == {"token": True, "email": True}
                and lesson["publication_state"] == "pending"
                and "texto acotado" in self.lesson_doc
                and "no contienen señales de secretos" in self.lesson_doc
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        self.assertEqual(len(checks), 15)
        for ident in IDS:
            with self.subTest(clause=ident):
                self.assertTrue(checks[ident], "sin contraste local: " + ident)

    def test_falla_cerrado(self):
        """AC-02: evidencia ausente, stale o contradictoria nunca da éxito."""
        negative = (
            ("backup", {"baseline_verified": False}, "baseline_unverified"),
            ("backup", {"artifact_verified": False}, "artifact_unverified"),
            ("backup", {"rollback_verified": False}, "rollback_unverified"),
            ("deploy", {"backup_verified": False}, "backup_unverified"),
            ("deploy", {"expected_candidate_sha": "d" * 40},
             "candidate_sha_mismatch"),
            ("deploy", {"phase": "live"}, "invalid_synthetic_fixture"),
            ("rollback", {"baseline_verified": False}, "baseline_unverified"),
            ("rollback", {"expected_healthy_sha": "d" * 40},
             "healthy_sha_mismatch"),
        )
        for name, kwargs, reason in negative:
            with self.subTest(adapter=name, reason=reason):
                code, result = preflight(name, **kwargs)
                self.assertEqual(code, 2)
                self.assertEqual(result["status"], "REJECTED")
                self.assertEqual(result["reason"], reason)
                self.assertEqual(result["readiness"], UNKNOWN)
        audit = scenario("identity", "deterministic")
        self.assertTrue(audit["actor_scope_blocked"])
        self.assertTrue(audit["audit_full_blocked"])
        self.assertEqual(audit["replay"]["status"], "already_applied")
        self.assertEqual(audit["denied_replay"]["status"],
                         "owner_decision_required")
        for state in ("unknown", "contradictory", "incomplete"):
            self.assertTrue(scenario("lesson", "uncertain")[state][
                "owner_action_required"])
        self.assertEqual(matrix_state("", "CB-0573"), UNKNOWN)
        self.assertEqual(matrix_state(
            "| CB-0573 | x | text | CUBIERTO | note | #1 |",
            "CB-0573",
        ), UNKNOWN)
        with self.assertRaises(ValueError):
            scenario("identity", "non-existent")
        with self.assertRaises(ValueError):
            helper("network")

    def test_sin_datos_reales(self):
        """AC-03: solo fixtures locales sin PII, credenciales ni red."""
        self.assertEqual(set(HELPERS), {"backup", "deploy", "rollback", "health"})
        self.assertEqual(set(SCENARIOS), {"identity", "lesson"})
        for filename in HELPERS.values():
            self.assertTrue((ROOT / "tests" / filename).is_file())
        for filename, allowed in SCENARIOS.values():
            self.assertTrue((ROOT / "tests" / filename).is_file())
            self.assertTrue(allowed)
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:httpx|requests|socket|urllib)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY")
        self.assertNotRegex(own, r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
        self.assertNotRegex(own, r"Bearer\s+[A-Za-z0-9._~+/=-]{15,}")
        self.assertEqual(operational_status(), UNKNOWN)
        self.assertEqual(scenario("lesson", "valid")["publication_state"], "pending")
        for name in ("backup", "deploy", "rollback"):
            self.assertEqual(preflight(name)[1]["readiness"], UNKNOWN)

    def test_estados_unknown_explicitos(self):
        """AC-04: matriz no promocionada y 15 estados productivos UNKNOWN."""
        found = set()
        for ident in IDS:
            with self.subTest(clause=ident):
                state = matrix_state(self.matrix, ident)
                self.assertIn(state, (UNKNOWN, "NO_VERIFICABLE"))
                if state == "NO_VERIFICABLE":
                    found.add(ident)
                self.assertEqual(operational_status(ident), UNKNOWN)
        self.assertEqual(found, {"CB-0573"})
        self.assertEqual(len(IDS), 15)
        self.assertNotIn("CB-0577", IDS)
        self.assertEqual(matrix_state(
            "| CB-0573 | ref | claim | NO_VERIFICABLE | note | #1 |\n"
            "| CB-0573| ref | claim | NO_VERIFICABLE | note | #1 |",
            "CB-0573",
        ), UNKNOWN)
        self.assertFalse(preflight("deploy")[1]["production_ready"])


if __name__ == "__main__":
    unittest.main()
