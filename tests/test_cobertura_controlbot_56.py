#!/usr/bin/env python3
"""ControlBot #1239: exercise actual PHP behavior for CB-0935..CB-0946.

Synthetic aggregate fixtures are provided by the checked-in PHP scenario entrypoints.
Never read or assert the status of a coverage matrix; CI and deployment are separate.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "signals": "product_learning_signal_scenarios.php",
    "origin": "product_learning_work_origin_scenarios.php",
    "evaluation": "prompt_evaluation_scenarios.php",
}
CASES = {
    "signals": {"scope", "closed", "fail_closed", "reject_payload", "provenance", "pure"},
    "origin": {"scope", "explicit", "idempotency", "limited", "optional", "pure"},
    "evaluation": {"compatibility", "metrics", "safety", "keep", "deterministic",
                   "provenance", "substitution", "pure"},
}
IDS = frozenset(f"CB-{n:04d}" for n in range(935, 947))


@lru_cache(maxsize=32)
def php(family: str, case: str) -> dict:
    """Invoke repository PHP logic, not a Python model or a mock outcome."""
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unsupported PHP scenario")
    binary = shutil.which("php")
    script = ROOT / "tests" / SCRIPTS[family]
    if binary is None or not script.is_file():
        raise AssertionError("UNKNOWN: original PHP executable missing")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for variable in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                     "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE"):
        if variable in os.environ:
            env[variable] = os.environ[variable]
    with tempfile.TemporaryDirectory(prefix="controlbot-1239-") as home:
        env["HOME"] = home
        run = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,"
             "stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
             str(script), case],
            cwd=ROOT, env=env, text=True, capture_output=True, timeout=60,
            check=False,
        )
    if run.returncode or run.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: PHP {family}/{case} failed (exit={run.returncode}): "
            + run.stderr[-600:]
        )
    try:
        output = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP response is not JSON") from exc
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: PHP response is not an object")
    return output


class CoberturaControlbot56Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each ID proves executable behavior of its original PHP class."""
        scope = php("signals", "scope")
        closed = php("signals", "closed")
        fallback = php("signals", "fail_closed")
        rejects = php("signals", "reject_payload")
        provenance = php("signals", "provenance")
        origin_scope = php("origin", "scope")
        explicit = php("origin", "explicit")
        identity = php("origin", "idempotency")
        limited = php("origin", "limited")
        optional = php("origin", "optional")
        compatible = php("evaluation", "compatibility")
        metrics = php("evaluation", "metrics")
        safety = php("evaluation", "safety")
        keep = php("evaluation", "keep")
        ordered = php("evaluation", "deterministic")
        fingerprints = php("evaluation", "provenance")
        substitute = php("evaluation", "substitution")
        pure = php("evaluation", "pure")
        signal_pure = php("signals", "pure")
        origin_pure = php("origin", "pure")

        good = scope["good"]
        work = explicit["first"]
        changed = explicit["second"]
        comparison = compatible["valid"]
        fp = fingerprints["base"]
        assertions = {
            "CB-0935": (
                (good["venture_id"], good["product_id"], good["surface"])
                == ("venture-condor", "product-condor", "web_app")
                and good["source_kind"] == "metric"
                and good["aggregate_ref"] == "product-intelligence:metric/metric-activation"
                and scope["wrong_scope"] is True
                and provenance["experiment"]["source_kind"] == "experiment_outcome"
            ),
            "CB-0936": (
                closed["good"]["targets"] == ["capital", "discovery", "momentum"]
                and closed["duplicate"] is True and closed["bad_target"] is True
                and closed["bad_type"] is True
            ),
            "CB-0937": (
                (fallback["unknown"]["type"], fallback["unknown"]["status"])
                == ("evidence_gap", "unknown")
                and fallback["stale"]["freshness"] == "stale"
                and (fallback["inferred"]["type"], fallback["inferred"]["nature"])
                == ("inferred_change", "inferred")
                and fallback["inferred"]["confidence"] == 0.55
                and (fallback["experiment"]["type"], fallback["experiment"]["status"])
                == ("experiment_result", "inconclusive")
            ),
            "CB-0938": (
                set(rejects) == {"message", "action", "priority", "members",
                                 "event_payload", "user_id"}
                and all(value is True for value in rejects.values())
                and not set(good) & {"message", "action", "priority", "members",
                                     "event_payload", "user_id", "executor"}
            ),
            "CB-0939": (
                (work["work_type"], work["priority_class"], work["authority_level"])
                == ("product", "high", "operational")
                and changed["priority_class"] == "medium"
                and changed["authority_level"] == "owner"
                and changed["requested_capabilities"] == ["analysis"]
                and changed["required_roles"] == ["datos-analitica"]
                and changed["policy_ref"] == "factory:policy-product"
                and identity["a"]["idempotency_key"] == identity["b"]["idempotency_key"]
                and identity["a"]["evidence_refs"] == sorted(identity["a"]["evidence_refs"])
                and origin_scope["metric"]["origin_system"] == "controlbot"
                and origin_scope["experiment"]["origin_mode"] == "automatic"
            ),
            "CB-0940": (
                limited["missing_time"] is True
                and all(item["observed_at"] == "2026-09-29T14:00:00Z"
                        for item in (limited["unknown"], limited["stale"]))
                and all(not set(item) & {"ready", "approved", "authorized", "readiness"}
                        for item in (limited["unknown"], limited["stale"]))
                and optional["bad_execution_field"] is True
            ),
            "CB-0941": (
                comparison["template_id"] == "support-agent"
                and comparison["current_version"] == 1
                and comparison["candidate_version"] == 2
                and compatible["task"] is True and compatible["set"] is True
                and compatible["older_candidate"] is True
                and all(metrics[k] is True for k in ("unknown", "shape", "sample"))
            ),
            "CB-0942": (
                comparison["decision"] == "candidate_better"
                and "candidate_dominates_current" in comparison["reasons"]
                and keep["tie"]["decision"] == "keep_current"
                and "tie_keep_current" in keep["tie"]["reasons"]
                and keep["small"]["decision"] == "keep_current"
                and "insufficient_evidence" in keep["small"]["reasons"]
                and ordered["same"] is True
            ),
            "CB-0943": (
                fp["current_result_fingerprint"] == fingerprints["current_helper"]
                and fp["candidate_result_fingerprint"] == fingerprints["candidate_helper"]
                and all(len(fp[field]) == 64 for field in
                        ("current_result_fingerprint", "candidate_result_fingerprint",
                         "fingerprint"))
                and all(fingerprints[key]["candidate_result_fingerprint"]
                        != fp["candidate_result_fingerprint"]
                        for key in ("metric", "safety", "policy"))
            ),
            "CB-0944": (
                substitute["evaluation_changed"] is True
                and substitute["current_stable"] is True
                and substitute["candidate_changed"] is True
                and substitute["old_candidate_rejects_substitute"] is True
            ),
            "CB-0945": (
                comparison["authority"] == "advisory_only"
                and all(safety[k]["decision"] == "keep_current"
                        and safety[k]["authority"] == "advisory_only"
                        and "candidate_safety_or_policy_failed" in safety[k]["reasons"]
                        for k in ("unsafe", "policy"))
            ),
            "CB-0946": (
                pure["hits"] == [] and pure["authority"] == "advisory_only"
                and pure["decision"] == "candidate_better"
                and signal_pure["methods"] == ["fromExperimentOutcome", "fromMetric"]
                and origin_pure["methods"] == ["fromExperimentOutcome", "fromMetric"]
                and optional["bad_execution_field"] is True
            ),
        }
        self.assertEqual(set(assertions), IDS)
        for ident, passed in assertions.items():
            with self.subTest(coverage_row=ident):
                self.assertTrue(passed, f"{ident}: PHP behavior contract not satisfied")

    def test_falla_cerrado(self) -> None:
        """AC-02: no inference of authority or quality from invalid evidence."""
        for case in ("duplicate", "bad_target", "bad_type"):
            with self.subTest(signal=case):
                self.assertIs(php("signals", "closed")[case], True)
        for value in php("signals", "reject_payload").values():
            self.assertIs(value, True)
        for case in ("task", "set", "older_candidate"):
            self.assertIs(php("evaluation", "compatibility")[case], True)
        for case in ("unknown", "shape", "sample"):
            self.assertIs(php("evaluation", "metrics")[case], True)
        self.assertIs(php("origin", "limited")["missing_time"], True)
        self.assertIs(php("origin", "optional")["bad_execution_field"], True)
        self.assertEqual(php("evaluation", "keep")["small"]["decision"], "keep_current")
        for choice in ("unsafe", "policy"):
            self.assertEqual(php("evaluation", "safety")[choice]["decision"], "keep_current")

    def test_sin_datos_reales(self) -> None:
        """AC-03: only synthetic aggregates; no customer payloads or privileges."""
        output = php("signals", "scope")["good"]
        self.assertEqual(output["venture_id"], "venture-condor")
        self.assertEqual(output["source_ref"], "aggregate:analytics/product")
        self.assertTrue(output["evidence_ref"].startswith("evidence:product/"))
        for item in (output, php("origin", "scope")["metric"],
                     php("evaluation", "compatibility")["valid"]):
            self.assertFalse(set(item) & {
                "email", "password", "password_hash", "api_key", "access_token",
                "members", "member_id", "event_payload", "personal_data",
                "execution", "executor", "approved", "authorized", "ready",
            })
        self.assertEqual(php("evaluation", "pure")["authority"], "advisory_only")


if __name__ == "__main__":
    unittest.main()
