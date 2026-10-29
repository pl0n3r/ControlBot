#!/usr/bin/env python3
"""#1237: verify existing Product Discovery PHP behavior for CB-0898..CB-0915.

Only checked-in PHP scenarios and synthetic in-memory calls are executed.
No coverage-matrix status is consulted or frozen into a test expectation.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "core": "product_discovery_scenarios.php",
    "decision": "product_discovery_decision_scenarios.php",
    "experiment": "product_discovery_experiment_scenarios.php",
}
CASES = {
    "core": frozenset({"initiative", "hypothesis", "freshness", "closed", "pure"}),
    "decision": frozenset({"bindings", "catalog", "build", "gates", "nonbuild", "schema"}),
    "experiment": frozenset({"bind", "declared", "scope", "invalid", "deterministic", "pure"}),
}
# Domain/architecture statements cannot be established as remote operational truth.
NON_EXECUTABLE = {
    "CB-0904": "C: boundary with Market Scope and future slices is architectural policy",
    "CB-0905": "C: rejected design alternative, not an executable behavior",
    "CB-0906": "C: historical assertion about Issue #240 test coverage, not new product behavior",
    "CB-0911": "C: no WorkItem, provider, persistence or gate execution is an architectural limit",
    "CB-0912": "C: purpose and history of experimental planning in Issue #183",
}
EXECUTABLE = frozenset(
    f"CB-{i:04d}"
    for i in (898, 899, 900, 901, 902, 903, 907, 908, 909, 910, 913, 914, 915)
)

# Extends the checked-in PHP scenarios only for edge cases the scenarios omit.
# Every behavior below calls the shipping ProductDiscovery/Experiment classes.
PROBE = r"""
require 'src/ProductDiscovery.php';
require 'src/ProductDiscoveryExperiment.php';
use ControlBot\Business\ProductDiscovery;
use ControlBot\Business\ProductDiscoveryExperiment;
function r(string $ns,string $hex='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): string {
 return $ns.':'.$hex;
}
function denied(callable $f): bool {
 try {$f(); return false;} catch (InvalidArgumentException) {return true;}
}
$i=[
 'version'=>1,'initiative_id'=>r('initiative'),'scope'=>'group','venture_id'=>null,
 'market_ref'=>null,'problem_ref'=>r('problem'),'segment_ref'=>r('segment'),
 'evidence_refs'=>[],'source_ref'=>r('source'),'freshness'=>'fresh',
 'confidence'=>'medium','state'=>'HYPOTHESIS','responsible_ref'=>r('responsible'),
];
$h=[
 'version'=>1,'hypothesis_ref'=>r('hypothesis'),'initiative_id'=>r('initiative'),
 'expected_outcome_ref'=>r('outcome'),'primary_metric_ref'=>r('metric'),
 'constraint_refs'=>[],'evidence_refs'=>[r('evidence')],
 'source_ref'=>r('source'),'freshness'=>'fresh','confidence'=>'medium',
];
$p=[
 'version'=>1,'experiment_ref'=>r('experiment'),'initiative_id'=>r('initiative'),
 'hypothesis_ref'=>r('hypothesis'),'kind'=>'prototype','primary_metric_ref'=>r('metric'),
 'evaluation_window'=>['start_at'=>15,'end_at'=>30],
 'validation_cost_ref'=>r('cost'),'state'=>'RUNNING',
];
$confidences=[];
foreach (['low','medium','high','unknown'] as $confidence) {
 $sample=$i;
 $sample['confidence']=$confidence;
 $sample['freshness']=$confidence==='unknown'?'unknown':'fresh';
 $normalized=ProductDiscovery::initiative($sample);
 $confidences[$confidence]=[$normalized['freshness'],$normalized['confidence']];
}
$empty=$h; $empty['evidence_refs']=[];
$invalidConfidence=$i; $invalidConfidence['confidence']='certain';
$unknownHigh=$i; $unknownHigh['freshness']='unknown'; $unknownHigh['confidence']='high';
$staleHigh=$h; $staleHigh['freshness']='stale'; $staleHigh['confidence']='high';
$untrusted=$p; $untrusted['factory_handoff_ready']=true;
$running=ProductDiscoveryExperiment::plan($p,$i,$h);
echo json_encode([
 'confidences'=>$confidences,
 'empty_evidence_rejected'=>denied(fn()=>ProductDiscovery::hypothesis($empty,$i)),
 'invalid_confidence_rejected'=>denied(fn()=>ProductDiscovery::initiative($invalidConfidence)),
 'unknown_high_rejected'=>denied(fn()=>ProductDiscovery::initiative($unknownHigh)),
 'stale_high_rejected'=>denied(fn()=>ProductDiscovery::hypothesis($staleHigh,$i)),
 'self_certified_experiment_rejected'=>denied(
  fn()=>ProductDiscoveryExperiment::plan($untrusted,$i,$h)
 ),
 'running'=>$running
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


def run_php(args: list[str]) -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI is required")
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "LANG": "C",
    }
    # Preserve only the local test runner instrumentation; never pass tokens.
    for key in (
        "CONTROLBOT_REAL_PHP",
        "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR",
        "CONTROLBOT_REPO_ROOT",
        "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1237-") as home:
        env["HOME"] = home
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", *args],
            cwd=ROOT, env=env, text=True, capture_output=True,
            timeout=60, check=False,
        )
    if result.returncode or result.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: local PHP failed (exit={result.returncode}): "
            + result.stderr[-800:]
        )
    try:
        output = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: local PHP did not return valid JSON") from exc
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: local PHP did not return an object")
    return output


@lru_cache(maxsize=32)
def php(family: str, case: str) -> dict:
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unsupported scenario")
    script = ROOT / "tests" / SCRIPTS[family]
    if not script.is_file():
        raise AssertionError("UNKNOWN: checked-in scenario missing")
    return run_php([str(script), case])


@lru_cache(maxsize=1)
def probe() -> dict:
    return run_php(["-r", PROBE])


class CoberturaControlbot54Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: row-level predicates exercise real PHP functions."""
        core = php("core", "initiative")
        hyp = php("core", "hypothesis")
        fresh = php("core", "freshness")
        closed = php("core", "closed")
        extension = probe()
        build = php("decision", "build")
        catalog = php("decision", "catalog")
        gates = php("decision", "gates")
        nonbuild = php("decision", "nonbuild")
        schema = php("decision", "schema")
        binding = php("decision", "bindings")
        experiment = php("experiment", "bind")
        declared = php("experiment", "declared")
        scope = php("experiment", "scope")
        invalid = php("experiment", "invalid")

        positive = build["validated"]
        plan = experiment["good"]
        expected_window = {"start_at": 1000, "end_at": 2000}
        predicates = {
            "CB-0898": (
                (core["venture"]["scope"], core["venture"]["venture_id"])
                == ("venture", "venture-condor")
                and (core["group"]["scope"], core["group"]["venture_id"],
                     core["group"]["market_ref"]) == ("group", None, None)
                and core["bad_group_scope"] and core["bad_venture_scope"]
                and re.fullmatch(r"problem:[a-f0-9]{32}", core["venture"]["problem_ref"])
                is not None
            ),
            "CB-0899": (
                hyp["good"]["initiative_id"] == core["venture"]["initiative_id"]
                and hyp["good"]["expected_outcome_ref"].startswith("outcome:")
                and hyp["good"]["primary_metric_ref"].startswith("metric:")
                and hyp["good"]["constraint_refs"] == sorted(hyp["good"]["constraint_refs"])
                and hyp["missing_outcome"] and hyp["missing_metric"]
                and hyp["wrong_initiative"]
            ),
            "CB-0900": extension["confidences"] == {
                "low": ["fresh", "low"], "medium": ["fresh", "medium"],
                "high": ["fresh", "high"], "unknown": ["unknown", "unknown"],
            } and extension["invalid_confidence_rejected"],
            "CB-0901": (
                (fresh["unknown"]["freshness"], fresh["unknown"]["confidence"])
                == ("unknown", "unknown")
                and fresh["unknown_high"] and fresh["unknown_medium_hypothesis"]
                and extension["unknown_high_rejected"]
            ),
            "CB-0902": (
                (fresh["stale"]["freshness"], fresh["stale"]["confidence"])
                == ("stale", "medium")
                and fresh["stale_high"] and extension["stale_high_rejected"]
            ),
            "CB-0903": (
                core["group"]["evidence_refs"] == []
                and not core["has_repo"]
                and len(hyp["good"]["evidence_refs"]) >= 1
                and extension["empty_evidence_rejected"]
            ),
            "CB-0907": (
                positive["classification"] == "VALIDATED"
                and schema["valid"]["classification"] == "INVALIDATED"
                and all(binding.values())
                and schema["extra"]
            ),
            "CB-0908": (
                [catalog[k] for k in
                 ("build", "iterate", "park", "stop", "research_more")]
                == ["BUILD", "ITERATE", "PARK", "STOP", "RESEARCH_MORE"]
                and catalog["invalid"]
                and build["invalidated_reject"] and build["inconclusive_reject"]
                and build["validated_not_inferred"] == "ITERATE"
            ),
            "CB-0909": (
                gates["build"]["required_gates"]
                == ["authority", "budget", "aegis", "lex"]
                and gates["build"]["factory_handoff_ready"] is False
                and gates["build"]["execution"] is False
                and gates["self_certify"]
                and all(row["required_gates"] == [] and
                        row["factory_handoff_ready"] is False and
                        row["execution"] is False for row in nonbuild.values())
            ),
            "CB-0910": (
                positive["venture_id"] == "venture-alpha"
                and positive["product_id"] == "product-alpha"
                and positive["assessment_rule_ref"].startswith("rule:")
                and positive["assessment_evidence_refs"]
                == sorted(positive["assessment_evidence_refs"])
                and positive["evaluation_window"] == {"start_at": 100, "end_at": 200}
                and positive["freshness"] == "fresh"
                and positive["confidence"] is not None
                and positive["source_ref"] and positive["evidence_ref"]
                and schema["deterministic"] and schema["sorted"] == sorted(schema["sorted"])
            ),
            "CB-0913": (
                plan["initiative_id"] == "initiative:" + "1" * 32
                and plan["hypothesis_ref"] == "hypothesis:" + "1" * 32
                and plan["primary_metric_ref"] == "metric:" + "1" * 32
                and experiment["initiative"] and experiment["hypothesis"]
                and experiment["metric"] and invalid["parked"]
            ),
            "CB-0914": (
                declared["good"]["kind"] == "landing_test"
                and declared["good"]["evaluation_window"] == expected_window
                and declared["reversed_window"]["evaluation_window"] == expected_window
                and re.fullmatch(r"cost:[a-f0-9]{32}",
                                 declared["good"]["validation_cost_ref"]) is not None
                and declared["bad_kind"] and declared["bad_window"]
                and declared["bad_cost"] and extension["running"]["state"] == "RUNNING"
                and extension["running"]["execution"] is False
            ),
            "CB-0915": (
                (scope["venture"]["scope"], scope["venture"]["venture_id"])
                == ("venture", "venture-condor")
                and (scope["group"]["scope"], scope["group"]["venture_id"],
                     scope["group"]["market_ref"]) == ("group", None, None)
                and plan["responsible_ref"] == "responsible:" + "1" * 32
                and plan["expected_outcome_ref"] == "outcome:" + "1" * 32
                and (scope["stale"]["hypothesis_freshness"],
                     scope["stale"]["hypothesis_confidence"]) == ("stale", "medium")
                and (scope["unknown"]["hypothesis_freshness"],
                     scope["unknown"]["hypothesis_confidence"]) == ("unknown", "unknown")
                and "repository" not in plan and "project_ref" not in plan
            ),
        }
        self.assertEqual(
            EXECUTABLE | NON_EXECUTABLE.keys(),
            {f"CB-{i:04d}" for i in range(898, 916)},
        )
        self.assertFalse(EXECUTABLE & NON_EXECUTABLE.keys())
        self.assertEqual(set(predicates), EXECUTABLE)
        for row, matches in predicates.items():
            with self.subTest(row=row):
                self.assertTrue(matches, "Code behavior not verified: " + row)

    def test_falla_cerrado(self) -> None:
        """AC-02: bad/missing evidence, bindings, scope and authority deny."""
        core = php("core", "closed")
        for key in (
            "extra", "bad_ref", "duplicate_evidence", "bad_scope",
            "duplicate_constraints", "extra_hypothesis",
        ):
            with self.subTest(core=key):
                self.assertIs(core[key], True)
        for key in ("missing_outcome", "missing_metric", "wrong_initiative"):
            self.assertIs(php("core", "hypothesis")[key], True)
        for key in ("stale_high", "unknown_high", "unknown_medium_hypothesis"):
            self.assertIs(php("core", "freshness")[key], True)
        for key, value in php("decision", "bindings").items():
            with self.subTest(binding=key):
                self.assertIs(value, True)
        for key in ("invalid", "reason", "empty_evidence", "duplicate"):
            self.assertIs(php("decision", "catalog")[key], True)
        self.assertTrue(php("decision", "gates")["self_certify"])
        self.assertTrue(php("decision", "schema")["extra"])
        for key in ("initiative", "hypothesis", "metric"):
            self.assertIs(php("experiment", "bind")[key], True)
        for key in ("extra", "bad_state", "parked", "bad_ref", "zero_window"):
            self.assertIs(php("experiment", "invalid")[key], True)
        for key in ("bad_kind", "bad_window", "bad_cost"):
            self.assertIs(php("experiment", "declared")[key], True)
        for key in (
            "empty_evidence_rejected", "invalid_confidence_rejected",
            "unknown_high_rejected", "stale_high_rejected",
            "self_certified_experiment_rejected",
        ):
            self.assertIs(probe()[key], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: deterministic offline PHP and opaque refs; no live or secrets."""
        self.assertEqual(len(SCRIPTS), 3)
        self.assertEqual(php("core", "pure")["methods"], ["hypothesis", "initiative"])
        self.assertEqual(php("experiment", "pure")["methods"], ["plan"])
        self.assertEqual(php("experiment", "deterministic")["a"],
                         php("experiment", "deterministic")["b"])
        self.assertFalse(php("experiment", "deterministic")["a"]["execution"])
        self.assertFalse(php("decision", "schema")["valid"]["execution"])
        for result in (php("core", "initiative")["venture"],
                       php("experiment", "scope")["group"],
                       php("decision", "build")["validated"]):
            payload = json.dumps(result)
            self.assertNotIn("@", payload)
            self.assertNotIn("password", payload.lower())
            self.assertNotIn("bearer ", payload.lower())
            self.assertNotIn("repository", result)
        code = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                self.assertFalse(
                    {part.name.split(".")[0] for part in node.names}
                    & {"socket", "requests", "urllib", "httpx", "pymysql"},
                )
        self.assertNotIn("matriz" + "-cobertura", code)


if __name__ == "__main__":
    unittest.main()
