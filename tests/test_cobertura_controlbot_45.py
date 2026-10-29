#!/usr/bin/env python3
"""#1228: exercise real MOMENTUM PHP on synthetic, offline fixture scenarios.

CB-0738..0754 are executable pure-code contracts; CB-0755 is a governance
boundary (C), not a verified external financial/authority fact. Nothing in
this test reads or asserts the state of docs/matriz-cobertura*.md.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "experiment": "momentum_experiment_scenarios.php",
    "paid": "momentum_paid_media_scenarios.php",
    "performance": "momentum_performance_scenarios.php",
}
CASES = {
    "experiment": frozenset({
        "scope", "readiness", "result", "invalid", "deterministic", "pure",
    }),
    "paid": frozenset({
        "nominal", "authority", "mismatch", "freshness", "evidence", "pure",
    }),
    "performance": frozenset({
        "scope", "chain", "classification", "unit", "guardrails", "pure",
    }),
}
CODE_ROWS = frozenset({
    "CB-0738", "CB-0739", "CB-0740", "CB-0741", "CB-0742", "CB-0743",
    "CB-0744", "CB-0745", "CB-0746", "CB-0747", "CB-0748", "CB-0749",
    "CB-0750", "CB-0751", "CB-0752", "CB-0753", "CB-0754",
})
DOCUMENTARY = {
    "CB-0755": (
        "C", "Separation of operational financial authority among CAPITAL, "
        "Paid Media and Revenue is a governance boundary: a pure fixture "
        "cannot independently certify the external financial source of truth."
    ),
}
ALL_ROWS = CODE_ROWS | DOCUMENTARY.keys()
REF = re.compile(r"^[a-z][a-z-]*:[0-9a-f]{32}$")


@lru_cache(maxsize=24)
def php(domain: str, case: str) -> dict:
    """Call checked-in real-PHP scenario scripts with synthetic fixtures only."""
    if domain not in SCRIPTS or case not in CASES[domain]:
        raise AssertionError("Unapproved offline PHP scenario")
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("Actual PHP interpreter required")
    script = ROOT / "tests" / SCRIPTS[domain]
    if not script.is_file():
        raise AssertionError("Real PHP scenario script missing")
    forwarded = ("PATH", "LANG", "LC_ALL", "XDEBUG_MODE",
                 "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                 "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT")
    env = {key: os.environ[key] for key in forwarded if key in os.environ}
    result = subprocess.run(
        [executable, "-d", "allow_url_fopen=0", str(script), case],
        cwd=ROOT, env=env, text=True, capture_output=True,
        timeout=30, check=False,
    )
    # PHP fixtures may emit harmless engine warnings on stderr (e.g. a
    # global `use InvalidArgumentException`). Rely on exit status and a strict
    # JSON response; never echo raw stderr, which could contain private data.
    if result.returncode:
        raise AssertionError(f"Real PHP {domain}/{case} scenario failed")
    try:
        payload = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("Invalid real PHP evidence") from exc
    if not isinstance(payload, dict):
        raise AssertionError("Real PHP returned non-object evidence")
    return payload


def side_effect_free(module: str, forbidden: tuple[str, ...]) -> bool:
    """Inspect runtime source for forbidden side-effect *entry points*."""
    source = (ROOT / "src" / module).read_text(encoding="utf-8").lower()
    return all(token not in source for token in forbidden)


class CoberturaControlbot45Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: independent, real-PHP predicates for all executable rows."""
        self.assertEqual(len(ALL_ROWS), 18)
        exp_scope = php("experiment", "scope")
        exp_ready = php("experiment", "readiness")
        exp_result = php("experiment", "result")
        exp_invalid = php("experiment", "invalid")
        exp_order = php("experiment", "deterministic")
        paid_nominal = php("paid", "nominal")
        paid_auth = php("paid", "authority")
        paid_scope = php("paid", "mismatch")
        paid_time = php("paid", "freshness")
        paid_spend = php("paid", "evidence")
        perf_scope = php("performance", "scope")
        perf_chain = php("performance", "chain")
        perf_classes = php("performance", "classification")
        perf_unit = php("performance", "unit")
        perf_guard = php("performance", "guardrails")
        perf_pure = php("performance", "pure")

        baseline = exp_scope["valid"]
        obs = exp_result["observed"]["result"]
        inferred = exp_result["inferred"]["result"]
        unknown = exp_result["unknown"]["result"]
        paid = paid_nominal["ok"]
        current = perf_chain["full"]
        derived = current["derived"]
        spend = current["spend"]
        funnel = current["funnel"]
        cost = current["cost"]
        props = current["unit_economics"]

        # Each value below is computed from the return of real MOMENTUM PHP
        # methods. Static documentation and matrix statuses are never evidence.
        behaviors = {
            "CB-0738": (
                baseline["status"] == "ready"
                and baseline["window"] == {"start_at": 1200, "end_at": 2000}
                and baseline["baseline_ref"] not in baseline["variant_refs"]
                and exp_scope["cross_venture"] is True
                and all(exp_ready[key] is True for key in (
                    "missing_baseline", "missing_metric", "missing_window"))
                and all(exp_invalid[key] is True for key in (
                    "duplicate", "baseline_duplicate", "window", "foreign"))
            ),
            "CB-0739": (
                (obs["state"], inferred["state"], unknown["state"])
                == ("observed", "inferred", "unknown")
                and obs["effect_bps"] == 750
                and inferred["effect_bps"] == 500
                and obs["freshness"] == "current"
                and bool(obs["source_ref"]) and bool(obs["evidence_refs"])
                and exp_order["first"] == exp_order["second"]
            ),
            "CB-0740": (
                unknown == {
                    "state": "unknown", "winner_ref": None,
                    "effect_bps": None, "source_ref": None,
                    "observed_at": None, "freshness": "unknown",
                    "evidence_refs": [],
                } and all(exp_invalid[key] is True
                          for key in ("extra", "sensitive"))
            ),
            "CB-0741": (
                paid["status"] == "planned"
                and paid_nominal["array"]["status"] == "denied"
                and paid_nominal["object"]["status"] == "denied"
                and paid_scope["capability"]["status"] == "denied"
                and paid_scope["policy"]["status"] == "denied"
                and paid_auth["caller"]["reasons"] == ["invalid_input"]
                and all(paid_auth[key]["authority"]["required_authority_level"]
                        == "L2_VENTURE_ADMIN"
                        for key in ("launch_l1", "pause_l1", "reallocate_l1"))
            ),
            "CB-0742": (
                paid_time["current"]["status"] == "planned"
                and all(paid_time[key]["reasons"] == [reason]
                        for key, reason in {
                            "future": "evidence_from_future",
                            "expired": "evidence_expired",
                            "stale": "evidence_stale",
                            "unknown": "evidence_unknown",
                        }.items())
                and all(paid_time[key]["status"] == "denied"
                        for key in ("future", "expired", "stale", "unknown"))
            ),
            "CB-0743": (
                paid["venture_id"] == "venture-condor"
                and paid["spend"]["currency"] == "COP"
                and paid_spend["budget_mismatch"]["status"] == "denied"
                and paid_spend["capital_mismatch"]["status"] == "denied"
                and paid_spend["expand"]["status"] == "owner_decision_required"
                and "spend_expansion_requires_owner"
                    in paid_spend["expand"]["reasons"]
            ),
            "CB-0744": (
                perf_scope["ok"]["performance_id"] == current["performance_id"]
                and current["execution"] is False
                and current["paid_media_spend_ref"]
                    == current["spend"]["governed_spend_ref"]
                and perf_pure["methods"] == ["project"]
            ),
            "CB-0745": (
                perf_chain["planned"] == 2_000_000
                and spend["amount_minor"] == 2_500_000
                and derived["spend_minor"] == 2_500_000
                and perf_chain["unknown_spend"]["derived"]["spend_minor"] is None
                and perf_chain["unknown_spend"]["derived"]["margin_minor"] is None
            ),
            "CB-0746": (
                current["venture_id"] == "venture-condor"
                and current["currency"] == "COP"
                and current["period"] == {"start_at": 1000, "end_at": 2000}
                and all(perf_scope[key] is True for key in (
                    "venture", "campaign", "period", "paid_denied"))
                and perf_guard["currency"] is True
            ),
            "CB-0747": (
                spend["classification"] == "observed"
                and spend["amount_minor"] == 2_500_000
                and spend["currency"] == "COP"
                and spend["freshness"] == "current"
                and bool(REF.fullmatch(spend["source_ref"]))
                and bool(spend["evidence_refs"])
                and current["paid_media_spend_ref"] == spend["governed_spend_ref"]
            ),
            "CB-0748": (
                funnel["classification"] == "observed"
                and (funnel["leads"], funnel["conversions"]) == (100, 20)
                and funnel["freshness"] == "current"
                and bool(REF.fullmatch(funnel["source_ref"]))
                and bool(funnel["evidence_refs"])
            ),
            "CB-0749": (
                cost["classification"] == "observed"
                and cost["amount_minor"] == 1_000_000
                and cost["currency"] == "COP"
                and cost["freshness"] == "current"
                and bool(REF.fullmatch(cost["source_ref"]))
                and bool(cost["evidence_refs"])
            ),
            "CB-0750": (
                all(perf_guard[key] is True for key in (
                    "paid_stale", "currency", "duplicate", "missing",
                    "outside", "spend_ref"))
                and perf_guard["stale"]["freshness"] == "stale"
                and perf_guard["stale"]["derived"]["spend_minor"] is None
                and perf_guard["pipeline_stale"]["derived"]["spend_minor"] is None
            ),
            "CB-0751": (
                derived == {
                    "spend_minor": 2_500_000, "leads": 100, "conversions": 20,
                    "observed_revenue_minor": 9_000_000,
                    "inferred_revenue_minor": None,
                    "cost_minor": 1_000_000, "margin_minor": 5_500_000,
                }
                and props["cac_minor"] == 125_000
                and props["roas_milli"] == 3_600
                and perf_unit["zero"]["unit_economics"]["cac_minor"] is None
            ),
            "CB-0752": (
                perf_classes["inferred"]["derived"]["observed_revenue_minor"] is None
                and perf_classes["inferred"]["derived"]["inferred_revenue_minor"]
                    == 9_000_000
                and perf_classes["inferred"]["derived"]["margin_minor"] is None
                and perf_unit["inferred"]["unit_economics"]["roas_milli"] is None
                and perf_classes["unknown"]["derived"]["observed_revenue_minor"]
                    is None
            ),
            "CB-0753": (
                props["payback_days"] is None
                and props["payback_classification"] == "unknown"
                and props["ltv_minor"] is None
                and props["ltv_classification"] == "unknown"
            ),
            "CB-0754": (
                perf_pure["execution"] is False
                and php("experiment", "pure")["methods"] == ["experiment"]
                and php("paid", "pure")["methods"] == ["plan"]
                and perf_pure["methods"] == ["project"]
                and all(value["execution"] is False for value in (
                    baseline, paid, current))
                and side_effect_free("MomentumPerformance.php", (
                    "curl_", "pdo(", "mysqli", "factoryrunner", "scheduler",
                    "workitem", "decisionrights", "capitalpolicy", "provider",
                    "tracking", "email", "phone", "file_put_contents",
                ))
            ),
        }
        self.assertEqual(set(behaviors), CODE_ROWS)
        for identifier, proved in behaviors.items():
            with self.subTest(row=identifier):
                self.assertIs(proved, True, f"{identifier}: PHP behavior not proved")
        # The normative division of financial authority has no live receipt.
        self.assertEqual(DOCUMENTARY["CB-0755"][0], "C")
        self.assertGreater(len(DOCUMENTARY["CB-0755"][1]), 70)

    def test_falla_cerrado(self) -> None:
        """AC-02: real PHP rejects invalid, stale and unauthorized operations."""
        exp = php("experiment", "invalid")
        paid = php("paid", "mismatch")
        time = php("paid", "freshness")
        spend = php("paid", "evidence")
        scope = php("performance", "scope")
        perf = php("performance", "guardrails")
        for name, flag in exp.items():
            with self.subTest(experiment=name):
                self.assertIs(flag, True)
        for name in ("scope", "capability", "policy", "invalid"):
            with self.subTest(paid_scope=name):
                self.assertEqual(paid[name]["status"], "denied")
        self.assertEqual(paid["owner"]["status"], "owner_decision_required")
        for name in ("future", "expired", "stale", "unknown"):
            with self.subTest(stale=name):
                self.assertEqual(time[name]["status"], "denied")
        for name in ("duplicate", "secret", "budget_mismatch",
                     "capital_mismatch", "invalid_blast"):
            with self.subTest(capital=name):
                self.assertEqual(spend[name]["status"], "denied")
        for name in ("venture", "campaign", "period", "paid_denied"):
            with self.subTest(scope=name):
                self.assertIs(scope[name], True)
        for name in ("paid_stale", "currency", "duplicate", "missing",
                     "outside", "spend_ref"):
            with self.subTest(performance=name):
                self.assertIs(perf[name], True)
        for domain in ("missing", "unknown"):
            with self.assertRaises(AssertionError):
                php(domain, "unsafe")
        self.assertEqual(len(ALL_ROWS), 18)

    def test_sin_datos_reales(self) -> None:
        """AC-03: offline PHP fixtures, pure projection, no provider/live data."""
        self.assertEqual(set(SCRIPTS), {"experiment", "paid", "performance"})
        self.assertEqual(len(ALL_ROWS), 18)
        for script in SCRIPTS.values():
            path = ROOT / "tests" / script
            self.assertTrue(path.is_file())
            self.assertNotIn("https://", path.read_text(encoding="utf-8"))
        projection = php("performance", "pure")
        self.assertEqual(projection["methods"], ["project"])
        self.assertIs(projection["execution"], False)
        self.assertFalse(php("experiment", "scope")["valid"]["execution"])
        self.assertFalse(php("paid", "nominal")["ok"]["execution"])
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----",
                         json.dumps(projection))
        self.assertTrue(side_effect_free("MomentumExperiment.php", (
            "curl_", "factoryrunner", "scheduler", "traffic allocation",
            "spend(", "file_put_contents",
        )))


if __name__ == "__main__":
    unittest.main()
