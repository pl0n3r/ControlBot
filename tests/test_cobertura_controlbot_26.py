#!/usr/bin/env python3
"""#1209: behavioral coverage of CB-0415..CB-0431 via real offline PHP.

The canonical observer/clock, crypto authenticity and real GitHub transport
cannot be proved with fake fixtures. A simulated issue mutation never grants
real GitHub authority or justifies promoting a matrix row by itself.
"""
from __future__ import annotations

import importlib.util
import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ALL_ROWS = {f"CB-{number:04d}" for number in range(415, 432)}
# An offline PHP call does not replace a trusted production observer or policy.
NON_OFFLINE = {
    "CB-0415": ("B", "Canonical authenticated observer/store provenance needs real integration evidence"),
    "CB-0416": ("B", "Trusted server clock and channel injection cannot be attested by a caller fixture"),
    "CB-0417": ("C", "Structural array validation is not cryptographic authentication; a policy limitation"),
    "CB-0426": ("C", "PHP CLI and synthetic fixture instructions document the testing harness"),
    "CB-0431": ("C", "Squash/revert and no-deploy instructions describe a release policy, not an executable function"),
}
OFFLINE_ROWS = ALL_ROWS - set(NON_OFFLINE)
TRUSTED_CASES = {"canonical", "ttl_boundary", "closed"}
ACTIONS = {"issue.create", "issue.update", "issue.close", "issue.reserve", "issue.release"}


def _php() -> str:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("PHP CLI unavailable: real PHP behavior is NOT verified")
    return binary


@lru_cache(maxsize=3)
def trusted_case(name: str) -> dict:
    """Approved local PHP fixture calls GitHubIntentTrustedEvidence::derive."""
    if name not in TRUSTED_CASES:
        raise AssertionError("Unapproved fixture case")
    script = ROOT / "tests/github_intent_trusted_evidence_scenarios.php"
    if not script.is_file():
        raise AssertionError("Real PHP fixture missing")
    result = subprocess.run(
        [_php(), "-d", "allow_url_fopen=0", str(script), name],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode or not result.stdout:
        raise AssertionError("Trusted-evidence PHP scenario failed: " + result.stderr[-300:])
    return json.loads(result.stdout)


@lru_cache(maxsize=1)
def issue_helpers():
    """Reuse the real PHP simulator's synthetic input generator, not mock output."""
    path = ROOT / "tests/test_github_issue_intent_executor.py"
    spec = importlib.util.spec_from_file_location("_cb1209_offline_issue_helpers", path)
    if spec is None or spec.loader is None:
        raise AssertionError("Executable PHP harness unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.candidate, module.execute, module.KEY


def fake_action(name: str) -> tuple[dict, dict]:
    if name not in ACTIONS:
        raise AssertionError("Unapproved fake action")
    candidate, execute, _ = issue_helpers()
    request = candidate(name)
    return request, execute(request)


class CoberturaControlbot26Tests(unittest.TestCase):
    """Only real PHP scenario outputs count as executable offline coverage."""

    def assert_never_runtime(self, result: dict) -> None:
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("transport", result)
        self.assertNotIn("fixture_proof", result)

    def test_contrato_principal(self) -> None:
        """AC-01: strict source/scope/TTL and real in-memory intent lifecycle."""
        self.assertEqual(17, len(ALL_ROWS))
        self.assertEqual(12, len(OFFLINE_ROWS))

        # CB-0418/19/20: actual project validation, strict source and 300 s TTL.
        trusted = trusted_case("canonical")
        self.assertEqual("current", trusted["state"])
        self.assertEqual(1, trusted["version"])
        self.assertEqual("pl0n3r/ControlBot", trusted["repository_ref"])
        self.assertEqual("https://github.com/pl0n3r/ControlBot", trusted["source_ref"])
        self.assertEqual(trusted["source_ref"] + "/commit/" + "a" * 40,
                         trusted["evidence_ref"])
        self.assertEqual("current", trusted["freshness"])
        self.assertEqual(120, trusted["age_seconds"])
        self.assertEqual(1791157800 - 120, trusted["observed_at"])
        self.assertIs(trusted["execution"], False)
        border = trusted_case("ttl_boundary")
        self.assertEqual("current", border["last_accepted_second"]["state"])
        self.assertEqual(300, border["last_accepted_second"]["age_seconds"])
        self.assertEqual("unknown", border["first_rejected_second"]["state"])
        self.assertNotIn("evidence_ref", border["first_rejected_second"])

        # CB-0425/27/28: simulator calls real GitHubIssueIntentExecutor::simulate;
        # 'simulated' proves fixture mutation, NOT GitHub write authorization.
        actions = {}
        for name in sorted(ACTIONS):
            with self.subTest(action=name):
                request, result = fake_action(name)
                self.assert_never_runtime(result)
                self.assertEqual("simulated", result["fake_effect"]["status"])
                self.assertEqual(name, result["fake_state"]["events"][0]["type"])
                self.assertTrue(result["fake_state"]["events"][0]["fake_only"])
                self.assertEqual("executed", result["receipt"]["status"])
                self.assertEqual(name, result["receipt"]["type"])
                self.assertEqual(request["intent"]["idempotency_key"],
                                 result["receipt"]["idempotency_key"])
                actions[name] = result
        self.assertEqual([101],
                         [x["number"] for x in actions["issue.create"]["fake_state"]["issues"]])

        # CB-0429: the reservation exists only in fake_state.
        reserve = actions["issue.reserve"]
        self.assertEqual("controlbot:fixture/reservation",
                         reserve["fake_state"]["issues"][0]["reservation_ref"])
        self.assertIsNone(actions["issue.release"]["fake_state"]["issues"][0]["reservation_ref"])

        # CB-0430: same intent + scope replays without duplicated side effect.
        candidate, execute, _ = issue_helpers()
        original = candidate("issue.create")
        first = execute(original)
        retry = candidate("issue.create")
        retry["state"] = first["fake_state"]
        retry["audit"] = first["audit"]
        retry["ledger"] = first["ledger"]
        replay = execute(retry)
        self.assert_never_runtime(replay)
        self.assertTrue(replay["replayed"])
        self.assertEqual("simulated", replay["fake_effect"]["status"])
        self.assertEqual(first["receipt"], replay["receipt"])
        self.assertEqual(first["fake_state"], replay["fake_state"])
        self.assertEqual(first["audit"], replay["audit"])
        self.assertEqual(first["ledger"], replay["ledger"])

        # The exact set is recorded; documents/production-only rows remain B/C.
        demonstrated = {
            "CB-0418", "CB-0419", "CB-0420", "CB-0425",
            "CB-0427", "CB-0428", "CB-0429", "CB-0430",
        }
        self.assertTrue(demonstrated.issubset(OFFLINE_ROWS))

    def test_falla_cerrado(self) -> None:
        """AC-02: no caller clock, stale scope, reserved close or fake transport."""
        # CB-0420..24: 16 actual PHP projections, not checks of file contents.
        cases = trusted_case("closed")
        self.assertEqual(16, len(cases))
        for key in (
            "caller_timestamp", "caller_ttl", "scope_mismatch",
            "scope_case_mismatch", "stale", "future",
            "duplicated_repository", "partial_or_ambiguous",
            "mismatched_source", "unexpected_row_field",
            "untrusted_clock", "empty_repositories",
            "owner_dot", "owner_dotdot", "repository_dot", "repository_dotdot",
        ):
            with self.subTest(snapshot=key):
                denied = cases[key]
                self.assertEqual("unknown", denied["state"])
                self.assertEqual("untrusted_or_stale_snapshot", denied["reason"])
                self.assertIs(denied["execution"], False)
                for forbidden in ("allow", "evidence_ref", "observed_at", "grants"):
                    self.assertNotIn(forbidden, denied)

        candidate, execute, _ = issue_helpers()
        # CB-0429: cannot close an open fake issue while reserved.
        reserved_close = candidate("issue.close")
        reserved_close["state"]["issues"][0]["reservation_ref"] = (
            "controlbot:fixture/reservation"
        )
        denied = execute(reserved_close)
        self.assert_never_runtime(denied)
        self.assertEqual("denied", denied["fake_effect"]["status"])
        self.assertEqual(reserved_close["state"], denied["fake_state"])

        mismatch = candidate("issue.release")
        mismatch["state"]["issues"][0]["reservation_ref"] = (
            "controlbot:fixture/different"
        )
        denied = execute(mismatch)
        self.assert_never_runtime(denied)
        self.assertEqual("denied", denied["fake_effect"]["status"])
        self.assertEqual(mismatch["state"], denied["fake_state"])

        # CB-0430: same idempotency key with different issue identity fails.
        created = execute(candidate("issue.create"))
        collision = candidate("issue.create")
        collision["state"] = created["fake_state"]
        collision["audit"] = created["audit"]
        collision["ledger"] = created["ledger"]
        collision["fixture"]["issue_number"] = 102
        denied = execute(collision)
        self.assert_never_runtime(denied)
        self.assertEqual("denied", denied["fake_effect"]["status"])
        self.assertEqual(created["fake_state"], denied["fake_state"])

        # CB-0427/28: ambiguous/failing fake transport cannot mutate.
        for outcome in ("ambiguous", "failure"):
            with self.subTest(transport=outcome):
                request = candidate("issue.reserve")
                request["fixture"]["outcome"] = outcome
                result = execute(request)
                self.assert_never_runtime(result)
                self.assertEqual(
                    "ambiguous" if outcome == "ambiguous" else "failed",
                    result["fake_effect"]["status"],
                )
                self.assertEqual(request["state"], result["fake_state"])
                self.assertEqual([], result["audit"])
                self.assertEqual({}, result["ledger"])

        # Owner-only authority cannot be forged by modifying a fixture.
        no_owner = candidate("issue.update")
        no_owner["fixture"]["approval"]["owner_verified"] = False
        rejected = execute(no_owner)
        self.assert_never_runtime(rejected)
        self.assertEqual("denied", rejected["fake_effect"]["status"])
        self.assertEqual(no_owner["state"], rejected["fake_state"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic-only, no secrets or runtime execution in PHP output."""
        self.assertEqual(ALL_ROWS, OFFLINE_ROWS | set(NON_OFFLINE))
        self.assertFalse(OFFLINE_ROWS & set(NON_OFFLINE))
        self.assertTrue(all(cat in {"B", "C", "D"} and reason
                            for cat, reason in NON_OFFLINE.values()))
        self.assertTrue(all(re.fullmatch(r"CB-\d{4}", x)
                            for x in ALL_ROWS))

        # These outputs come from the real PHP entrypoints with fake fixtures.
        trusted = trusted_case("canonical")
        request, simulated = fake_action("issue.create")
        self.assert_never_runtime(simulated)
        self.assertIs(trusted["execution"], False)
        self.assertTrue(request["intent"]["repository_ref"].startswith("pl0n3r/"))
        self.assertTrue(request["intent"]["params"]["payload_ref"].startswith(
            "controlbot:fixture/"
        ))
        self.assertEqual("simulated", simulated["fake_effect"]["status"])
        self.assertEqual("offline_fake_only", simulated["fake_effect"]["reason"])
        _, _, key = issue_helpers()
        serialized = json.dumps(simulated).lower()
        for sensitive in (
            "fixture_proof", "authorization", "bearer", "access_token",
            "private_key", "password", key.lower(),
        ):
            self.assertNotIn(sensitive, serialized)

        # No real GitHub requests can be inferred from a deterministic receipt.
        self.assertEqual("deny", simulated["decision"])
        self.assertIs(simulated["runtime_authorized"], False)
        self.assertIn("fake_state", simulated)
        self.assertNotIn("github_api_response", simulated)


if __name__ == "__main__":
    unittest.main()
