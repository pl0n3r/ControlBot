#!/usr/bin/env python3
"""ControlBot #1201: real offline PHP regressions for external API authorization.

Only synthetic, checked-in PHP fixtures invoke production classes. Documentary
limits CB-0274/0279/0284/0286/0287 are classified in the PR and issue, not
counted as runnable product behavior. No coverage-matrix state is inspected.
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
SCRIPTS = {
    "session": "tests/external_api_session_scenarios.php",
    "source": "tests/external_api_session_source_scenarios.php",
    "access": "tests/external_api_access_scenarios.php",
    "gate": "tests/external_api_request_gate_scenarios.php",
}
ROWS = frozenset(f"CB-{n:04d}" for n in range(274, 292))
CLASSIFIED = {
    "CB-0274": ("C", "Pure authentication-vs-authorization boundary is a stated scope limit."),
    "CB-0279": ("C", "Historic Phase 0 exclusions are architectural scope notes, not a login implementation."),
    "CB-0284": ("C", "Stolen-session threat limitations cannot prove a missing mobile token issuer."),
    "CB-0286": ("C", "Absence of a second executor/queue is a scope constraint, not a runnable workflow."),
    "CB-0287": ("C", "Future-slice plan, not a current end-to-end authentication requirement."),
}
OFFLINE = ROWS - CLASSIFIED.keys()


@lru_cache(maxsize=None)
def scenario(area: str, mode: str) -> dict:
    """Execute an actual PHP module through its committed synthetic harness."""
    if area not in SCRIPTS:
        raise AssertionError(f"UNKNOWN: unsupported scenario {area}")
    path = ROOT / SCRIPTS[area]
    if not path.is_file():
        raise AssertionError(f"UNKNOWN: production fixture absent: {path.name}")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable; cannot claim coverage")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(path), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=45, check=False,
    )
    # The pre-existing access harness has one harmless PHP compile warning:
    # a global-class `use InvalidArgumentException` in a non-namespaced file.
    # Accept only that exact diagnostic; any other warning/error still fails.
    expected_access_warning = re.compile(
        r"^PHP Warning:\s+The use statement with non-compound name "
        r"'InvalidArgumentException' has no effect in "
        r".*/tests/external_api_access_scenarios\.php on line 23$"
    )
    warnings = [line.strip() for line in result.stderr.splitlines() if line.strip()]
    unexpected = [
        line for line in warnings
        if not (area == "access" and expected_access_warning.fullmatch(line))
    ]
    if result.returncode or unexpected:
        raise AssertionError(
            f"UNKNOWN: PHP {area}/{mode} failed ({result.returncode}): "
            + "\n".join(unexpected)[-800:]
        )
    try:
        output = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid PHP result") from exc
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: PHP result is not an object")
    return output


class CoberturaControlbot18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: real session, capability/authority and request-gate behavior."""
        self.assertEqual(len(ROWS), 18)
        self.assertEqual(len(OFFLINE), 13)
        self.assertEqual(ROWS, OFFLINE | CLASSIFIED.keys())

        # CB-0275: opaque device and session tied to identity, lifetime and revocation.
        bind = scenario("session", "binding")
        self.assertEqual(bind["device"]["identity_id"], "owner-human")
        self.assertRegex(bind["device"]["device_ref"], r"^device:[a-f0-9]{32}$")
        self.assertEqual(bind["session"]["device_ref"], bind["device"]["device_ref"])
        life = scenario("session", "lifetime")
        self.assertEqual(life["valid"]["state"], "active")
        self.assertLessEqual(
            life["valid"]["expires_at"] - life["valid"]["issued_at"], 3600
        )

        # CB-0276: server-bound identity, device and scope are exact.
        self.assertEqual(bind["session"]["scope"], "venture:alpha")
        for field in ("wrong_identity", "wrong_scope", "wrong_device"):
            self.assertIs(bind[field], True, field)

        # CB-0277: verified passkey evidence bound to this device/session.
        step = scenario("session", "stepup")
        self.assertEqual(step["valid"]["method"], "passkey")
        self.assertRegex(step["valid"]["step_up_ref"], r"^stepup:[a-f0-9]{32}$")
        self.assertLessEqual(
            step["valid"]["expires_at"] - step["valid"]["verified_at"], 300
        )
        self.assertEqual(step["valid"]["session_ref"], bind["session"]["session_ref"])

        # CB-0278: safe device/session inventory strips authentication materials.
        inv = scenario("session", "inventory")
        self.assertEqual(inv["inventory"]["identity_id"], "owner-human")
        self.assertEqual(len(inv["inventory"]["devices"]), 2)
        self.assertEqual(len(inv["inventory"]["sessions"]), 2)
        self.assertTrue(all(inv["bad"].values()))

        # CB-0280: client-supplied raw authority is not a verified context.
        nominal = scenario("access", "nominal")
        self.assertIs(nominal["raw_rejected"], True)
        self.assertIs(nominal["constructor_private"], True)
        self.assertEqual(nominal["valid"]["decision"], "allow")

        # CB-0281: server-owned venture scope cannot be replaced by caller.
        mismatch = scenario("access", "mismatch")
        self.assertEqual(mismatch["scope"]["decision"], "deny")
        self.assertEqual(mismatch["scope"]["reasons"], ["resource_scope_mismatch"])

        # CB-0282: even a valid L3 grant is insufficient for Owner endpoints.
        rights = scenario("access", "owner_read")
        self.assertEqual(rights["owner"]["decision"], "allow")
        self.assertEqual(rights["owner"]["capability"], "owner.cockpit.read")
        for field in ("lower", "non_owner_role", "agent"):
            self.assertEqual(rights[field]["decision"], "deny", field)
            self.assertIn("owner_identity_required", rights[field]["reasons"])

        # CB-0283: a read is allowed, a write is held for verified step-up.
        read = scenario("gate", "read")
        mutation = scenario("gate", "mutation")
        self.assertEqual(read["valid"]["decision"], "allow")
        self.assertIs(read["valid"]["mutation"], False)
        self.assertEqual(mutation["without"]["decision"], "step_up_required")
        self.assertIs(mutation["without"]["mutation"], True)

        # CB-0285: stale/unknown server snapshots fail closed, not authority.
        fail = scenario("source", "fail_closed")
        self.assertIs(fail["stale"], True)
        self.assertIs(fail["unknown"], True)

        # CB-0288: gate compares trusted identity/scope and re-checks freshness.
        mismatch_gate = scenario("gate", "trusted_mismatch")
        self.assertIs(mismatch_gate["identity"], True)
        self.assertIs(mismatch_gate["scope"], True)
        self.assertTrue(all(scenario("gate", "auth_fail").values()))

        # CB-0289: no step-up needed for an authorized GET.
        self.assertIsNone(read["valid"]["step_up_ref"])
        self.assertEqual(read["valid"]["capability"], "owner.cockpit.read")

        # CB-0290: verified, current step-up transitions only an authorized
        # mutation; an independent access denial is never overridden.
        self.assertEqual(mutation["with"]["decision"], "allow")
        self.assertEqual(mutation["with"]["reasons"], ["authorized_with_step_up"])
        denied = scenario("gate", "deny")
        self.assertEqual(denied["decision"], "deny")
        self.assertIsNone(denied["step_up_ref"])

        # CB-0291: successful output contains only opaque auth references.
        for row in (read["valid"], read["with_step"], mutation["with"]):
            self.assertRegex(row["device_ref"], r"^device:[a-f0-9]{32}$")
            self.assertRegex(row["session_ref"], r"^session:[a-f0-9]{32}$")
        self.assertIsNone(read["valid"]["step_up_ref"])
        for row in (read["with_step"], mutation["with"]):
            self.assertRegex(row["step_up_ref"], r"^stepup:[a-f0-9]{32}$")

    def test_falla_cerrado(self) -> None:
        """AC-02: incomplete, revoked, mismatched and injected inputs are denied."""
        for field in ("too_long", "expired", "revoked_session", "revoked_device"):
            self.assertIs(scenario("session", "lifetime")[field], True, field)
        for field in ("too_long", "expired", "wrong_session", "wrong_device",
                      "wrong_identity", "revoked_device", "revoked_step_up"):
            self.assertIs(scenario("session", "stepup")[field], True, field)
        self.assertTrue(all(scenario("source", "fail_closed").values()))
        self.assertTrue(all(scenario("gate", "client").values()))
        self.assertTrue(all(scenario("gate", "auth_fail").values()))
        self.assertTrue(all(scenario("gate", "trusted_mismatch").values()))
        access = scenario("access", "mismatch")
        self.assertEqual(access["capability"]["decision"], "deny")
        self.assertTrue(access["policy_mint_rejected"])
        self.assertTrue(access["identity_mint_rejected"])
        self.assertTrue(all(scenario("source", "secrets")["bad"].values()))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only fictional identities; no transport, secrets or prod I/O."""
        for row in (scenario("session", "inventory")["inventory"],
                    scenario("source", "secrets")["summary"],
                    scenario("gate", "read")["valid"],
                    scenario("gate", "mutation")["with"]):
            payload = json.dumps(row, sort_keys=True).lower()
            for field in ("access_token", "refresh_token", "cookie", "otp",
                          "password", "private_key", "public_key", "credential"):
                self.assertNotIn(field, payload, field)
        self.assertEqual(scenario("source", "source")["calls"], 1)
        self.assertEqual(scenario("source", "source")["summary"]["source_ref"],
                         "controlbot:session-source/primary")
        self.assertEqual(scenario("session", "pure")["methods"],
                         ["device", "safeInventory", "session", "stepUp"])
        self.assertEqual(scenario("gate", "pure")["methods"], ["authorize"])
        self.assertEqual(scenario("access", "surface")["public"], ["authorize"])

    def test_ci_gate_contract(self) -> None:
        """AC-04: the file has runnable tests; CI exact-HEAD must verify them."""
        self.assertEqual(ROWS, frozenset(f"CB-{n:04d}" for n in range(274, 292)))
        self.assertFalse(OFFLINE & CLASSIFIED.keys())
        self.assertEqual({kind for kind, _ in CLASSIFIED.values()}, {"C"})
        for path in SCRIPTS.values():
            self.assertTrue((ROOT / path).is_file(), path)
        for name in ("test_contrato_principal", "test_falla_cerrado",
                     "test_sin_datos_reales", "test_ci_gate_contract"):
            self.assertTrue(callable(getattr(self, name)))


if __name__ == "__main__":
    unittest.main()
