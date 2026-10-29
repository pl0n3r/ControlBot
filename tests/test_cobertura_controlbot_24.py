#!/usr/bin/env python3
"""ControlBot #1207: executable offline coverage of GitHub intent grant boundaries.

The tests call production PHP through the existing deterministic, synthetic
Python/PHP adapters. Owner decisions and real-hosting assertions are NOT
presented as an executable permission, production readiness, or coverage.
"""
from __future__ import annotations

import copy
import hashlib
import json
import subprocess
import sys
import unittest
from pathlib import Path

from tests import test_github_intent_grant_verification as verifier
from tests import test_github_intent_offline_grant_harness as harness
from tests import test_github_intent_offline_issuer as issuer

ROOT = Path(__file__).resolve().parents[1]
FALSE_FLAGS = (
    "authorized", "grant_issued", "approval_issued",
    "approval_verified", "execution",
)
# C: OWNER decisions and documented limits, not runtime predicates.
NON_EXECUTABLE = frozenset({"CB-0382", "CB-0386", "CB-0391", "CB-0392"})
EXECUTABLE = frozenset({
    "CB-0383", "CB-0384", "CB-0385", "CB-0387", "CB-0388",
    "CB-0389", "CB-0390", "CB-0393", "CB-0394", "CB-0395", "CB-0396",
})
IDS = frozenset(f"CB-{n:04d}" for n in range(382, 397))


def deny_authority(result: dict, *, harness_result: bool = False) -> None:
    """Every actual PHP result must remain deny with all authority flags false."""
    assert result["decision"] == "deny", result
    for flag in FALSE_FLAGS:
        assert result[flag] is False, (flag, result)
    if harness_result:
        assert result["runtime_authorized"] is False, result


def existing_suite(name: str) -> None:
    """Execute the four existing tests, not source-text or documentary checks."""
    proc = subprocess.run(
        [sys.executable, "-m", "unittest", "-v", "tests." + name],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=90,
    )
    if proc.returncode:
        raise AssertionError(
            "Existing executable PHP contract failed: "
            + name + "\n" + proc.stderr[-2200:]
        )
    if "Ran 4 tests" not in proc.stderr or "OK" not in proc.stderr:
        raise AssertionError("Incomplete executable suite: " + name)


class CoberturaControlbot24Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        proved: set[str] = set()

        # CB-0383: call the real pure inspector on a valid, synthetic signed
        # grant and verify that signature validity cannot enable execution.
        candidate = verifier.candidate()
        initial = verifier.evaluate(candidate)
        self.assertIs(initial["proof_valid"], True)
        self.assertEqual(initial["reason"], "offline_only")
        deny_authority(initial)
        proved.add("CB-0383")

        # CB-0384: inspect the actual type/authority/owner/scope verifier.
        for operation in ("issue.update", "pr.merge", "workflow.dispatch"):
            case = verifier.candidate()
            capabilities = {
                "issue.update": "github.issue.write",
                "pr.merge": "github.pr.merge",
                "workflow.dispatch": "github.workflow.dispatch",
            }
            case["requirements"]["type"] = operation
            case["requirements"]["capability"] = capabilities[operation]
            case["proof"]["capability"] = capabilities[operation]
            verifier.sign(case)
            checked = verifier.evaluate(case)
            self.assertIs(checked["proof_valid"], True)
            deny_authority(checked)
        proved.add("CB-0384")

        # CB-0385: the documented CLI invocation runs all four PHP tests.
        existing_suite("test_github_intent_grant_verification")
        proved.add("CB-0385")

        # CB-0387: the real composition harness returns only a deterministic
        # signed fixture digest and a simulated (not executable) effect.
        scenario = harness.candidate()
        first = harness.execute(copy.deepcopy(scenario))
        second = harness.execute(copy.deepcopy(scenario))
        self.assertEqual(first["fixture_status"], "accepted_offline")
        self.assertEqual(first["fake_effect"]["status"], "simulated")
        digest = first["fake_effect"]["fixture_digest"]
        self.assertRegex(digest, r"^[a-f0-9]{64}$")
        self.assertEqual(digest, second["fake_effect"]["fixture_digest"])
        self.assertTrue(first["test_grant_valid"])
        proved.add("CB-0387")

        # CB-0388: deny applies to successful fake effects as well as failures.
        for invalid in (False, True):
            case = harness.candidate()
            if invalid:
                case["approval"]["approved"] = False
            output = harness.execute(case)
            deny_authority(output, harness_result=True)
            self.assertEqual(
                output["fake_effect"]["status"],
                "denied" if invalid else "simulated",
            )
            self.assertNotIn("signature", json.dumps(output))
        proved.add("CB-0388")

        # CB-0389: the harness accepts a narrow fake_action, not an intent,
        # callback, idempotency store, ledger mutation or runtime transport.
        for injected in (
            {"intent_envelope": {"type": "issue.update"}},
            {"idempotency_key": "fixture-only"},
            {"callback": "unexpected"},
        ):
            case = harness.candidate()
            case["fake_action"].update(injected)
            rejected = harness.execute(case)
            self.assertEqual(rejected["fixture_status"], "rejected")
            self.assertEqual(rejected["fake_effect"]["status"], "denied")
            deny_authority(rejected, harness_result=True)
        proved.add("CB-0389")

        # CB-0390: all four named harness tests call actual PHP classes.
        existing_suite("test_github_intent_offline_grant_harness")
        proved.add("CB-0390")

        # CB-0393: real issuer composes an HMAC proof without creating rights.
        valid_issuer = issuer.execute(issuer.candidate())
        self.assertEqual(valid_issuer["issuer"]["reason"], "offline_only")
        self.assertIsInstance(valid_issuer["issuer"]["proof"], dict)
        self.assertTrue(valid_issuer["verification"]["proof_valid"])
        deny_authority(valid_issuer["issuer"])
        deny_authority(valid_issuer["verification"])
        proved.add("CB-0393")

        # CB-0394: exact owner, scope and signed capability in real issuer.
        for action in ("pr.merge", "issue.update", "project.freeze",
                       "release.approve"):
            case = issuer.candidate(action)
            result = issuer.execute(case)
            self.assertEqual(result["issuer"]["proof"]["scope_ref"],
                             case["requirements"]["scope_ref"])
            self.assertEqual(result["issuer"]["proof"]["owner"], "pl0n3r")
            self.assertTrue(result["verification"]["proof_valid"])
            deny_authority(result["issuer"])
        proved.add("CB-0394")

        # CB-0395: even successful offline HMAC verification has zero
        # runtime/approval/grant authority and is never an ALLOW decision.
        for action in ("pr.merge", "project.freeze"):
            checked = issuer.execute(issuer.candidate(action))
            self.assertEqual(checked["issuer"]["reason"], "offline_only")
            self.assertEqual(checked["verification"]["reason"], "offline_only")
            self.assertTrue(checked["verification"]["proof_valid"])
            deny_authority(checked["issuer"])
            deny_authority(checked["verification"])
        proved.add("CB-0395")

        # CB-0396: the documented four-case CLI contract actually runs.
        existing_suite("test_github_intent_offline_issuer")
        proved.add("CB-0396")

        self.assertEqual(proved, EXECUTABLE)
        self.assertEqual(EXECUTABLE | NON_EXECUTABLE, IDS)
        self.assertFalse(EXECUTABLE & NON_EXECUTABLE)

    def test_falla_cerrado(self) -> None:
        # These are mutations of real PHP input, never simulated decisions.
        scenarios = []
        for field, value in (
            ("signature", "0" * 64),
            ("nonce", "short"),
            ("expires_at", 1000),
            ("issuer", "client:untrusted"),
        ):
            case = verifier.candidate()
            case["proof"][field] = value
            scenarios.append(case)
        seen = verifier.candidate()
        seen["seen"] = [seen["proof"]["nonce"]]
        scenarios.append(seen)
        evil = verifier.candidate()
        evil["requirements"]["authorized"] = True
        verifier.sign(evil)
        scenarios.append(evil)
        for case in scenarios:
            with self.subTest(proof=case["proof"]):
                result = verifier.evaluate(case)
                self.assertIs(result["proof_valid"], False)
                deny_authority(result)

        for section, field, value in (
            ("approval", "origin", "client"),
            ("approval", "approved", False),
            ("approval", "owner", "unknown"),
            ("approval", "approval_ref", "real:grant"),
            ("trusted", "mode", "production"),
            ("trusted", "ttl_seconds", 301),
            ("requirements", "scope_ref", "pl0n3r/Other"),
        ):
            with self.subTest(issuer=(section, field)):
                case = issuer.candidate()
                case[section][field] = value
                result = issuer.execute(case)["issuer"]
                self.assertIsNone(result["proof"])
                deny_authority(result)

        for field in ("seen", "revoked"):
            case = harness.candidate()
            case[field] = [case["trusted"]["nonce"]]
            result = harness.execute(case)
            self.assertEqual(result["fixture_status"], "rejected")
            deny_authority(result, harness_result=True)
        foreign = harness.candidate()
        foreign["fake_action"]["scope_ref"] = "pl0n3r/Other"
        denied = harness.execute(foreign)
        self.assertEqual(denied["fake_effect"]["status"], "denied")
        deny_authority(denied, harness_result=True)

    def test_sin_datos_reales(self) -> None:
        # Deterministic keys are synthetic bytes; never an env/token lookup.
        self.assertEqual(verifier.KEY, bytes(range(32, 64)))
        self.assertEqual(issuer.FIXTURE_KEY, bytes(range(32, 64)))
        self.assertEqual(harness.KEY, bytes(range(32, 64)).hex())
        case = harness.candidate()
        before = copy.deepcopy(case)
        outcome = harness.execute(case)
        self.assertEqual(case, before)
        deny_authority(outcome, harness_result=True)
        serialized = json.dumps(outcome)
        self.assertNotIn(case["key"], serialized)
        self.assertNotIn("signature", serialized)
        self.assertNotIn("authorization", serialized)

    def test_ci_contract(self) -> None:
        # The workflow is present; only GitHub certifies terminal Validar
        # against this pull request's exact head SHA.
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: CI ControlBot", ci)
        self.assertIn("name: Validar", ci)
        self.assertIn("python3 scripts/generate_coverage.py", ci)
        self.assertIn("pl0n3r/factory/.github/workflows/ci.yml@v1", ci)


if __name__ == "__main__":
    unittest.main()
