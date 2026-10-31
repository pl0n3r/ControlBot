#!/usr/bin/env python3
"""ControlBot #1068: quince brechas CB-0937..0951 con PHP sintético.

La cobertura local no acredita Factory, aprobación humana, despliegue o live.
No cambia ni promueve las filas NO_VERIFICABLE de la matriz.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_e4_coverage_row, corrupted_e4_row

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
PARTIAL = "CONTRATO_LOCAL"
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{n:04d}" for n in range(937, 952))
DOCS = {
    "learning": "docs/product-learning-signals.md",
    "origin": "docs/product-learning-work-origin.md",
    "evaluation": "docs/prompt-evaluation-v1.md",
    "promotion": "docs/prompt-promotion-decision-v1.md",
}
CODE = {
    "learning": "src/ProductLearningSignal.php",
    "origin": "src/ProductLearningWorkOrigin.php",
    "evaluation": "src/PromptEvaluation.php",
    "promotion": "src/PromptPromotionDecision.php",
    "registry": "src/PromptRegistry.php",
}
SCRIPTS = {
    "learning": "product_learning_signal_scenarios.php",
    "origin": "product_learning_work_origin_scenarios.php",
    "evaluation": "prompt_evaluation_scenarios.php",
    "promotion": "prompt_promotion_decision_scenarios.php",
}
CLAUSES = {
    "CB-0937": ("learning", "Una métrica unknown/insufficient produce"),
    "CB-0938": ("learning", "El contrato cerrado no admite mensaje"),
    "CB-0939": ("origin", "El caller/policy debe aportar explícitamente"),
    "CB-0940": ("origin", "Unknown, inconclusive o stale pueden originar intención"),
    "CB-0941": ("evaluation", "Un EvaluationSet fija"),
    "CB-0942": ("evaluation", "La comparación es estricta y fail-closed."),
    "CB-0943": ("evaluation", "resultFingerprint()"),
    "CB-0944": ("evaluation", "Esto permite que consumidores read-only verifiquen"),
    "CB-0945": ("evaluation", "safety_result=fail"),
    "CB-0946": ("evaluation", "Sin DB, red, shell, filesystem write"),
    "CB-0947": ("promotion", "convierte evidencia de"),
    "CB-0948": ("promotion", "valida todo el historial"),
    "CB-0949": ("promotion", "Solo puede resultar"),
    "CB-0950": ("promotion", "la evaluación conserva"),
    "CB-0951": ("promotion", "contiene evidencia"),
}
MARKERS = {
    "learning": ("final class ProductLearningSignal", "function fromMetric(", "function fromExperimentOutcome("),
    "origin": ("final class ProductLearningWorkOrigin", "private const REQUIRED=", "'observed_at'"),
    "evaluation": ("final class PromptEvaluation", "function compare(", "function resultFingerprint("),
    "promotion": ("final class PromptPromotionDecision", "function decide(", "PromptRegistry::active("),
    "registry": ("final class PromptRegistry", "function active(", "function history("),
}
FILES = {MAIN, PART4, *DOCS.values(), *CODE.values(), *("tests/" + x for x in SCRIPTS.values())}
CASES = {
    "learning": ("fail_closed", "reject_payload", "closed", "pure"),
    "origin": ("explicit", "limited", "pure"),
    "evaluation": ("compatibility", "metrics", "keep", "safety", "provenance", "substitution", "pure"),
    "promotion": ("eligibility", "binding", "decision", "human_gate", "provenance", "provenance_gate", "provenance_format", "pure"),
}
REQUIRED_PROBE = {
    "CB-0937": "learning.fail_closed", "CB-0938": "learning.reject_payload",
    "CB-0939": "origin.explicit", "CB-0940": "origin.limited",
    "CB-0941": "evaluation.compatibility", "CB-0942": "evaluation.keep",
    "CB-0943": "evaluation.provenance", "CB-0944": "evaluation.substitution",
    "CB-0945": "evaluation.safety", "CB-0946": "evaluation.pure",
    "CB-0947": "promotion.human_gate", "CB-0948": "promotion.binding",
    "CB-0949": "promotion.decision", "CB-0950": "promotion.provenance",
    "CB-0951": "promotion.provenance_gate",
}


def local_sources() -> dict[str, str]:
    """Read tracked files only; no network, DB, credentials or filesystem writes."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def matrix_rows(data: object) -> dict[str, tuple[str, ...]]:
    """Require all fifteen unique unpromoted rows in their canonical parts."""
    if type(data) is not dict or any(type(data.get(p)) is not str for p in (MAIN, PART4)):
        raise ValueError("UNKNOWN: missing matrix")
    found = {}
    for path in (MAIN, PART4):
        for line in data[path].splitlines():
            match = re.match(r"^\s*\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            group, clause = CLAUSES[key]
            if (key in found or len(cells) != 6 or cells[0] != key
                    or DOCS[group] not in cells[1]
                    or clause not in cells[2]
                    or not credible_e4_coverage_row(cells)
                    or "/issues/1" not in cells[5]
                    or (path == MAIN) != (key in {"CB-0939", "CB-0941", "CB-0947"})):
                raise ValueError("UNKNOWN: invalid/duplicate row " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: coverage IDs missing")
    return found


@lru_cache(maxsize=None)
def scenario(group: str, case: str) -> dict:
    """Execute only allowlisted synthetic PHP scenarios with bounded runtime."""
    if group not in CASES or case not in CASES[group]:
        raise AssertionError("UNKNOWN: invalid scenario")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP unavailable")
    run = subprocess.run(
        [php, str(ROOT / "tests" / SCRIPTS[group]), case], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=25,
    )
    if run.returncode != 0 or run.stderr.strip():
        raise AssertionError("UNKNOWN: scenario failed")
    try:
        result = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP evidence") from exc
    if type(result) is not dict or not result:
        raise AssertionError("UNKNOWN: empty PHP evidence")
    return result


def observations() -> dict[str, dict]:
    """Capture independent local outputs without an external provider."""
    return {f"{g}.{case}": scenario(g, case)
            for g, cases in CASES.items() for case in cases}


def evidence_ok(key: str, obs: dict[str, dict]) -> bool:
    """Specific behavior assertions; source presence alone never passes."""
    p = lambda group, case: obs[f"{group}.{case}"]
    if key == "CB-0937":
        x = p("learning", "fail_closed")
        return (x["unknown"]["type"] == "evidence_gap"
                and x["unknown"]["status"] == "unknown"
                and x["stale"]["freshness"] == "stale"
                and x["inferred"]["nature"] == "inferred"
                and x["inferred"]["confidence"] == 0.55
                and x["experiment"]["status"] == "inconclusive"
                and x["experiment"]["type"] == "experiment_result")
    if key == "CB-0938":
        x = p("learning", "reject_payload")
        y = p("learning", "closed")
        return (all(x[k] is True for k in
                    ("message", "action", "priority", "members", "event_payload", "user_id"))
                and all(y[k] is True for k in ("duplicate", "bad_target", "bad_type")))
    if key == "CB-0939":
        x = p("origin", "explicit")
        a, b = x["first"], x["second"]
        return (a["work_type"] == "product" and a["priority_class"] == "high"
                and a["authority_level"] == "operational"
                and b["work_type"] == "data_analytics" and b["authority_level"] == "owner"
                and b["requested_capabilities"] == ["analysis"]
                and b["required_roles"] == ["datos-analitica"]
                and b["policy_ref"] == "factory:policy-product"
                and p("origin", "limited")["missing_time"] is True)
    if key == "CB-0940":
        x = p("origin", "limited")
        return (x["missing_time"] is True
                and all(all(field not in item for field in
                            ("ready", "approved", "authorized", "readiness"))
                        for item in (x["unknown"], x["stale"]))
                and x["unknown"]["observed_at"] == "2026-09-29T14:00:00Z")
    if key == "CB-0941":
        x, y = p("evaluation", "compatibility"), p("evaluation", "metrics")
        return (x["valid"]["decision"] == "candidate_better"
                and all(x[k] is True for k in ("task", "set", "older_candidate"))
                and all(y[k] is True for k in ("unknown", "shape", "sample")))
    if key == "CB-0942":
        x = p("evaluation", "compatibility")["valid"]
        keep = p("evaluation", "keep")
        return (x["decision"] == "candidate_better"
                and "candidate_dominates_current" in x["reasons"]
                and all(keep[k]["decision"] == "keep_current" for k in ("tie", "small"))
                and all(p("evaluation", "safety")[k]["decision"] == "keep_current"
                        for k in ("unsafe", "policy")))
    if key == "CB-0943":
        x = p("evaluation", "provenance")
        base = x["base"]
        return (base["current_result_fingerprint"] == x["current_helper"]
                and base["candidate_result_fingerprint"] == x["candidate_helper"]
                and all(re.fullmatch("[a-f0-9]{64}", base[k]) for k in
                        ("current_result_fingerprint", "candidate_result_fingerprint")))
    if key == "CB-0944":
        x = p("evaluation", "substitution")
        return all(x[k] is True for k in (
            "evaluation_changed", "current_stable", "candidate_changed",
            "old_candidate_rejects_substitute"))
    if key == "CB-0945":
        x = p("evaluation", "safety")
        return (all(x[k]["decision"] == "keep_current"
                    and "candidate_safety_or_policy_failed" in x[k]["reasons"]
                    for k in ("unsafe", "policy"))
                and p("evaluation", "pure")["authority"] == "advisory_only")
    if key == "CB-0946":
        x = p("evaluation", "pure")
        return x["hits"] == [] and x["authority"] == "advisory_only"
    if key == "CB-0947":
        x = p("promotion", "human_gate")
        return (x["human_gate_required"] is True
                and x["authority"] == "human_approval_required"
                and x["history_unchanged"] is True
                and x["current_status"] == "approved"
                and x["candidate_status"] == "candidate")
    if key == "CB-0948":
        x, y = p("promotion", "binding"), p("promotion", "eligibility")
        return (all(x[k] is True for k in ("version", "authority", "fingerprint", "template"))
                and all(y[k] is True for k in
                        ("current_not_approved", "candidate_not_candidate",
                         "wrong_successor", "contract_drift"))
                and all(p("promotion", "provenance_format").values()))
    if key == "CB-0949":
        x = p("promotion", "decision")
        return (x["better"]["decision"] == "eligible_for_human_approval"
                and x["hold"]["decision"] == "hold"
                and p("promotion", "eligibility")["valid"]["human_gate_required"] is True)
    if key == "CB-0950":
        x, y = p("promotion", "human_gate"), p("promotion", "provenance")
        return (x["authority"] == "human_approval_required"
                and y["evaluation"]["authority"] == "advisory_only"
                and y["out"]["human_gate_required"] is True)
    if key == "CB-0951":
        x, y = p("promotion", "provenance_gate"), p("promotion", "provenance")
        return (x["eligible"]["decision"] == "eligible_for_human_approval"
                and x["hold"]["decision"] == "hold" and x["same_set"] is True
                and y["tampered_current"] is True
                and y["tampered_candidate"] is True)
    return False


def local_verdict(key: object, source: object, probes: object) -> str:
    """Missing, promoted or ambiguous facts never claim acceptance."""
    if type(key) is not str or key not in IDS or type(source) is not dict or type(probes) is not dict:
        return UNKNOWN
    try:
        if set(source) != FILES or any(type(source[p]) is not str or not source[p] for p in FILES):
            return UNKNOWN
        matrix_rows(source)
        group, clause = CLAUSES[key]
        if clause not in source[DOCS[group]]:
            return UNKNOWN
        if not all(k in source[CODE[group]] for k in MARKERS[group]):
            return UNKNOWN
        if group == "promotion" and not all(
            k in source[CODE["registry"]] for k in MARKERS["registry"]
        ):
            return UNKNOWN
        if not evidence_ok(key, probes):
            return UNKNOWN
    except (KeyError, TypeError, ValueError, IndexError, AttributeError):
        return UNKNOWN
    return PARTIAL


def operational_state(_key: object, _evidence: object = None) -> str:
    """Synthetic artifacts never grant owner, deployment or Factory authority."""
    return UNKNOWN


class BrechasControlbot61Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each of fifteen matrix clauses has a behavioral probe."""
        source, probes = local_sources(), observations()
        self.assertEqual(15, len(IDS))
        self.assertEqual(set(IDS), set(CLAUSES))
        rows = matrix_rows(source)
        self.assertEqual(set(IDS), set(rows))
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(PARTIAL, local_verdict(key, source, probes))
                self.assertTrue(credible_e4_coverage_row(rows[key]))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, promoted and tampered sources fail closed."""
        source, probes = local_sources(), observations()
        for key in IDS:
            path = MAIN if key in {"CB-0939", "CB-0941", "CB-0947"} else PART4
            row = next(x for x in source[path].splitlines()
                       if x.startswith(f"| {key} |"))
            for bad in (source[path].replace(row, "", 1),
                        source[path] + "\n" + row,
                        source[path] + "\n   " + row,
                        source[path].replace(row, corrupted_e4_row(row), 1)):
                with self.subTest(id=key, source=path):
                    self.assertEqual(UNKNOWN, local_verdict(
                        key, {**source, path: bad}, probes))
            group, marker = CLAUSES[key]
            doc = DOCS[group]
            self.assertEqual(UNKNOWN, local_verdict(
                key, {**source, doc: source[doc].replace(marker, "", 1)}, probes))
            self.assertEqual(UNKNOWN, local_verdict(
                key, {**source, CODE[group]: ""}, probes))
            self.assertEqual(UNKNOWN, local_verdict(
                key, source, {**probes, REQUIRED_PROBE[key]: {}}))
        self.assertEqual(UNKNOWN, local_verdict("CB-9999", source, probes))
        self.assertEqual(UNKNOWN, local_verdict("CB-0937", {}, probes))
        self.assertEqual(UNKNOWN, local_verdict("CB-0937", source, {}))

    def test_sin_datos_reales(self) -> None:
        """AC-03: local PHP fixtures only; no remote clients, secrets or PII."""
        source = local_sources()
        self.assertEqual(FILES, set(source))
        own = (ROOT / "tests/test_brechas_controlbot_61.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertLessEqual(imports, {"__future__", "ast", "functools", "json",
                                      "pathlib", "re", "shutil", "subprocess", "unittest", "test_brechas_controlbot_20"})
        for path in FILES:
            self.assertFalse(Path(path).is_absolute())
            self.assertNotIn("..", Path(path).parts)
            self.assertTrue((ROOT / path).is_file())
        self.assertEqual(UNKNOWN, operational_state("CB-0949", {"approved": True}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no local evidence upgrades unknown external permissions."""
        source, probes = local_sources(), observations()
        for key in IDS:
            with self.subTest(id=key):
                for fake in (None, {}, {"ci": "success", "sha": "a" * 40},
                             {"production": "GREEN", "approved": True},
                             {"human_decision": "yes", "runtime_authorized": True}):
                    self.assertEqual(UNKNOWN, operational_state(key, fake))
                self.assertEqual(PARTIAL, local_verdict(key, source, probes))


if __name__ == "__main__":
    unittest.main()
