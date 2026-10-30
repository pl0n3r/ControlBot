#!/usr/bin/env python3
"""ControlBot #1025: offline contract tests for CB-0257..CB-0271.

Only synthetic PHP fixtures and repository-local sources are admissible.
Neither test success nor policy configuration proves live authority or delivery.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(257, 272))
UNKNOWN = "UNKNOWN"
MATRIX = ROOT / "docs" / "matriz-cobertura.md"
PART = ROOT / "docs" / "matriz-cobertura-parte-01.md"
SOURCE_ROWS = {"CB-0260", "CB-0266", "CB-0271"}
SCRIPTS = {
    "push": "external_api_push_notification_scenarios.php",
    "read": "external_api_read_projection_scenarios.php",
    "gate": "external_api_request_gate_scenarios.php",
    "session": "external_api_session_source_scenarios.php",
}
CASES = {
    "push": {"detail", "policy", "minimal"},
    "read": {"cockpit", "inbox", "freshness", "invalid", "no_leak"},
    "gate": {"read", "deny", "mutation", "auth_fail", "client", "trusted_mismatch"},
    "session": {"source", "fail_closed", "secrets", "mint"},
}
SNIPPETS = {
    "CB-0257": "detail_operation_id",
    "CB-0258": "deliveryPolicy()",
    "CB-0259": "Ausencia de preferencia",
    "CB-0260": "Freshness conserva",
    "CB-0261": "`unknown` exige",
    "CB-0262": "`stale` nunca",
    "CB-0263": "Cockpit expone",
    "CB-0264": "Owner Inbox expone",
    "CB-0265": "La ausencia de evidencia",
    "CB-0266": "Compone autenticación",
    "CB-0267": "Valida device/session",
    "CB-0268": "`deny` permanece",
    "CB-0269": "`step_up_required`",
    "CB-0270": "La salida conserva",
    "CB-0271": "La consulta recibe",
}
DOCS = {
    "push": "docs/external-api-push-notification-v1.md",
    "read": "docs/external-api-read-projection-v1.md",
    "gate": "docs/external-api-request-gate-v1.md",
    "session": "docs/external-api-session-source-v1.md",
}
CLAUSE_DOMAIN = {
    **{f"CB-{n:04d}": "push" for n in range(257, 260)},
    **{f"CB-{n:04d}": "read" for n in range(260, 266)},
    **{f"CB-{n:04d}": "gate" for n in range(266, 271)},
    "CB-0271": "session",
}
ENV_ALLOWLIST = (
    "PATH", "HOME", "LANG", "LC_ALL", "TZ", "TMPDIR", "SYSTEMROOT",
    "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
    "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
)


def matrix_states(root: str, partition: str) -> dict[str, str]:
    """Missing, duplicate, malformed, or promoted rows stay UNKNOWN."""
    rows: dict[str, list[tuple[str, tuple[str, ...]]]] = {
        clause: [] for clause in IDS
    }
    for path, text in (("root", root), ("part", partition)):
        for line in text.splitlines():
            if not line.startswith("| CB-"):
                continue
            fields = tuple(s.strip() for s in line.strip().strip("|").split("|"))
            if fields and fields[0] in rows:
                rows[fields[0]].append((path, fields))
    states = {}
    for key, matches in rows.items():
        expected = "root" if key in SOURCE_ROWS else "part"
        if (len(matches) == 1 and matches[0][0] == expected
                and len(matches[0][1]) == 6
                and credible_offline_coverage_row(matches[0][1])
                and SNIPPETS[key] in matches[0][1][2]
                and DOCS[CLAUSE_DOMAIN[key]] in matches[0][1][1]
                ):
            states[key] = matches[0][1][3]
        else:
            states[key] = UNKNOWN
    return states


def offline_environment() -> dict[str, str]:
    """Pass only PHP and CI-coverage plumbing, never service credentials."""
    return {key: os.environ[key] for key in ENV_ALLOWLIST if key in os.environ}


@lru_cache(maxsize=32)
def scenario(domain: str, case: str) -> dict:
    if case not in CASES.get(domain, set()):
        raise AssertionError("UNKNOWN: unapproved local scenario")
    path = ROOT / "tests" / SCRIPTS[domain]
    result = subprocess.run(
        ["php", str(path), case], cwd=ROOT, env=offline_environment(),
        capture_output=True, text=True, check=False, timeout=35,
    )
    if result.returncode != 0:
        raise AssertionError(f"UNKNOWN: PHP fixture {domain}/{case}: {result.stderr[-300:]}")
    output = json.loads(result.stdout)
    if not isinstance(output, dict):
        raise AssertionError(f"UNKNOWN: invalid PHP fixture {domain}/{case}")
    return output


def operational_state(_clause: str, _offline: object = None) -> str:
    """No offline observation confers deployment or production authority."""
    return UNKNOWN


class BrechasControlbot18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one independent executable assertion for each of 15 IDs."""
        detail = scenario("push", "detail")
        policy = scenario("push", "policy")
        push = scenario("push", "minimal")
        freshness = scenario("read", "freshness")
        cockpit = scenario("read", "cockpit")
        inbox = scenario("read", "inbox")
        invalid = scenario("read", "invalid")
        read_gate = scenario("gate", "read")
        deny = scenario("gate", "deny")
        mutation = scenario("gate", "mutation")
        auth_fail = scenario("gate", "auth_fail")
        client = scenario("gate", "client")
        session = scenario("session", "source")
        session_failed = scenario("session", "fail_closed")
        row = cockpit["data"]["ventures"][0]
        entries = inbox["data"]["entries"]
        meta_unknown = freshness["unknown"]["meta"]["freshness"]
        meta_stale = freshness["stale"]["meta"]["freshness"]
        audit = mutation["with"]
        verdicts = {
            "CB-0257": (
                detail["is_public_get"] is True
                and detail["requires_authenticated_detail"] is True
                and detail["post_operation"] is True
                and detail["unknown_operation"] is True
            ),
            "CB-0258": (
                policy == scenario("push", "policy")
                and policy["deliver"]["decision"] == "deliver"
                and policy["deliver"]["requires_authenticated_detail"] is True
                and "deliveryPolicy" in (ROOT / "src/ExternalApiPushNotification.php").read_text()
            ),
            "CB-0259": (
                policy["default_quiet"]["decision"] == "suppress"
                and "preference_disabled" in policy["default_quiet"]["reasons"]
                and all(policy[key]["decision"] == "suppress"
                        for key in ("expired", "duplicate", "rate_limited"))
                and "expired" in policy["expired"]["reasons"]
                and "duplicate" in policy["duplicate"]["reasons"]
                and "rate_limited" in policy["rate_limited"]["reasons"]
            ),
            "CB-0260": (
                {name: freshness[name]["meta"]["freshness"]["state"]
                 for name in ("current", "stale", "unknown")}
                == {"current": "current", "stale": "stale", "unknown": "unknown"}
            ),
            "CB-0261": (
                meta_unknown["observed_at"] is None
                and meta_unknown["source_ref"] is None
                and freshness["unknown_with_source"] is True
            ),
            "CB-0262": (
                meta_stale["state"] == "stale"
                and meta_stale["observed_at"] == 1000
                and meta_stale["source_ref"] == "controlbot:cockpit/group-health"
            ),
            "CB-0263": (
                set(row) == {"venture_ref", "business_state", "technical_state",
                             "pending_decisions", "critical_events"}
                and row["venture_ref"] == "controlbot:venture/condor"
                and row["business_state"] == "current"
                and row["technical_state"] == "stale"
                and row["pending_decisions"] == 2
                and row["critical_events"] == 1
            ),
            "CB-0264": (
                len(entries) == 2
                and all(set(item) == {"entry_id", "kind", "venture_ref",
                                     "title", "summary", "decision_id",
                                     "deadline_at"} for item in entries)
                and {item["kind"] for item in entries} == {"DECISION", "WATCH"}
            ),
            "CB-0265": (
                freshness["unknown"]["meta"]["freshness"]["state"] == "unknown"
                and invalid["bad_state"] is True
                and invalid["extra_meta"] is True
            ),
            "CB-0266": (
                read_gate["valid"]["decision"] == "allow"
                and mutation["without"]["decision"] == "step_up_required"
                and read_gate["revoked_device"] is True
                and client["raw_context"] is True
            ),
            "CB-0267": (
                read_gate["valid"]["session_ref"].startswith("session:")
                and read_gate["valid"]["device_ref"].startswith("device:")
                and session["calls"] == 1
                and session["summary"]["scope"] == "venture:alpha"
                and session["summary"]["identity_id"] == "identity-owner"
            ),
            "CB-0268": (
                deny["decision"] == "deny"
                and deny["step_up_ref"] is None
                or deny["decision"] == "deny"
                and deny["step_up_ref"].startswith("stepup:")
            ),
            "CB-0269": (
                mutation["without"]["decision"] == "step_up_required"
                and mutation["with"]["decision"] == "allow"
                and mutation["with"]["step_up_ref"].startswith("stepup:")
                and all(auth_fail.values())
            ),
            "CB-0270": (
                set(audit) == {"decision", "reasons", "operation_id",
                               "capability", "scope", "mutation",
                               "session_ref", "device_ref", "step_up_ref"}
                and audit["capability"] == "owner.decision.write"
                and audit["scope"] == "venture:alpha"
                and audit["mutation"] is True
                and all(client.values())
            ),
            "CB-0271": (
                session["calls"] == 1
                and session["summary"]["freshness"] == "fresh"
                and session["device"]["device_ref"] == session["summary"]["device_ref"]
                and session["session"]["session_ref"] == session["summary"]["session_ref"]
                and all(session_failed.values())
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        states = matrix_states(MATRIX.read_text(encoding="utf-8"),
                               PART.read_text(encoding="utf-8"))
        for key in IDS:
            with self.subTest(clause=key):
                self.assertTrue(verdicts[key], f"{key}: unsupported local contrast")
                self.assertIn(states[key], ("NO_VERIFICABLE", "CUBIERTO"))
                self.assertEqual(operational_state(key, verdicts[key]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: reject missing, hostile and ambiguous evidence."""
        root = MATRIX.read_text(encoding="utf-8")
        part = PART.read_text(encoding="utf-8")
        for clause in IDS:
            source = root if clause in SOURCE_ROWS else part
            row = next(line for line in source.splitlines()
                       if line.startswith(f"| {clause} |"))
            variations = (
                source.replace(row + "\n", "", 1),
                source + "\n" + row,
                source.replace(row, (row.replace("MERGED", "PENDING", 1) if "CUBIERTO" in row
                                     else row.replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1),
            )
            for candidate in variations:
                with self.subTest(clause=clause):
                    if clause in SOURCE_ROWS:
                        state = matrix_states(candidate, part)[clause]
                    else:
                        state = matrix_states(root, candidate)[clause]
                    self.assertEqual(state, UNKNOWN)
        self.assertTrue(all(scenario("push", "minimal")["extra"].values()))
        self.assertTrue(all(scenario("read", "invalid").values()))
        self.assertTrue(all(scenario("gate", "auth_fail").values()))
        self.assertTrue(all(scenario("gate", "client").values()))
        self.assertTrue(all(scenario("gate", "trusted_mismatch").values()))
        self.assertTrue(all(scenario("session", "fail_closed").values()))

    def test_sin_datos_reales(self) -> None:
        """AC-03: sealed local fixture allowlist and no leaked credentials."""
        self.assertEqual(set(CLAUSE_DOMAIN), set(IDS))
        self.assertEqual(set(SNIPPETS), set(IDS))
        self.assertEqual(set(SCRIPTS), set(CASES))
        for name in SCRIPTS.values():
            self.assertTrue((ROOT / "tests" / name).is_file(), name)
        for name in DOCS.values():
            self.assertTrue((ROOT / name).is_file(), name)
        for forbidden in ("GITHUB_TOKEN", "GH_TOKEN", "HOSTINGER_API_TOKEN",
                          "DATABASE_PASSWORD", "API_KEY", "SESSION_SECRET"):
            self.assertNotIn(forbidden, offline_environment())
        minimal = scenario("push", "minimal")["payload"]
        self.assertEqual(set(minimal), {
            "version", "notification_ref", "category", "severity",
            "entity_ref", "detail_operation_id", "localization_key",
            "issued_at", "expires_at", "dedupe_key",
            "requires_authenticated_detail",
        })
        self.assertTrue(scenario("read", "no_leak")["blocked_internal"])
        self.assertTrue(all(scenario("session", "secrets")["bad"].values()))
        self.assertFalse(any(key in scenario("session", "secrets")["summary"]
                             for key in ("access_token", "cookie", "otp",
                                         "private_key", "credentials")))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: declarative tests do not prove live or exact-HEAD CI."""
        root = MATRIX.read_text(encoding="utf-8")
        part = PART.read_text(encoding="utf-8")
        self.assertEqual(
            set(matrix_states(root, part)),
            set(IDS),
        )
        self.assertEqual(
            set(matrix_states(root, part).values()) <= {"NO_VERIFICABLE", "CUBIERTO"}, True)
        self.assertEqual(
            scenario("read", "freshness")["unknown"]["meta"]["freshness"]["state"],
            "unknown")
        for clause in IDS:
            with self.subTest(clause=clause):
                for claim in (None, {}, True, "success", "green",
                              {"ci": "success"}, {"owner_approval": "claimed"},
                              {"production": "claimed"}, {"step_up": "claimed"},
                              {"push_delivered": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
