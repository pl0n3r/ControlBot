#!/usr/bin/env python3
"""#1236: regression of real pause/resume, audit and discovery PHP contracts.

Only synthetic versioned scenarios exercise existing classes under src/.
Legal policy and human-only obligations remain uncredited, not simulated.
No coverage matrix, production endpoint, credentials or customer data is read.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "resume": "pause_resume_plan_scenarios.php",
    "audit": "pause_transition_audit_scenarios.php",
    "discovery": "product_discovery_assessment_scenarios.php",
}
PHP_DISABLE = (
    "curl_exec,curl_multi_exec,fsockopen,pfsockopen,"
    "stream_socket_client,exec,shell_exec,proc_open,passthru,system"
)
TESTABLE_ROWS = {
    "CB-0878", "CB-0879", "CB-0880", "CB-0882", "CB-0883",
    "CB-0884", "CB-0894", "CB-0895", "CB-0896",
}


@lru_cache(maxsize=24)
def scenario(module: str, case: str) -> dict:
    """Run a synthetic fixture through PHP production classes, never mock them."""
    if module not in SCENARIOS or case not in {
        "resume": {"valid", "drift", "state", "replay", "deterministic"},
        "audit": {"transition", "invalid", "append", "history", "secret"},
        "discovery": {
            "valid", "bindings", "classification", "unreliable",
            "numbers", "schema",
        },
    }[module]:
        raise ValueError("Unrecognized synthetic contract")
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("PHP CLI required for real contract regression")
    completed = subprocess.run(
        [
            binary, "-d", "allow_url_fopen=0",
            "-d", "disable_functions=" + PHP_DISABLE,
            str(ROOT / "tests" / SCENARIOS[module]), case,
        ],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
        timeout=30,
    )
    if completed.returncode or completed.stderr.strip():
        raise AssertionError(
            "PHP scenario failed: " + (completed.stderr or completed.stdout)[-320:]
        )
    return json.loads(completed.stdout)


class CoberturaControlbot53Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: each A row asserts behavior returned by actual PHP classes."""
        valid = scenario("resume", "valid")
        state = scenario("resume", "state")
        drift = scenario("resume", "drift")
        replay = scenario("resume", "replay")
        transition = scenario("audit", "transition")
        invalid = scenario("audit", "invalid")
        append = scenario("audit", "append")
        secret = scenario("audit", "secret")
        discovered = scenario("discovery", "valid")
        binding = scenario("discovery", "bindings")
        comparison = scenario("discovery", "numbers")
        unreliable = scenario("discovery", "unreliable")

        checks = {
            # PauseResumePlan::plan only allows currently assigned work,
            # exact reservation/owner and matching execution order.
            "CB-0878": (
                valid["work_item_id"] == "work-a"
                and valid["generation"] == 4
                and valid["attempt"] == 2
                and all(state.values())
                and all(drift.values())
            ),
            # Resume can reuse the attempt but grants no new authority.
            "CB-0879": (
                valid["order_id"] == "11111111-1111-4111-8111-111111111111"
                and valid["attempt_id"] == "22222222-2222-4222-8222-222222222222"
                and valid["runner_id"] == "33333333-3333-4333-8333-333333333333"
                and valid["issued_at"] == 110
                and valid["expires_at"] == 1000
                and not valid["create_work_item"]
                and not valid["create_order"]
                and valid["reuse_current_attempt"]
                and valid["requires_existing_authority"]
                and re.fullmatch(r"[0-9a-f]{64}", valid["order_fingerprint"]) is not None
            ),
            # Identical replay is allowed, payload drift even with a new
            # fingerprint is refused.
            "CB-0880": replay["exact"] and replay["conflict"],
            # PauseTransitionAudit::event binds immutable identity and
            # provenance and preserves before/after/actor/fingerprint.
            "CB-0882": (
                transition["same_create"]
                and transition["create"]["actor"] == "owner-main"
                and transition["create"]["source"] == "owner"
                and transition["create"]["reason"] == "Operational freeze"
                and transition["create"]["before_state"] is None
                and transition["create"]["after_state"] == "active"
                and transition["release"]["before_state"] == "active"
                and transition["release"]["after_state"] == "releasing"
                and all(invalid.values())
            ),
            # Append-only, idempotent and never overwrites a conflicting ID.
            "CB-0883": (
                append["count"] == 2
                and append["replay_same"]
                and append["conflict"]
                and append["chain_gap"]
                and append["history_provenance"]
                and append["tail_event"]["after_state"] == "releasing"
            ),
            # Secret/PII-looking synthetic inputs fail closed in event().
            "CB-0884": (
                all(secret[name] for name in (
                    "actor_secret", "actor_pii", "reason_secret",
                    "reason_pii", "evidence_pii",
                ))
                and secret["hits"] == []
            ),
            # Higher numeric delta is NOT a desirability/causal verdict.
            "CB-0894": (
                comparison["higher"]["delta"] == 5
                and comparison["higher"]["comparison"] == "higher"
                and comparison["higher"]["classification"] == "INVALIDATED"
                and comparison["lower"]["delta"] == -15
                and comparison["lower"]["classification"] == "VALIDATED"
            ),
            # The predeclared experiment/initiative/hypothesis/window/outcome
            # bindings are enforced by ProductDiscoveryAssessment::assess().
            "CB-0895": (
                all(binding.values())
                and discovered["experiment_ref"] == "experiment:" + "c" * 32
                and discovered["hypothesis_ref"] == "hypothesis:" + "b" * 32
                and discovered["primary_metric_ref"] == "metric:" + "d" * 32
                and discovered["outcome_id"] == "outcome-alpha"
            ),
            # Stale, inferred, unknown or zero-confidence evidence cannot
            # validate/ invalidate, even if the numeric delta is positive.
            "CB-0896": all(
                unreliable[reason + "_reject"]
                and unreliable[reason + "_ok"] == "INCONCLUSIVE"
                for reason in (
                    "stale", "inferred", "unknown", "zero_confidence",
                    "hypothesis_stale", "hypothesis_unknown",
                )
            ),
        }
        self.assertEqual(set(checks), TESTABLE_ROWS)
        for row, demonstrated in checks.items():
            with self.subTest(row=row):
                self.assertTrue(demonstrated, row + ": real PHP contract regression")

    def test_falla_cerrado(self):
        """AC-02: input drift and authority/provenance failures are rejected."""
        for module, case, keys in (
            ("resume", "drift", (
                "work_id", "generation", "attempt", "order_work",
                "pause_identity", "pause_provenance",
            )),
            ("resume", "state", ("done", "unowned", "unreleased")),
            ("audit", "invalid", (
                "identity", "scope", "provenance", "activation_drift",
                "safe_point_drift", "invalid_state", "invalid_creation",
                "future_evidence",
            )),
            ("audit", "append", (
                "conflict", "null_identity", "invalid_pair",
                "chain_gap", "second_creation", "history_provenance",
            )),
            ("audit", "history", ("regressive_new", "reordered_existing")),
            ("discovery", "bindings", (
                "experiment", "initiative", "hypothesis", "metric",
                "outcome", "outcome_experiment", "window",
            )),
            ("discovery", "classification", (
                "unknown_class", "empty_rule", "empty_evidence",
            )),
            ("discovery", "schema", (
                "duplicate", "extra", "bad_ref", "sensitive_text",
                "direct_pii_text",
            )),
        ):
            result = scenario(module, case)
            for field in keys:
                with self.subTest(module=module, case=case, field=field):
                    self.assertIs(result[field], True)

    def test_sin_datos_reales(self):
        """AC-03: fixtures operate offline and never grant production rights."""
        resume = scenario("resume", "deterministic")
        self.assertTrue(resume["same"])
        self.assertTrue(resume["fingerprint"])
        self.assertEqual(resume["hits"], [])
        self.assertFalse(scenario("resume", "valid")["create_work_item"])
        self.assertFalse(scenario("resume", "valid")["create_order"])

        audit = scenario("audit", "secret")
        self.assertEqual(audit["hits"], [])
        self.assertTrue(audit["reason_secret"])
        self.assertTrue(audit["actor_pii"])

        schema = scenario("discovery", "schema")
        self.assertEqual(schema["sorted"], sorted(schema["sorted"]))
        self.assertEqual(
            schema["numeric_opaque_ref"],
            "assessment:12345678901234567890123456789012",
        )
        self.assertTrue(schema["direct_pii_text"])
        numbers = scenario("discovery", "numbers")
        self.assertEqual(numbers["higher"]["confidence"], 0.9)
        self.assertEqual(numbers["higher"]["nature"], "observed")
        self.assertEqual(numbers["higher"]["freshness"], "fresh")


if __name__ == "__main__":
    unittest.main()
