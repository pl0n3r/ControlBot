#!/usr/bin/env python3
"""#1252: real PHP behavior tests for GitHub policy and Factory Live projection.

Offline assertions never read coverage matrix states or claim operational,
production, browser, grants, secrets or hosting evidence.
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
FIXTURES = {
    "policy": ("github_intent_policy_scenarios.php",
               {"reuse", "closed", "trusted_valid", "trusted_closed", "freshness"}),
    "project": ("github_project_snapshot_scenarios.php",
                {"routes", "review-invalid", "invalid"}),
    "snapshot": ("factory_live_snapshot_scenarios.php",
                 {"full", "fail_closed", "safety", "simulated"}),
    "ui": ("factory_live_ui_scenarios.php",
           {"full", "empty", "stale", "incoherent", "hostile"}),
}
ROWS_A = frozenset({"CB-1165", "CB-1167", "CB-1171", "CB-1172",
                    "CB-1173", "CB-1174", "CB-1176"})
ROWS_B = frozenset({"CB-1164", "CB-1166", "CB-1168", "CB-1169",
                    "CB-1175", "CB-1181"})
ROWS_C = frozenset({"CB-1177"})
ROWS_D = frozenset({"CB-1178", "CB-1179", "CB-1180"})


@lru_cache(maxsize=32)
def scenario(family: str, name: str):
    if family not in FIXTURES or name not in FIXTURES[family][1]:
        raise AssertionError("UNKNOWN: unsupported PHP fixture")
    script = ROOT / "tests" / FIXTURES[family][0]
    php = shutil.which("php")
    if php is None or not script.is_file():
        raise AssertionError("UNKNOWN: real PHP or fixture unavailable")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE"):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="cb1252-") as home:
        env["HOME"] = home
        run = subprocess.run([
            php, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
            "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
            str(script), name], cwd=ROOT, env=env, text=True,
            capture_output=True, timeout=60, check=False)
    if run.returncode:
        raise AssertionError("UNKNOWN: PHP fixture execution failed: " + run.stderr[-750:])
    if family == "ui":
        return run.stdout.strip()
    try:
        result = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid JSON from real PHP") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: real PHP response is not a mapping")
    return result


class CoberturaControlbot69Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: seven offline rows assert real production PHP behavior."""
        trusted = scenario("policy", "trusted_valid")
        denials = scenario("policy", "trusted_closed")
        github = scenario("project", "routes")
        snapshot = scenario("snapshot", "full")
        simulated = scenario("snapshot", "simulated")
        html = scenario("ui", "full")
        failure = scenario("snapshot", "fail_closed")
        review = scenario("project", "review-invalid")
        rows = {
            "CB-1165": (
                trusted["current"] == {
                    "decision": "deny", "reasons": ["grant_required"],
                    "execution": False}
                and all(v["decision"] != "allow" and v["execution"] is False
                        for v in denials.values())
            ),
            "CB-1167": (
                scenario("project", "invalid")["blocked"] ==
                {"bad-sha": True, "bad-check": True, "bad-workflow": True,
                 "project": True}
                and review["bad_review"]["items"][0]["review_state"] == "unknown"
                and review["null_mergeable"]["items"][0]["mergeability"] == "unknown"
                and len(github["calls"]) == 16
                and all(x[0] == "GET" and x[3] is None for x in github["calls"])
            ),
            "CB-1171": (
                snapshot["version"] == 1
                and set(snapshot["sections"]) == {
                    "batches", "owner_decisions", "releases", "blockers",
                    "production", "quality", "work", "learning"}
                and all(snapshot["sections"].values())
                and all(isinstance(x["source_ref"], str)
                        and isinstance(x["observed_at"], int)
                        and x["freshness"] == "current"
                        and x["age_seconds"] == snapshot["observed_at"] - x["observed_at"]
                        for signals in snapshot["sections"].values() for x in signals)
                and all(f'data-section="{section}"' in html
                        for section in snapshot["sections"])
            ),
            "CB-1172": (
                html.index('data-section="owner_decisions"') <
                html.index('data-section="batches"')
                and 'href="https://github.com/pl0n3r/Condor/issues/384"' in html
                and "Abrir Issue de decisión" in html
                and snapshot["sections"]["owner_decisions"][0]["data"]["issue_ref"]
                == "https://github.com/pl0n3r/ControlBot/issues/100"
            ),
            "CB-1173": (
                failure["missing"]["state"] == "unknown"
                and failure["missing"]["freshness"] == "unknown"
                and failure["missing"]["source_ref"] is None
                and "UNKNOWN" in scenario("ui", "empty")
                and "fresh-stale" in scenario("ui", "stale")
                and ">STALE<" in scenario("ui", "stale")
                and "state-healthy fresh-stale" not in scenario("ui", "stale")
                and scenario("ui", "incoherent") == "blocked"
            ),
            "CB-1174": (
                "&lt;script&gt;alert(1)&lt;/script&gt;" in scenario("ui", "hostile")
                and "<script>alert(1)</script>" not in scenario("ui", "hostile")
                and "&lt;img src=x onerror=alert(1)&gt;" in scenario("ui", "hostile")
                and "<img src=x onerror=alert(1)>" not in scenario("ui", "hostile")
                and "<form" not in html.lower()
                and scenario("snapshot", "safety")["secret"] is True
            ),
            "CB-1176": (
                simulated["source_kinds"] == [
                    "factory", "github", "learning", "observability",
                    "sonar", "tool_usage"]
                and len(simulated["authorities"]) == 8
                and simulated["authorities"]["quality"] == "quality_health"
                and simulated["authorities"]["owner_decisions"] == "owner_inbox"
                and simulated["authorities"]["work"] == "github_project_snapshot"
                and scenario("snapshot", "safety")["deterministic"] is True
            ),
        }
        self.assertEqual(set(rows), ROWS_A)
        self.assertEqual(len(ROWS_A | ROWS_B | ROWS_C | ROWS_D), 17)
        for row, verified in rows.items():
            with self.subTest(row=row):
                self.assertIs(verified, True, "PHP contract not exercised: " + row)

    def test_falla_cerrado(self) -> None:
        """AC-02: untrusted grants, stale inputs, ambiguous GitHub, missing data."""
        data = scenario("policy", "trusted_closed")
        for key, value in data.items():
            with self.subTest(trusted_case=key):
                self.assertNotEqual(value["decision"], "allow")
                self.assertIs(value["execution"], False)
        self.assertEqual(data["stale"]["reasons"], ["untrusted_or_stale_snapshot"])
        self.assertEqual(data["duplicate_source"]["reasons"], ["untrusted_or_stale_snapshot"])
        self.assertEqual(scenario("policy", "freshness")["unbound_ref"]["reasons"],
                         ["evidence_mismatch"])
        for key, blocked in scenario("project", "invalid")["blocked"].items():
            with self.subTest(github_case=key):
                self.assertIs(blocked, True)
        for key, blocked in scenario("snapshot", "fail_closed")["blocked"].items():
            with self.subTest(snapshot_case=key):
                self.assertIs(blocked, True)
        self.assertEqual(scenario("ui", "incoherent"), "blocked")
        self.assertEqual(scenario("project", "review-invalid")["bad_mergeability"]["items"][0]["mergeability"],
                         "unknown")
        self.assertIs(scenario("snapshot", "safety")["secret"], True)
        self.assertIs(scenario("snapshot", "safety")["bounded"], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: tests use deterministic fake input and read-only PHP outputs."""
        self.assertEqual([len(x) for x in (ROWS_A, ROWS_B, ROWS_C, ROWS_D)],
                         [7, 6, 1, 3])
        for name, (file, cases) in FIXTURES.items():
            with self.subTest(fixture=name):
                self.assertTrue((ROOT / "tests" / file).is_file())
                self.assertTrue(cases)
        for class_path in ("FactoryLiveSnapshot.php", "FactoryLiveUi.php",
                           "GitHubIntentPolicy.php"):
            code = (ROOT / "src" / class_path).read_text(encoding="utf-8").lower()
            for forbidden in ("curl_init(", "fsockopen(", "shell_exec(",
                              "proc_open(", "new pdo", "'post'", "'patch'",
                              "'delete'"):
                with self.subTest(php_class=class_path, forbidden=forbidden):
                    self.assertNotIn(forbidden, code)
        self.assertIs(scenario("snapshot", "safety")["deterministic"], True)
        for node in ast.walk(ast.parse(Path(__file__).read_text(encoding="utf-8"))):
            if isinstance(node, ast.Import):
                roots = {x.name.split(".")[0] for x in node.names}
            elif isinstance(node, ast.ImportFrom):
                roots = {(node.module or "").split(".")[0]}
            else:
                continue
            self.assertFalse(roots & {"requests", "urllib", "httpx",
                                      "socket", "pymysql"})


if __name__ == "__main__":
    unittest.main()
