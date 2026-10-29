#!/usr/bin/env python3
"""#1198: real mobile-state and mobile-audit coverage with synthetic fixtures."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROWS = frozenset(f"CB-{number:04d}" for number in range(221, 239))
# C: documentary scope/limit, not separately executable behavior.
CLASSIFIED = {
    "CB-0221": ("C", "Contract purpose and non-authorization scope, not a separate operation."),
    "CB-0238": ("C", "Persistence, retention and operational export are outside this slice."),
}
OFFLINE = ROWS - CLASSIFIED.keys()
STATE = "tests/external_api_mobile_state_scenarios.php"
AUDIT = "tests/external_api_mobile_audit_scenarios.php"


def php_case(script: str, case: str) -> dict:
    """Call real PHP implementations through deterministic, offline scenarios."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI is unavailable")
    run = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(ROOT / script), case],
        cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
    )
    if run.returncode:
        raise AssertionError(f"PHP {script}:{case} failed: {run.stderr[-600:]}")
    return json.loads(run.stdout)


class CoberturaControlbot15Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(ROWS), 18)
        self.assertEqual(len(OFFLINE), 16)
        self.assertEqual(OFFLINE | CLASSIFIED.keys(), ROWS)
        self.assertFalse(OFFLINE & CLASSIFIED.keys())

        states = php_case(STATE, "states")
        reads = php_case(STATE, "reads")
        mutation = php_case(STATE, "mutation")
        queue = php_case(STATE, "queue")
        cache = php_case(STATE, "cache")

        # CB-0222: old connected snapshot is stale, not current.
        self.assertEqual(states["stale"]["state"], "stale")
        self.assertIs(states["stale"]["current"], False)
        # CB-0223: disconnected snapshot with valid provenance is offline.
        self.assertEqual(states["offline"]["state"], "offline")
        self.assertIs(states["offline"]["current"], False)
        # CB-0224: absent temporal provenance results in unknown.
        self.assertEqual(states["unknown"]["state"], "unknown")
        self.assertIsNone(states["unknown"]["age_seconds"])
        # CB-0225: provenance must be fully present or fully absent.
        self.assertIs(states["partial"], True)
        # CB-0226: stale/offline reads preserve timestamp, source and age.
        self.assertEqual(reads["stale"]["age_seconds"], 401)
        self.assertEqual(reads["stale"]["observed_at"], 1000)
        self.assertEqual(reads["stale"]["source_ref"], "controlbot:cockpit/group-health")
        self.assertEqual(reads["offline"]["age_seconds"], 200)
        self.assertEqual(reads["offline"]["state"], "offline")
        self.assertIs(reads["offline"]["current"], False)
        # CB-0227: policy reports freshness and queueability, not authorization.
        self.assertEqual(mutation["fresh"]["freshness_gate"], "pass")
        self.assertEqual(mutation["fresh"]["operation_id"], "owner.note.ack")
        self.assertNotIn("authorized", mutation["fresh"])
        self.assertNotIn("executed", mutation["fresh"])
        # CB-0228: every non-fresh state fails the gate.
        for name in ("stale", "offline_stepup", "offline_high", "unknown"):
            with self.subTest(state=name):
                self.assertEqual(mutation[name]["freshness_gate"], "fail")
        # CB-0229: step-up and high authority preclude offline queueing.
        self.assertIs(mutation["offline_stepup"]["queueable"], False)
        self.assertIs(mutation["offline_high"]["queueable"], False)
        self.assertIn("step_up_required", mutation["offline_stepup"]["reasons"])
        self.assertIn("high_authority", mutation["offline_high"]["reasons"])
        # CB-0230: queueability requires idempotency and explicit allowlist.
        self.assertIs(queue["queueable"]["queueable"], True)
        self.assertEqual(queue["queueable"]["snapshot_state"], "offline")
        self.assertIs(queue["not_listed"]["queueable"], False)
        self.assertIs(queue["not_idempotent"]["queueable"], False)
        self.assertIs(queue["fresh"]["queueable"], False)
        # CB-0231: confidential persistent cache requires encryption.
        self.assertIs(cache["conf_plain"]["persistent_cache_allowed"], False)
        self.assertIs(cache["conf_encrypted"]["persistent_cache_allowed"], True)
        self.assertIs(cache["restricted"]["persistent_cache_allowed"], False)

        derived = php_case(AUDIT, "derived")
        trace = php_case(AUDIT, "trace")
        rejected = php_case(AUDIT, "authz")
        refs = php_case(AUDIT, "refs")
        # CB-0232: verified owner identity and authority context.
        self.assertEqual(derived["identity_id"], "identity-owner")
        self.assertEqual(derived["authority_level"], "L4_OWNER")
        # CB-0233: audit carries request and correlation IDs and outcome.
        self.assertEqual(trace["request_id"], "a" * 32)
        self.assertEqual(trace["correlation_id"], "b" * 32)
        self.assertEqual(trace["occurred_at"], 9000)
        self.assertEqual(trace["outcome"], "read_served")
        # CB-0234: client-facing visibility is never permission.
        self.assertEqual(rejected["horizontal"], "verified authentication context mismatch.")
        self.assertEqual(rejected["vertical"], "audit outcome requires authorized request.")
        # CB-0235: scope, capability, grant and policy are server-resolved.
        self.assertEqual(derived["capability"], "owner.cockpit.read")
        self.assertEqual(derived["scope"], "venture:alpha")
        self.assertEqual(derived["grant_id"], "grant-owner-api")
        self.assertEqual(derived["policy_refs"], ["controlbot:policy/external-owner-v1"])
        # CB-0236: audit re-evaluates the Request Gate, including denials.
        self.assertEqual(derived["authorization_decision"], "allow")
        self.assertEqual(derived["authorization_reasons"], ["authorized"])
        self.assertEqual(rejected["vertical"], "audit outcome requires authorized request.")
        # CB-0237: handoff ref is not a verification or execution result.
        self.assertEqual(refs["handoff"]["outcome"], "factory_handoff")
        self.assertEqual(refs["handoff"]["work_item_ref"], "controlbot:work/item-abc")
        self.assertIsNone(refs["handoff"]["result_ref"])
        self.assertNotEqual(refs["handoff"]["outcome"], refs["verified"]["outcome"])

    def test_falla_cerrado(self) -> None:
        states = php_case(STATE, "states")
        self.assertIs(states["partial"], True)
        self.assertIs(states["unknown"]["current"], False)
        for field in ("observed_at", "received_at", "source_ref"):
            self.assertIsNone(states["unknown"][field])
        queue = php_case(STATE, "queue")
        self.assertIs(queue["not_listed"]["queueable"], False)
        self.assertIs(queue["not_idempotent"]["queueable"], False)
        cache = php_case(STATE, "cache")
        self.assertIs(cache["sensitive_ref"], True)
        self.assertIs(cache["extra_sensitive"], True)
        denied = php_case(AUDIT, "authz")
        self.assertIn("mismatch", denied["horizontal"])
        self.assertIn("authorized request", denied["vertical"])
        refs = php_case(AUDIT, "refs")
        for key in ("missing_decision", "missing_work", "missing_result",
                    "read_with_work", "handoff_with_result"):
            with self.subTest(ref=key):
                self.assertIs(refs[key], True)
        sensitive = php_case(AUDIT, "sensitive")
        self.assertIs(sensitive["extra"], True)
        self.assertIs(sensitive["secret_ref"], True)

    def test_sin_datos_reales(self) -> None:
        # Reflection inspects actual PHP class methods; no provider or network.
        self.assertEqual(php_case(STATE, "pure")["methods"],
                         ["cachePolicy", "mutationPolicy", "snapshot"])
        self.assertEqual(php_case(AUDIT, "pure")["methods"], ["event"])
        evidence = php_case(AUDIT, "sensitive")["event"]
        self.assertEqual(evidence["identity_id"], "identity-owner")
        self.assertEqual(evidence["request_id"], "a" * 32)
        for key in ("password", "token", "cookie", "ip_address", "user_agent",
                    "fingerprint", "request_payload", "response_payload"):
            self.assertNotIn(key, evidence)
        self.assertTrue(all(cat == "C" and reason for cat, reason in CLASSIFIED.values()))
        self.assertFalse({"requests", "urllib", "socket", "httpx"} & set(globals()))


if __name__ == "__main__":
    unittest.main()
