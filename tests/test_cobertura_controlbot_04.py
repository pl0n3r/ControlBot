#!/usr/bin/env python3
"""ControlBot #1187: behavioral evidence for CB-0017..CB-0030.

Exercises real AegisPosture::summarize with synthetic JSON only. Never reads
production configuration, credentials, user data or a network endpoint.
"""
from __future__ import annotations

import copy
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/AegisPosture.php"
PHP_RUNNER = r"""
require 'src/AegisPosture.php';
try {
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $out = \ControlBot\Security\AegisPosture::summarize($data);
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'REJECTED: ' . get_class($error) . "\n");
    exit(3);
}
"""
SCOPE = "project:fixture"
WHEN = 1_700_000_000


def ref(name: str) -> str:
    return "controlbot:fixture/" + name


def finding(name: str, *, severity: str = "medium", state: str = "open") -> dict:
    return {
        "finding_id": name,
        "category": "application_security",
        "severity": severity,
        "scope": SCOPE,
        "state": state,
        "evidence_refs": [ref("evidence-one")],
        "source_ref": ref("scanner"),
        "observed_at": WHEN,
        "freshness": "fresh",
    }


def identity(metric: str, value: int = 0) -> dict:
    return {
        "metric": metric,
        "scope": SCOPE,
        "value": value,
        "source_type": "authoritative",
        "source_ref": ref("identity"),
        "observed_at": WHEN,
        "freshness": "fresh",
    }


def vulnerability(severity: str, count: int = 0) -> dict:
    return {
        "severity": severity,
        "scope": SCOPE,
        "count": count,
        "source_ref": ref("scan"),
        "observed_at": WHEN,
        "freshness": "fresh",
    }


def continuity(state: str, kind: str) -> dict:
    return {
        "scope": SCOPE,
        "state": state,
        "source_ref": ref(kind),
        "observed_at": WHEN,
        "freshness": "fresh",
    }


def fixture() -> dict:
    return {
        "version": 1,
        "scope": SCOPE,
        "findings": [finding("finding-example")],
        "identity_signals": [
            identity("mfa_coverage", 85),
            identity("privileged_identities", 2),
            identity("orphan_accounts", 0),
        ],
        "vulnerability_signals": [
            vulnerability(s) for s in ("info", "low", "medium", "high", "critical")
        ],
        "backup_signal": continuity("available", "backup"),
        "restore_signal": continuity("verified", "restore"),
    }


def run_php(payload: dict) -> tuple[dict | None, str]:
    """Only local PHP, local source and disposable synthetic STDIN."""
    if not SOURCE.is_file():
        raise AssertionError("AegisPosture.php absent; real behavior unverified")
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP absent; real behavior unverified")
    env = {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL", "CONTROLBOT_REAL_PHP",
                              "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
                              "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE") if key in os.environ}
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_RUNNER],
        input=json.dumps(payload, sort_keys=True),
        cwd=ROOT, env=env, text=True, capture_output=True,
        timeout=20, check=False,
    )
    if result.returncode == 0:
        try:
            return json.loads(result.stdout), ""
        except json.JSONDecodeError as error:
            raise AssertionError("PHP did not return JSON") from error
    return None, result.stderr


class CoberturaControlbot04Tests(unittest.TestCase):
    """Every behavioral assertion calls PHP, not just docs or matrix state."""

    def ok(self, payload: dict) -> dict:
        out, error = run_php(payload)
        self.assertIsNotNone(out, "synthetic PHP failure: " + error[:250])
        return out or {}

    def rejected(self, payload: dict) -> None:
        out, error = run_php(payload)
        self.assertIsNone(out, "invalid payload accepted")
        self.assertIn("REJECTED:", error, "invalid payload not rejected explicitly")

    def test_contrato_principal(self) -> None:
        # CB-0017/18: deterministic, read-only, exact canonical scope.
        base = fixture()
        result = self.ok(base)
        self.assertEqual(SCOPE, result["scope"])
        self.assertEqual("observed", result["posture_state"])
        self.assertEqual(result, self.ok(copy.deepcopy(base)))

        # CB-0019: mixing scopes fails closed for findings AND signals.
        foreign = copy.deepcopy(base)
        foreign["findings"][0]["scope"] = "project:other"
        self.rejected(foreign)
        foreign = copy.deepcopy(base)
        foreign["restore_signal"]["scope"] = "project:other"
        self.rejected(foreign)

        # CB-0020/21: strict schema, opaqueness and safe evidence dedupe.
        duplicated = copy.deepcopy(base)
        twin = copy.deepcopy(duplicated["findings"][0])
        twin["evidence_refs"] = [ref("evidence-two")]
        duplicated["findings"].append(twin)
        rows = self.ok(duplicated)["findings"]
        self.assertEqual(1, len(rows))
        self.assertEqual(
            sorted([ref("evidence-one"), ref("evidence-two")]),
            rows[0]["evidence_refs"],
        )

        # CB-0022: only authoritative + fresh identities can expose values.
        by_metric = {x["metric"]: x for x in result["identity_signals"]}
        self.assertEqual("known", by_metric["mfa_coverage"]["state"])
        self.assertEqual(85, by_metric["mfa_coverage"]["value"])
        derived = copy.deepcopy(base)
        derived["identity_signals"][0]["source_type"] = "derived"
        rows = {x["metric"]: x for x in self.ok(derived)["identity_signals"]}
        self.assertEqual("unknown", rows["mfa_coverage"]["state"])
        self.assertIsNone(rows["mfa_coverage"]["value"])

        # CB-0023: zero vulnerabilities are clear ONLY with fresh evidence.
        by_severity = {x["severity"]: x for x in result["vulnerability_signals"]}
        self.assertEqual("clear", by_severity["high"]["state"])
        self.assertEqual(0, by_severity["high"]["count"])
        self.assertEqual(WHEN, by_severity["high"]["observed_at"])
        self.assertEqual(ref("scan"), by_severity["high"]["source_ref"])
        stale = copy.deepcopy(base)
        next(x for x in stale["vulnerability_signals"] if x["severity"] == "high")["freshness"] = "stale"
        out = {x["severity"]: x for x in self.ok(stale)["vulnerability_signals"]}
        self.assertEqual("unknown", out["high"]["state"])

        # CB-0024..27: backup and restore verification are separate.
        self.assertEqual("available", result["backup_signal"]["state"])
        self.assertEqual("verified", result["restore_signal"]["state"])
        no_restore = copy.deepcopy(base)
        no_restore["restore_signal"]["state"] = "unknown"
        out = self.ok(no_restore)
        self.assertEqual("available", out["backup_signal"]["state"])
        self.assertEqual("unknown", out["posture_state"])

        # CB-0028: active critical findings and failed backups require attention.
        urgent = copy.deepcopy(base)
        urgent["findings"][0]["severity"] = "critical"
        self.assertEqual("attention_required", self.ok(urgent)["posture_state"])
        failed = copy.deepcopy(base)
        failed["backup_signal"]["state"] = "failed"
        self.assertEqual("attention_required", self.ok(failed)["posture_state"])

        # CB-0029/30: missing supported signals never become observed.
        missing = copy.deepcopy(base)
        missing["identity_signals"].pop()
        self.assertEqual("unknown", self.ok(missing)["posture_state"])

    def test_falla_cerrado(self) -> None:
        bad_cases = {}
        bad = fixture(); bad["scope"] = "team:fixture"
        bad_cases["noncanonical scope"] = bad
        bad = fixture(); bad["findings"][0]["unlisted"] = True
        bad_cases["unexpected field"] = bad
        bad = fixture(); bad["findings"][0]["source_ref"] = ref("api-token")
        bad_cases["secret-like reference"] = bad
        bad = fixture()
        bad["findings"].append(copy.deepcopy(bad["findings"][0]))
        bad["findings"][1]["severity"] = "critical"
        bad_cases["duplicate conflict"] = bad
        bad = fixture(); bad["restore_signal"]["state"] = "available"
        bad_cases["invalid restore state"] = bad
        bad = fixture(); bad["backup_signal"]["state"] = "verified"
        bad_cases["invalid backup state"] = bad
        bad = fixture(); bad["identity_signals"][0]["value"] = -1
        bad_cases["invalid identity count"] = bad
        bad = fixture(); bad["vulnerability_signals"][0]["count"] = -1
        bad_cases["negative vulnerability count"] = bad
        bad = fixture(); bad.pop("backup_signal")
        bad_cases["missing required backup signal"] = bad
        for label, payload in bad_cases.items():
            with self.subTest(label=label):
                self.rejected(payload)
        partial = fixture()
        partial["vulnerability_signals"].pop()
        self.assertEqual("unknown", self.ok(partial)["posture_state"])

    def test_sin_datos_reales(self) -> None:
        base = fixture()
        self.assertEqual(SCOPE, base["scope"])
        self.assertEqual(
            {"version", "scope", "findings", "identity_signals",
             "vulnerability_signals", "backup_signal", "restore_signal"},
            set(base),
        )
        serialized = json.dumps(base)
        self.assertNotIn("@", serialized)
        self.assertNotIn("https://", serialized)
        self.assertNotIn("password", serialized.lower())
        data = self.ok(base)
        self.assertEqual("observed", data["posture_state"])
        self.assertEqual(ref("backup"), data["backup_signal"]["source_ref"])
        self.assertEqual(ref("restore"), data["restore_signal"]["source_ref"])

    def test_estados_unknown_explicitos(self) -> None:
        # AC-04: missing, stale and non-authoritative evidence is never healthy.
        cases = {}
        bad = fixture(); bad["identity_signals"][0]["freshness"] = "stale"
        cases["stale identity"] = bad
        bad = fixture(); bad["identity_signals"][0]["source_type"] = "unknown"
        cases["nonauthoritative identity"] = bad
        bad = fixture(); bad["vulnerability_signals"][3]["freshness"] = "unknown"
        cases["unknown vulnerability"] = bad
        bad = fixture(); bad["restore_signal"]["state"] = "unknown"
        cases["unverified restore"] = bad
        bad = fixture(); bad["backup_signal"]["freshness"] = "stale"
        cases["stale backup"] = bad
        bad = fixture(); bad["findings"][0]["state"] = "unknown"
        cases["unknown finding"] = bad
        bad = fixture(); bad["identity_signals"].pop()
        cases["missing identity"] = bad
        bad = fixture(); bad["vulnerability_signals"].pop()
        cases["missing vulnerability"] = bad
        for name, case in cases.items():
            with self.subTest(name=name):
                self.assertEqual("unknown", self.ok(case)["posture_state"])
        crisis = fixture()
        crisis["vulnerability_signals"][4]["count"] = 1
        self.assertEqual("attention_required", self.ok(crisis)["posture_state"])


if __name__ == "__main__":
    unittest.main()
