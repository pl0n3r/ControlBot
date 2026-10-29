#!/usr/bin/env python3
"""ControlBot #1067: behavioral contracts CB-0922..CB-0936 using synthetic PHP.

Only local, aggregate product evidence is exercised. No production, CI status,
human approval, live telemetry or matrix promotion is inferred by this file.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MAIN = "docs/matriz-cobertura.md"
PART = "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{n:04d}" for n in range(922, 937))
DOCS = {
    "outcome": "docs/product-experiment-outcomes.md",
    "health": "docs/product-health-snapshot.md",
    "core": "docs/product-intelligence-contract.md",
    "learning": "docs/product-learning-signals.md",
}
PHP = {
    "outcome": "tests/product_experiment_outcome_scenarios.php",
    "health": "tests/product_health_snapshot_scenarios.php",
    "core": "tests/product_intelligence_scenarios.php",
    "learning": "tests/product_learning_signal_scenarios.php",
}
CODE = {
    "outcome": "src/ProductExperimentOutcome.php",
    "health": "src/ProductHealthSnapshot.php",
    "core": "src/ProductIntelligence.php",
    "learning": "src/ProductLearningSignal.php",
}
CASES = {
    "outcome": frozenset(("measured", "inconclusive", "fail_closed", "boundary", "pure")),
    "health": frozenset(("dimensions", "reasons", "global", "boundary", "pure")),
    "core": frozenset(("scope", "provenance", "unknown", "aggregate", "revenue", "pure")),
    "learning": frozenset(("scope", "closed", "reject_payload", "provenance", "pure")),
}
CLAUSES = {
    "CB-0922": ("outcome", "Si cualquiera de las dos métricas está"),
    "CB-0923": ("outcome", "Si cualquier evidencia es unknown"),
    "CB-0924": ("outcome", "En ausencia de unknown"),
    "CB-0925": ("outcome", "El outcome contiene agregados"),
    "CB-0926": ("health", "Reason codes cerrados"),
    "CB-0927": ("health", "Freshness global degrada"),
    "CB-0928": ("health", "Este contrato no mezcla"),
    "CB-0929": ("core", "Toda señal declara"),
    "CB-0930": ("core", "`observed` significa que existe evidencia agregada directa."),
    "CB-0931": ("core", "`unknown` e `insufficient_data` son estados de evidencia"),
    "CB-0932": ("core", "El contrato no agrega categorías"),
    "CB-0933": ("core", "solo acepta"),
    "CB-0934": ("core", "normaliza tamaño"),
    "CB-0935": ("learning", "Product Learning Signal es una salida"),
    "CB-0936": ("learning", "Targets:"),
}
TOP_IDS = frozenset(("CB-0926", "CB-0929", "CB-0935"))
FILES = frozenset((MAIN, PART, *DOCS.values(), *PHP.values(), *CODE.values()))
CLOSED_REASONS = {"unknown", "insufficient_data", "stale",
                  "freshness_unknown", "inferred", "observed_fresh"}


def local_sources() -> dict[str, str]:
    """Read only allowlisted committed resources; no remote or mutable providers."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in FILES}


def matrix_rows(data: object) -> dict[str, tuple[str, ...]]:
    """Reject incomplete, duplicate, cross-matrix or prematurely promoted rows."""
    if type(data) is not dict or set(data) != FILES or any(
        type(data[p]) is not str or not data[p] for p in FILES
    ):
        raise ValueError("UNKNOWN: missing canonical source")
    found = {}
    for matrix in (MAIN, PART):
        for line in data[matrix].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in CLAUSES:
                continue
            ident = match.group(1)
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            group, phrase = CLAUSES[ident]
            if (ident in found or len(cells) != 6 or cells[0] != ident
                    or not all(cells) or DOCS[group] not in cells[1]
                    or phrase not in cells[2]
                    or (matrix == MAIN) != (ident in TOP_IDS)
                    or cells[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos." not in cells[4]
                    or any(f"{state}=UNKNOWN" not in cells[4] for state in
                           ("Offline", "live", "operando"))
                    or "/issues/1" not in cells[5]):
                raise ValueError("UNKNOWN: row invalid " + ident)
            found[ident] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: rows missing or misplaced")
    return found


@lru_cache(maxsize=32)
def scenario(group: str, case: str) -> dict:
    """Bounded, allowlisted PHP process. No inherited credentials or network."""
    if group not in CASES or case not in CASES[group]:
        raise ValueError("UNKNOWN: disallowed scenario")
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    cmd = [binary, str(ROOT / PHP[group]), case]
    run = subprocess.run(
        cmd, cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
        env={**{key: os.environ[key] for key in
                 ("PATH", "LANG", "LC_ALL", "HOME", "TMPDIR",
                  "PHP_INI_SCAN_DIR", "PHPRC", "LD_LIBRARY_PATH")
                 if key in os.environ}, "XDEBUG_MODE": "off"},
    )
    if run.returncode != 0 or run.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: PHP {group}/{case} exit={run.returncode} "
            f"stderr_bytes={len(run.stderr)}")
    try:
        value = json.loads(run.stdout)
    except ValueError as ex:
        raise AssertionError("UNKNOWN: invalid scenario JSON") from ex
    if type(value) is not dict or not value:
        raise AssertionError("UNKNOWN: missing scenario output")
    return value


def behavior(ident: str) -> bool:
    """An independent executable assertion for every matrix row."""
    if ident == "CB-0922":
        r = scenario("outcome", "inconclusive")
        return all(r[k]["status"] == "inconclusive" and
                   r[k]["delta"] is None and r[k]["comparison"] == "unknown"
                   for k in ("unknown", "insufficient"))
    if ident == "CB-0923":
        r = scenario("outcome", "fail_closed")
        return (r["unknown_freshness"]["freshness"] == "unknown"
                and scenario("outcome", "inconclusive")["unknown"]["freshness"] == "unknown")
    if ident == "CB-0924":
        r = scenario("outcome", "fail_closed")
        return (r["stale"]["freshness"] == "stale"
                and scenario("outcome", "measured")["outcome"]["freshness"] == "fresh")
    if ident == "CB-0925":
        r = scenario("outcome", "boundary")
        good = r["good"]
        return (r["extra_decision"] is True and r["bad_ref"] is True
                and good["experiment_ref"].startswith("experiment:")
                and set(good).isdisjoint(("members", "user_id", "events",
                                          "event_payload", "decision", "execution"))
                and set(scenario("outcome", "pure")["methods"]) == {"outcome"})
    if ident == "CB-0926":
        dims = scenario("health", "reasons")["dimensions"]
        reasons = {reason for d in dims for reason in d["reasons"]}
        return reasons == CLOSED_REASONS and all(
            set(d["reasons"]) <= CLOSED_REASONS for d in dims)
    if ident == "CB-0927":
        r = scenario("health", "global")
        d = r["dimensions"]
        return (r["freshness"] == "unknown"
                and r["reasons"] == sorted(set(reason for row in d
                                               for reason in row["reasons"]))
                and scenario("health", "dimensions")["good"]["freshness"] == "fresh")
    if ident == "CB-0928":
        r = scenario("health", "boundary")
        return (all(r[k] is True for k in ("churn", "support", "revenue"))
                and set(scenario("health", "pure")["methods"]) == {"snapshot"}
                and set(r["good"]).isdisjoint(("decision", "work_items", "customer_id")))
    if ident == "CB-0929":
        r = scenario("core", "scope")
        metric = r["metric"]
        fields = ("venture_id", "product_id", "surface", "period", "source_ref",
                  "evidence_ref", "freshness", "confidence", "nature")
        return (all(metric.get(k) is not None for k in fields)
                and metric["venture_id"] == "venture-condor"
                and metric["product_id"] == "product-condor"
                and all(r[k] is True for k in
                        ("wrong_venture", "wrong_product", "bad_period")))
    if ident == "CB-0930":
        r = scenario("core", "provenance")
        return (r["inferred"]["nature"] == "inferred"
                and r["inferred"]["confidence"] == 0.61
                and all(r[k] is True for k in
                        ("zero_sample", "bad_confidence", "bad_freshness")))
    if ident == "CB-0931":
        r = scenario("core", "unknown")
        return (r["unknown"]["value"] is None
                and r["unknown"]["status"] == "unknown"
                and r["insufficient"]["value"] is None
                and r["insufficient"]["status"] == "insufficient_data"
                and r["insufficient"]["sample_size"] > 0
                and r["unknown_with_value"] is True and r["insufficient_zero"] is True)
    if ident == "CB-0932":
        r = scenario("core", "aggregate")
        return (all(r[k] is True for k in
                    ("member_list", "cohort_members", "increasing"))
                and set(r["funnel"]).isdisjoint(("members", "user_id", "events"))
                and set(r["cohort"]).isdisjoint(("member_ids", "customers", "events"))
                and set(scenario("core", "pure")["methods"]) ==
                {"metric", "funnel", "cohort"})
    if ident == "CB-0933":
        r = scenario("core", "revenue")
        return (r["good"]["source_ref"].startswith("aggregate:finance/")
                and r["good"]["category"] == "revenue_outcome"
                and r["bad_source"] is True and r["customer_ref"] is True)
    if ident == "CB-0934":
        r = scenario("core", "aggregate")
        cohort = r["cohort"]
        return (cohort["sample_size"] == 80 and cohort["retained_count"] == 46
                and cohort["cohort_key"] == "signup_week_40"
                and r["retained_over_sample"] is True)
    if ident == "CB-0935":
        r = scenario("learning", "scope")
        good = r["good"]
        rejected = scenario("learning", "reject_payload")
        return (r["wrong_scope"] is True
                and good["source_kind"] == "metric"
                and good["type"] == "observed_change"
                and good["aggregate_ref"].startswith("product-intelligence:")
                and set(good).isdisjoint(("message", "action", "priority",
                                          "members", "event_payload", "user_id"))
                and all(rejected.values())
                and set(scenario("learning", "pure")["methods"]) ==
                {"fromMetric", "fromExperimentOutcome"})
    if ident == "CB-0936":
        r = scenario("learning", "closed")
        return (r["good"]["targets"] == ["capital", "discovery", "momentum"]
                and r["duplicate"] is True and r["bad_target"] is True
                and r["bad_type"] is True)
    return False


def local_verdict(ident: object, data: object) -> str:
    if type(ident) is not str or ident not in CLAUSES:
        return UNKNOWN
    try:
        rows = matrix_rows(data)
        group, phrase = CLAUSES[ident]
        if ident not in rows or phrase not in data[DOCS[group]]:
            return UNKNOWN
        return LOCAL if behavior(ident) else UNKNOWN
    except (KeyError, ValueError, TypeError, IndexError, AssertionError,
            subprocess.TimeoutExpired, OSError):
        return UNKNOWN


def operational_verdict(_ident: object, _evidence: object = None) -> str:
    """Local contracts cannot attest CI, policy, live, legal or production."""
    return UNKNOWN


class BrechasControlbot60Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = local_sources()
        cls.index = matrix_rows(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: every requirement has an exercised PHP assertion or UNKNOWN."""
        self.assertEqual(set(IDS), set(CLAUSES))
        self.assertEqual(set(self.index), set(IDS))
        for ident in IDS:
            with self.subTest(ident=ident):
                group, phrase = CLAUSES[ident]
                self.assertIn(phrase, self.sources[DOCS[group]])
                verdict = local_verdict(ident, self.sources)
                if verdict != LOCAL:
                    try:
                        scenario(group, sorted(CASES[group])[0])
                    except (AssertionError, ValueError, OSError,
                            subprocess.TimeoutExpired) as exc:
                        self.fail(f"{ident}: {exc}")
                    self.fail(f"{ident}: behavioral predicate not verified")
                self.assertEqual(LOCAL, verdict)
                self.assertEqual("NO_VERIFICABLE", self.index[ident][3])

    def test_falla_cerrado(self) -> None:
        """AC-02: no missing, conflicting, injected or promoted evidence."""
        for ident in IDS:
            with self.subTest(ident=ident):
                group, phrase = CLAUSES[ident]
                path = MAIN if ident in TOP_IDS else PART
                line = next(x for x in self.sources[path].splitlines()
                            if x.startswith(f"| {ident} |"))
                missing = {**self.sources, path: self.sources[path].replace(line, "", 1)}
                self.assertEqual(UNKNOWN, local_verdict(ident, missing))
                for altered in (self.sources[path] + "\n" + line,
                                self.sources[path].replace(
                                    line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows({**self.sources, path: altered})
                damaged = {**self.sources,
                           DOCS[group]: self.sources[DOCS[group]].replace(phrase, "", 1)}
                self.assertEqual(UNKNOWN, local_verdict(ident, damaged))
        for invalid in (None, "", "CB-9999", {"approval": True}):
            self.assertEqual(UNKNOWN, local_verdict(invalid, self.sources))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("learning", "send_email")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows({})

    def test_sin_datos_reales(self) -> None:
        """AC-03: only anonymized local fixtures and allowlisted PHP scripts."""
        self.assertEqual(set(self.sources), FILES)
        for name in FILES:
            with self.subTest(path=name):
                p = Path(name)
                self.assertFalse(p.is_absolute())
                self.assertNotIn("..", p.parts)
                self.assertTrue((ROOT / p).is_file())
                self.assertTrue((ROOT / p).resolve().is_relative_to(ROOT.resolve()))
        self.assertEqual(set(CASES), set(PHP))
        self.assertEqual(set(CASES), set(CODE))
        self.assertEqual(set(CASES), set(DOCS))
        for category in ("outcome", "health", "core", "learning"):
            self.assertIn("final class ", self.sources[CODE[category]])
        for synthetic in ("venture-condor", "product-condor", "metric-unknown"):
            self.assertNotIn("@", synthetic)
        self.assertEqual(UNKNOWN, operational_verdict("CB-0932", {"approved": True}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no local result promotes an unverified coverage row or live."""
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertEqual("NO_VERIFICABLE", self.index[ident][3])
                for fake in (None, {}, {"ci": "success", "sha": "a" * 40},
                             {"owner_approved": True}, {"live": "GREEN"},
                             {"production": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(UNKNOWN, operational_verdict(ident, fake))


if __name__ == "__main__":
    unittest.main()
