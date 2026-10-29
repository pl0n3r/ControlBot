#!/usr/bin/env python3
"""#1191: PHP runtime regressions for CB-0087..CB-0104.

All fixtures are synthetic; no real users, secrets, live calls or payments.
Passing these tests never proves deployment or production coverage.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROWS = frozenset(f"CB-{n:04d}" for n in range(87, 105))
CLASSIFIED = {
    "CB-0097": ("C", "The in-memory revocation map explicitly has no cross-process durability or live single-use guarantee."),
    "CB-0098": ("C", "Rollback of source files and historical review requirements are documentary governance, not an executable behavior."),
}
SCRIPTS = {
    "venture": ("tests/venture_access_runtime_scenarios.php",
                {"trusted", "scope", "restrictions", "owner", "replay", "audit", "secrets", "e2e"}),
    "grant": ("tests/capability_grant_revoker_scenarios.php",
              {"finish", "existing", "deny", "inflight_key", "privacy"}),
    "capital": ("tests/capital_policy_scenarios.php",
                {"base", "restrictions", "owner-gates", "shared-costs", "no-payments", "deterministic"}),
}


@lru_cache(maxsize=None)
def scenario(component: str, mode: str) -> dict:
    """Execute checked-in PHP scenario against actual source classes."""
    if component not in SCRIPTS or mode not in SCRIPTS[component][1]:
        raise AssertionError("UNKNOWN: unsupported PHP scenario")
    binary = shutil.which("php")
    fixture = ROOT / SCRIPTS[component][0]
    if binary is None or not fixture.is_file():
        raise AssertionError("UNKNOWN: PHP CLI or fixture unavailable")
    result = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", str(fixture), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(f"UNKNOWN: PHP {component}/{mode} failed (exit={result.returncode})")
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid PHP output") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP output was not an object")
    return data


def field(data: dict, *path: str):
    for segment in path:
        data = data[segment]
    return data


# Each listed clause names a particular *runtime* result of a PHP fixture.
# The two non-executable notes are explicit above, never silently promoted.
CHECKS = {
    "CB-0087": (
        ("venture", "trusted", ("actor",), True),
        ("venture", "trusted", ("scope",), True),
        ("venture", "scope", ("status",), "deny"),
    ),
    "CB-0088": (
        ("venture", "restrictions", ("budget", "status"), "deny"),
        ("venture", "restrictions", ("production", "status"), "owner_decision_required"),
        ("venture", "restrictions", ("allow", "status"), "applied"),
    ),
    "CB-0089": (
        ("venture", "restrictions", ("budget_replay", "status"), "deny"),
        ("venture", "restrictions", ("production_replay", "status"), "owner_decision_required"),
        ("venture", "restrictions", ("budget_replay", "audit", "reason_code"), "budget_blocked"),
        ("venture", "restrictions", ("production_replay", "audit", "reason_code"), "production_override_required"),
    ),
    "CB-0090": (
        ("venture", "restrictions", ("budget", "audit", "command_id"), "suspend01"),
        ("venture", "restrictions", ("budget", "state", "audit"), None),
        ("venture", "replay", ("first", "status"), "applied"),
        ("venture", "replay", ("replay", "status"), "already_applied"),
        ("venture", "replay", ("replay", "audit", "outcome"), "applied"),
    ),
    "CB-0091": (
        ("venture", "owner", ("status",), "owner_decision_required"),
        ("venture", "owner", ("parsed_gate", "category"), "product-direction"),
        ("venture", "owner", ("parsed_gate", "safe_default"), "B"),
    ),
    "CB-0092": (
        ("venture", "audit", ("grant", "kind"), "grant"),
        ("venture", "audit", ("revoke", "kind"), "revoke"),
        ("venture", "audit", ("override", "kind"), "override"),
        ("venture", "audit", ("deny", "kind"), "deny"),
    ),
    "CB-0093": (
        ("grant", "existing", ("read", "authorized"), True),
        ("grant", "existing", ("scope_wrong", "reason"), "project_mismatch"),
        ("grant", "existing", ("restricted", "reason"), "grant_expired"),
    ),
    "CB-0094": (
        ("grant", "finish", ("success", "status"), "executed"),
        ("grant", "finish", ("failed", "status"), "failed"),
        ("grant", "finish", ("ambiguous", "status"), "ambiguous"),
        ("grant", "finish", ("ambiguous", "error_code"), "fake_execution_unconfirmed"),
    ),
    "CB-0095": (
        ("grant", "finish", ("after", "reason"), "grant_consumed"),
        ("grant", "finish", ("failed_after", "reason"), "grant_consumed"),
        ("grant", "finish", ("restricted_replay", "reason"), "idempotency_collision"),
        ("grant", "finish", ("calls",), 1),
    ),
    "CB-0096": (
        ("grant", "finish", ("success", "audit", "event"), "grant_consumed"),
        ("grant", "finish", ("failed", "audit", "status"), "failed"),
        ("grant", "finish", ("failed", "error_code"), "fake_execution_failed"),
        ("grant", "finish", ("ambiguous", "audit", "at"), "2026-09-28T08:10:00Z"),
    ),
    "CB-0099": (
        ("capital", "base", ("scope",), "venture:condor"),
        ("capital", "base", ("currency",), "COP"),
        ("capital", "base", ("decision",), "allow"),
        ("capital", "base", ("budget", "after_proposal_minor"), 12000000),
        ("capital", "base", ("reserve", "cash_after_proposal_minor"), 45000000),
    ),
    "CB-0100": (
        ("capital", "base", ("proposal", "proposal_id"), "proposal-growth"),
        ("capital", "base", ("proposal", "amount_minor"), 5000000),
        ("capital", "base", ("proposal", "required_authority_level"), "L2_VENTURE_ADMIN"),
        ("capital", "base", ("proposal", "irreversible"), False),
    ),
    "CB-0101": (
        ("capital", "deterministic", ("unknown", "decision"), "deny"),
        ("capital", "deterministic", ("mismatch", "decision"), "deny"),
        ("capital", "deterministic", ("mismatch", "execution"), False),
    ),
    "CB-0102": (
        ("capital", "restrictions", ("budget_guard", "decision"), "deny"),
        ("capital", "restrictions", ("decision_rights", "decision"), "deny"),
        ("capital", "owner-gates", ("l4", "decision"), "owner_decision_required"),
        ("capital", "owner-gates", ("irreversible", "decision"), "owner_decision_required"),
        ("capital", "owner-gates", ("reserve_floor", "decision"), "owner_decision_required"),
    ),
    "CB-0103": (
        ("capital", "no-payments", ("evaluated", "decision"), "deny"),
        ("capital", "no-payments", ("evaluated", "execution"), False),
        ("capital", "no-payments", ("leaked",), False),
    ),
    "CB-0104": (
        ("capital", "deterministic", ("same",), True),
        ("capital", "deterministic", ("invalid_gate", "decision"), "deny"),
        ("capital", "shared-costs", ("shared_costs",), None),
    ),
}


class CoberturaControlbot08Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all executable clauses require actual PHP results."""
        self.assertEqual(len(ROWS), 18)
        self.assertEqual(len(CHECKS), 16)
        self.assertEqual(set(CHECKS) | set(CLASSIFIED), ROWS)
        for row, checks in CHECKS.items():
            with self.subTest(row=row):
                self.assertGreaterEqual(len(checks), 2)
                for component, mode, path, expected in checks:
                    actual = field(scenario(component, mode), *path)
                    if expected is None:
                        self.assertIsInstance(actual, list)
                        self.assertGreater(len(actual), 0)
                    else:
                        self.assertEqual(actual, expected)
        # CB-0090: restricted event reuses original command and idempotency.
        restriction = scenario("venture", "restrictions")["budget"]
        self.assertEqual(restriction["state"]["audit"][-1]["command_id"], "suspend01")
        self.assertEqual(restriction["state"]["audit"][-1]["idempotency_key"], "idem_suspend01")
        applied = scenario("venture", "replay")
        self.assertEqual(applied["replay"]["audit"]["occurred_at"],
                         applied["first"]["audit"]["occurred_at"])
        # CB-0095: equivalent policy replay must never repeat the fake callback.
        finish = scenario("grant", "finish")
        self.assertEqual(finish["replay"], finish["success"])
        # CB-0096 / CB-0092: the two audit schemas omit caller payloads.
        for result in ("success", "failed", "ambiguous"):
            self.assertEqual(set(finish[result]["audit"]), {"event", "status", "at"})
        for name, receipt in scenario("venture", "audit").items():
            self.assertEqual(set(receipt), {
                "event_id", "command_id", "actor_identity_id", "scope",
                "operation", "kind", "outcome", "reason_code", "occurred_at",
            }, name)
        # CB-0104: check actual allocation, not just document presence.
        rows = {r["cost_id"]: r for r in scenario("capital", "shared-costs")["shared_costs"]}
        self.assertEqual(rows["cost-infra"]["status"], "allocated")
        self.assertEqual(rows["cost-shared"]["status"], "unallocated")
        self.assertIsNone(rows["cost-shared"]["target_scope"])

    def test_falla_cerrado(self) -> None:
        """AC-02: real code denies untrusted identity/scope and unsafe replay."""
        self.assertTrue(all(scenario("venture", "secrets")["blocked"].values()))
        edge = scenario("venture", "e2e")
        self.assertEqual(edge["foreign"]["status"], "deny")
        self.assertEqual(edge["owner"]["status"], "owner_decision_required")
        self.assertEqual(edge["gate"]["safe_default"], "B")
        denies = scenario("grant", "deny")
        for key, reason in (
            ("expired", "grant_expired"), ("revoked", "grant_revoked"),
            ("reentrant", "grant_in_flight"), ("collision", "idempotency_collision"),
            ("scope_collision", "idempotency_collision"),
        ):
            self.assertEqual(denies[key]["status"], "rejected")
            self.assertEqual(denies[key]["reason"], reason)
        self.assertEqual(denies["calls"], 1)
        inflight = scenario("grant", "inflight_key")
        self.assertEqual(inflight["b_inflight"]["reason"], "idempotency_collision")
        self.assertEqual(inflight["b_authorize"]["reason"], "idempotency_collision")
        self.assertEqual(inflight["calls"], 2)
        self.assertEqual(scenario("grant", "existing")["fake_calls"], 0)
        result = scenario("capital", "deterministic")
        self.assertEqual(result["unknown"]["reasons"], ["unknown_financial_state"])
        self.assertEqual(result["mismatch"], {
            "version": 1, "decision": "deny", "reasons": ["invalid_input"],
            "execution": False,
        })
        self.assertEqual(result["first"], result["second"])
        self.assertIn("does not execute payments", scenario("capital", "no-payments")["payment"])
        with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
            scenario("capital", "not-a-mode")

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network, real payments, data persistence or customer PII."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertTrue(all(Path(x).parts[0] == "tests" for x, _ in SCRIPTS.values()))
        for row, (kind, why) in CLASSIFIED.items():
            with self.subTest(row=row):
                self.assertEqual(kind, "C")
                self.assertGreater(len(why), 35)
        self.assertIs(scenario("capital", "base")["execution"], False)
        self.assertNotIn("password", json.dumps(scenario("grant", "privacy")).lower())
        with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
            scenario("venture", "not-a-mode")


if __name__ == "__main__":
    unittest.main()
