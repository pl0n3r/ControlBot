#!/usr/bin/env python3
"""Issue #1238: real, offline PHP behavior for CB-0916 through CB-0934.

Only checked-in PHP scenario entrypoints and synthetic aggregate fixtures.
No coverage-matrix status is read or asserted.
"""
from __future__ import annotations

import ast
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
    "origin": "product_discovery_work_origin_scenarios.php",
    "outcome": "product_experiment_outcome_scenarios.php",
    "health": "product_health_snapshot_scenarios.php",
    "intelligence": "product_intelligence_scenarios.php",
}
CASES = {
    "origin": {"valid", "blocked", "explicit", "provenance", "invalid"},
    "outcome": {"scope", "measured", "inconclusive", "fail_closed", "boundary", "pure"},
    "health": {"scope", "dimensions", "reasons", "global", "boundary", "pure"},
    "intelligence": {"scope", "provenance", "unknown", "aggregate", "revenue", "pure"},
}
# C rows have no independently testable, current PHP behavior.
NON_EXECUTABLE = {
    "CB-0919": "C: reversibility/no-migration promise is lifecycle policy, not PHP behavior",
    "CB-0932": "C: obligations for future real tracking/data and legal gates are policy",
}
EXECUTABLE = frozenset(
    "CB-%04d" % n for n in
    (916, 917, 918, 920, 921, 922, 923, 924, 925, 926,
     927, 928, 929, 930, 931, 933, 934)
)


@lru_cache(maxsize=32)
def php(family: str, case: str) -> dict:
    """Exercise original PHP classes via checked-in scenario entrypoints."""
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unsupported PHP scenario")
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI required")
    script = ROOT / "tests" / SCRIPTS[family]
    if not script.is_file():
        raise AssertionError("UNKNOWN: original PHP scenario missing")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT",
                "XDEBUG_MODE"):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1238-") as home:
        env["HOME"] = home
        run = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
             str(script), case],
            cwd=ROOT, env=env, text=True, capture_output=True,
            timeout=60, check=False,
        )
    if run.returncode or run.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: PHP {family}/{case} failed (exit={run.returncode}): "
            + run.stderr[-700:]
        )
    try:
        value = json.loads(run.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: PHP returned invalid JSON") from error
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: PHP did not return an object")
    return value


class CoberturaControlbot55Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one real-code assertion for each executable coverage row."""
        o = php("origin", "valid")
        oe = php("origin", "explicit")
        ob = php("origin", "blocked")
        op = php("origin", "provenance")
        oi = php("origin", "invalid")
        sc = php("outcome", "scope")
        me = php("outcome", "measured")
        inc = php("outcome", "inconclusive")
        deg = php("outcome", "fail_closed")
        bd = php("outcome", "boundary")
        hr = php("health", "reasons")
        hg = php("health", "global")
        hd = php("health", "dimensions")
        hb = php("health", "boundary")
        ins = php("intelligence", "scope")
        ip = php("intelligence", "provenance")
        iu = php("intelligence", "unknown")
        ia = php("intelligence", "aggregate")
        ir = php("intelligence", "revenue")

        item = o["work_item"]
        changed = oe["changed"]["work_item"]
        numeric = me["outcome"]
        reasons = {x["category"]: x["reasons"] for x in hr["dimensions"]}
        predicates = {
            "CB-0916": (
                o["status"] == "materialized" and o["freshness"] == "fresh"
                and (item["origin_mode"], item["origin_system"], item["producer_ref"])
                == ("automatic", "controlbot", "controlbot:product-discovery")
                and all(v["status"] == "blocked" and v["work_item"] is None
                        for v in ob.values())
            ),
            "CB-0917": (
                (item["authority_level"], item["priority_class"], item["required_roles"])
                == ("l2", "high", ["producto", "qa"])
                and item["requested_capabilities"] == ["product.discovery", "workitem.write"]
                and item["policy_ref"] == "controlbot:policy/product-discovery-v1"
                and changed["authority_level"] == "l3"
                and changed["priority_class"] == "medium"
                and changed["depends_on"] == ["work:alpha", "work:beta"]
                and changed["claims"] == ["claim:review-alpha", "claim:review-beta"]
                and all("required_gates" not in x and "approval_ref" not in x
                        for x in (item, changed))
                and o["execution"] is False
            ),
            "CB-0918": (
                len(oi) == 7 and all(
                    v["status"] == "blocked" and v["work_item"] is None
                    and v["reasons"] == ["invalid_input"] and v["execution"] is False
                    for v in oi.values()
                ) and all(v["execution"] is False for v in ob.values())
            ),
            "CB-0920": (
                (sc["good"]["venture_id"], sc["good"]["product_id"],
                 sc["good"]["surface"], sc["good"]["category"], sc["good"]["unit"])
                == ("venture-condor", "product-condor", "web_app", "activation", "ratio")
                and all(sc[k] is True for k in
                        ("venture", "product", "surface", "category", "unit"))
            ),
            "CB-0921": (
                me["window_reject"] is True
                and numeric["baseline_metric_id"] == "metric-baseline"
                and numeric["variant_metric_id"] == "metric-variant"
                and numeric["evaluation_window"] == {"start_at": 900, "end_at": 2100}
                and numeric["source_ref"].startswith("aggregate:")
                and numeric["evidence_ref"].startswith("evidence:product/")
            ),
            "CB-0922": (
                all(inc[k]["status"] == "inconclusive"
                    and inc[k]["delta"] is None and inc[k]["comparison"] == "unknown"
                    for k in ("unknown", "insufficient"))
                and numeric["status"] == "measured"
                and abs(numeric["delta"] - 0.12) < 1e-9
                and numeric["comparison"] == "higher"
            ),
            "CB-0923": (
                inc["unknown"]["freshness"] == "unknown"
                and deg["unknown_freshness"]["freshness"] == "unknown"
            ),
            "CB-0924": (
                deg["stale"]["freshness"] == "stale"
                and deg["stale"]["confidence"] == 0.8
                and numeric["freshness"] == "fresh"
            ),
            "CB-0925": (
                bd["bad_ref"] is True and bd["extra_decision"] is True
                and set(numeric) == {
                    "version", "outcome_id", "experiment_ref", "venture_id", "product_id",
                    "surface", "category", "unit", "baseline_metric_id",
                    "variant_metric_id", "evaluation_window", "status", "delta",
                    "comparison", "nature", "freshness", "confidence",
                    "source_ref", "evidence_ref",
                }
                and not any(k in numeric for k in
                            ("members", "events", "event_payload", "execution"))
            ),
            "CB-0926": (
                reasons["activation"] == ["freshness_unknown", "unknown"]
                and "insufficient_data" in reasons["adoption"]
                and "stale" in reasons["retention"]
                and "inferred" in reasons["satisfaction"]
                and reasons["completion_rate"] == ["observed_fresh"]
                and set(hr["reasons"]).issubset({
                    "unknown", "insufficient_data", "stale",
                    "freshness_unknown", "inferred", "observed_fresh",
                })
            ),
            "CB-0927": (
                hg["freshness"] == "unknown"
                and hg["reasons"] == sorted(set(hg["reasons"]))
                and "stale" in hg["reasons"]
                and "freshness_unknown" in hg["reasons"]
                and hd["good"]["freshness"] == "fresh"
                and [x["category"] for x in hd["good"]["dimensions"]]
                == ["activation", "retention"]
            ),
            "CB-0928": (
                all(hb[k] is True for k in ("churn", "support", "revenue"))
                and not any(k in hb["good"] for k in
                            ("customer_success", "technical", "finance",
                             "decision", "work_item", "ui"))
                and hb["good"]["dimensions"][0]["category"] == "activation"
            ),
            "CB-0929": (
                ins["metric"]["venture_id"] == "venture-condor"
                and ins["metric"]["product_id"] == "product-condor"
                and ins["metric"]["surface"] == "web_app"
                and ins["metric"]["period"] == {"start_at": 1000, "end_at": 2000}
                and all(ins[k] is True for k in
                        ("wrong_venture", "wrong_product", "bad_period"))
                and ins["metric"]["source_ref"].startswith("aggregate:")
                and ins["metric"]["evidence_ref"].startswith("evidence:product/")
                and ins["metric"]["freshness"] == "fresh"
                and ins["metric"]["nature"] == "observed"
            ),
            "CB-0930": (
                ip["inferred"]["nature"] == "inferred"
                and ip["inferred"]["confidence"] == 0.61
                and ins["metric"]["nature"] == "observed"
                and deg["inferred"]["nature"] == "inferred"
            ),
            "CB-0931": (
                iu["unknown"]["value"] is None
                and iu["unknown"]["status"] == "unknown"
                and iu["insufficient"]["value"] is None
                and iu["insufficient"]["status"] == "insufficient_data"
                and iu["insufficient"]["sample_size"] > 0
                and iu["unknown_with_value"] is True
                and iu["insufficient_zero"] is True
            ),
            "CB-0933": (
                ir["good"]["category"] == "revenue_outcome"
                and ir["good"]["source_ref"].startswith("aggregate:finance/")
                and ir["bad_source"] is True and ir["customer_ref"] is True
                and "transactions" not in ir["good"]
            ),
            "CB-0934": (
                ia["cohort"]["sample_size"] == 80
                and ia["cohort"]["retained_count"] == 46
                and ia["cohort"]["cohort_key"] == "signup_week_40"
                and ia["retained_over_sample"] is True
                and ia["cohort_members"] is True
            ),
        }
        self.assertEqual(set(predicates), EXECUTABLE)
        self.assertEqual(EXECUTABLE | NON_EXECUTABLE.keys(),
                         {"CB-%04d" % n for n in range(916, 935)})
        self.assertFalse(EXECUTABLE & NON_EXECUTABLE.keys())
        self.assertEqual(op["first"], op["second"])
        self.assertNotEqual(op["first"]["work_item"]["idempotency_key"],
                            op["changed"]["work_item"]["idempotency_key"])
        for row, ok in predicates.items():
            with self.subTest(row=row):
                self.assertIs(ok, True, "Real PHP behavior not verified: " + row)

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid bindings, scope, evidence, windows and PII fail closed."""
        for key in ("cross_scope", "extra", "sensitive", "pii",
                    "duplicate_capability", "duplicate_claim", "duplicate_role"):
            row = php("origin", "invalid")[key]
            with self.subTest(origin=key):
                self.assertEqual(row["status"], "blocked")
                self.assertIsNone(row["work_item"])
                self.assertEqual(row["reasons"], ["invalid_input"])
                self.assertIs(row["execution"], False)
        for key in ("iterate", "park", "stop", "research_more", "stale", "unknown"):
            row = php("origin", "blocked")[key]
            self.assertIsNone(row["work_item"])
            self.assertIs(row["execution"], False)
        for key in ("venture", "product", "surface", "category", "unit"):
            self.assertIs(php("outcome", "scope")[key], True)
        self.assertIs(php("outcome", "measured")["window_reject"], True)
        for key in ("bad_ref", "extra_decision"):
            self.assertIs(php("outcome", "boundary")[key], True)
        for key in ("venture", "product", "surface", "period"):
            self.assertIs(php("health", "scope")[key], True)
        self.assertIs(php("health", "dimensions")["duplicate"], True)
        for key in ("churn", "support", "revenue"):
            self.assertIs(php("health", "boundary")[key], True)
        for key in ("wrong_venture", "wrong_product", "bad_period"):
            self.assertIs(php("intelligence", "scope")[key], True)
        for key in ("zero_sample", "bad_confidence", "bad_freshness", "sensitive_ref"):
            self.assertIs(php("intelligence", "provenance")[key], True)
        for key in ("unknown_with_value", "insufficient_zero"):
            self.assertIs(php("intelligence", "unknown")[key], True)
        for key in ("increasing", "member_list", "cohort_members", "retained_over_sample"):
            self.assertIs(php("intelligence", "aggregate")[key], True)
        for key in ("bad_source", "customer_ref"):
            self.assertIs(php("intelligence", "revenue")[key], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: deterministic synthetic calls, no secrets or live services."""
        self.assertEqual(set(SCRIPTS),
                         {"origin", "outcome", "health", "intelligence"})
        self.assertEqual(php("outcome", "pure")["methods"], ["outcome"])
        self.assertEqual(php("health", "pure")["methods"], ["snapshot"])
        self.assertEqual(sorted(php("intelligence", "pure")["methods"]),
                         ["cohort", "funnel", "metric"])
        self.assertEqual(php("origin", "provenance")["first"],
                         php("origin", "provenance")["second"])
        self.assertFalse(php("origin", "valid")["execution"])
        for result in (
            php("origin", "valid")["work_item"],
            php("outcome", "measured")["outcome"],
            php("health", "dimensions")["good"],
            php("intelligence", "aggregate")["cohort"],
        ):
            serial = json.dumps(result).lower()
            for forbidden in ("password", "bearer ", "authorization", "@",
                              "member_ids", "customer_id", "cookie"):
                self.assertNotIn(forbidden, serial)
            for field in ("events", "members", "event_payload", "access_token"):
                self.assertNotIn(field, result)
        source = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, (ast.Import, ast.ImportFrom)):
                modules = ([x.name.split(".")[0] for x in node.names]
                           if isinstance(node, ast.Import) else [node.module or ""])
                self.assertFalse(set(modules) &
                                 {"socket", "requests", "urllib", "httpx", "pymysql"})


if __name__ == "__main__":
    unittest.main()
