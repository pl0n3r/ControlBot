#!/usr/bin/env python3
"""#1233: offline behavioral checks of the real deploy/observer preflights.

The scripts below are extracted from the checked-in workflows and executed in
private synthetic directories. No GitHub dispatch, provider I/O or deployment.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
from tempfile import TemporaryDirectory
import unittest

from tests.test_authenticated_router import PHP_SCENARIO

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / ".github/workflows/deploy.yml"
OBSERVE = ROOT / ".github/workflows/observar.yml"
SMOKE = ROOT / "scripts/deploy-smoke.php"
VERSION = ROOT / "config/version.json"
EXPECTED = "a" * 40
ROWS = {f"CB-{i:04d}" for i in range(820, 835)}
# Each of these six clauses has an executable offline sub-contract.
OFFLINE = {
    "CB-0820", "CB-0824", "CB-0826", "CB-0827", "CB-0828", "CB-0834",
}
# Hosting recovery and the post-recovery live observation need real receipts.
REAL_WORLD = {
    "CB-0832": "B: restore on Hostinger needs actual backup, owner and change evidence",
    "CB-0833": "B: observing a restored live site needs a real trusted release",
}
POLICY = {
    "CB-0821": "C: GitHub expression binding is not a local executable SHA authority",
    "CB-0822": "C: no disclosed secret values is configuration/privacy policy",
    "CB-0823": "C: materialization of the public site requires owner authorization",
    "CB-0825": "C: secret names are documented bindings, not live secret validation",
    "CB-0829": "C: D-059 prior backup and purchase limits are human approval gates",
    "CB-0830": "C: rollback instruction to disable a flag is a governance action",
    "CB-0831": "C: non-destructive remote rollback is a documented limitation",
}


@lru_cache(maxsize=2)
def preflight(kind: str) -> str:
    """Read the exact Bash block run by the real GitHub Actions readiness job."""
    if kind not in ("deploy", "observe"):
        raise AssertionError("UNKNOWN: unknown workflow")
    path, label = (
        (DEPLOY, "Exigir configuración y entrypoint antes del deploy")
        if kind == "deploy"
        else (OBSERVE, "Exigir configuración e identidad observable")
    )
    lines = path.read_text(encoding="utf-8").splitlines()
    marker = "      - name: " + label
    if lines.count(marker) != 1:
        raise AssertionError("UNKNOWN: readiness step is absent or ambiguous")
    start = lines.index(marker)
    if lines[start + 1] != "        run: |":
        raise AssertionError("UNKNOWN: readiness is not a Bash block")
    body = []
    for line in lines[start + 2:]:
        if line.startswith("          "):
            body.append(line[10:])
        elif not line.strip():
            body.append("")
        else:
            break
    if not body or body[0].strip() != "set -euo pipefail":
        raise AssertionError("UNKNOWN: Bash fail-closed preflight is missing")
    return "\n".join(body) + "\n"


def guard(kind: str, *, domain: str = "https://synthetic.invalid",
          enabled: str = "true", entrypoint: bool = True,
          sha: str = EXPECTED) -> subprocess.CompletedProcess[str]:
    shell = shutil.which("bash")
    if not shell:
        raise AssertionError("UNKNOWN: Bash runtime required")
    with TemporaryDirectory(prefix="cb-1233-guard-") as tmp:
        private = Path(tmp)
        (private / "public").mkdir()
        if entrypoint:
            (private / "public/index.php").write_text(
                "<?php echo 'fixture';\n", encoding="utf-8"
            )
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": str(private),
            "DEPLOY_ENABLED": enabled,
            "DOMAIN": domain,
            "PUBLIC_ENTRYPOINT": "public/index.php",
            "GITHUB_SHA": sha,
        }
        return subprocess.run(
            [shell, "--noprofile", "--norc", "-c", preflight(kind)],
            cwd=private, env=env, capture_output=True, text=True,
            check=False, timeout=10,
        )


def smoke(*, release_sha: str = EXPECTED, expected_sha: str = EXPECTED,
          version: str | None = None, status: str = "ok",
          extra: bool = False) -> subprocess.CompletedProcess[str]:
    """Run actual local PHP identity verifier against synthetic health JSON."""
    php = shutil.which("php")
    if not php or not SMOKE.is_file():
        raise AssertionError("UNKNOWN: PHP verifier unavailable")
    declared = json.loads(VERSION.read_text(encoding="utf-8"))["version"]
    with TemporaryDirectory(prefix="cb-1233-smoke-") as tmp:
        root = Path(tmp)
        local_version = root / "version.json"
        health = root / "health.json"
        local_version.write_text(json.dumps({"version": declared}), encoding="utf-8")
        response = {
            "status": status,
            "release_sha": release_sha,
            "version": declared if version is None else version,
        }
        if extra:
            response["token"] = "synthetic-not-a-secret"
        health.write_text(json.dumps(response), encoding="utf-8")
        return subprocess.run(
            [php, "-d", "allow_url_fopen=0", str(SMOKE),
             str(health), str(local_version), expected_sha],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=15,
        )


@lru_cache(maxsize=1)
def authenticate() -> dict:
    """Genuine owner session and TOTP service, with only synthetic identities."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP runtime required")
    run = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_SCENARIO],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=35,
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: synthetic owner auth failed: " +
                             run.stderr[-400:])
    return json.loads(run.stdout)


class CoberturaControlbot50Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exercise real shell and PHP, not a coverage-matrix state."""
        deploy = DEPLOY.read_text(encoding="utf-8")
        observer = OBSERVE.read_text(encoding="utf-8")
        version = json.loads(VERSION.read_text(encoding="utf-8"))["version"]
        allowed_deploy = guard("deploy")
        allowed_observe = guard("observe")
        correct = smoke()
        bad_sha = smoke(release_sha="b" * 40)
        bad_version = smoke(version="0.0.0")
        session = authenticate()
        checks = {
            "CB-0820": (
                guard("observe", domain="").returncode != 0
                and allowed_observe.returncode == 0
            ),
            "CB-0824": (
                all(guard("deploy", enabled=flag).returncode != 0
                    for flag in ("", "TRUE", "false", "1"))
                and allowed_deploy.returncode == 0
                and deploy.count("if: vars.DEPLOY_ENABLED == 'true'") >= 2
                and "if: vars.DOMAIN != '' && vars.DEPLOY_ENABLED == 'true'"
                    in observer
            ),
            "CB-0826": (
                guard("deploy", domain="").returncode != 0
                and guard("deploy", entrypoint=False).returncode != 0
                and allowed_deploy.returncode == 0
            ),
            "CB-0827": (
                guard("observe", entrypoint=False).returncode != 0
                and guard("observe", sha="").returncode != 0
                and allowed_observe.returncode == 0
            ),
            "CB-0828": (
                correct.returncode == 0
                and bad_sha.returncode == 1
                and "expected_sha: " + "$" + "{{ github.sha }}" in observer
            ),
            "CB-0834": (
                correct.returncode == 0 and bad_version.returncode == 1
                and session["valid"]["status"] == 200
                and all(session[k]["status"] in (401, 403)
                        for k in ("missing", "not_otp", "expired", "forged"))
                and all(response["denied"] in (401, 403)
                        for response in session["routes"].values())
            ),
        }
        self.assertEqual(len(ROWS), 15)
        self.assertEqual(len(OFFLINE), 6)
        self.assertEqual(len(POLICY), 7)
        self.assertEqual(len(REAL_WORLD), 2)
        self.assertEqual(ROWS, OFFLINE | set(POLICY) | set(REAL_WORLD))
        self.assertEqual(set(checks), OFFLINE)
        for clause, passed in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(passed, clause + ": behavior not demonstrated")

    def test_falla_cerrado(self) -> None:
        """AC-02: missing config, invalid flags, forged SHA/health and auth."""
        for kind, options in (
            ("deploy", ({"domain": ""}, {"entrypoint": False},
                        {"enabled": ""}, {"enabled": "TRUE"})),
            ("observe", ({"domain": ""}, {"entrypoint": False},
                         {"sha": ""})),
        ):
            for params in options:
                with self.subTest(kind=kind, params=params):
                    run = guard(kind, **params)
                    self.assertNotEqual(run.returncode, 0)
                    self.assertNotIn("synthetic-not-a-secret", run.stderr)
        for params in (
            {"release_sha": "b" * 40},
            {"expected_sha": "b" * 40},
            {"version": "0.0.0"},
            {"status": "degraded"},
            {"extra": True},
        ):
            with self.subTest(smoke=params):
                result = smoke(**params)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, "")
                self.assertEqual(
                    result.stderr,
                    "Health smoke failed: identity unavailable or mismatched.\n",
                )
                self.assertNotIn("synthetic-not-a-secret", result.stderr)
        auth = authenticate()
        for kind in ("missing", "not_otp", "expired", "forged"):
            with self.subTest(identity=kind):
                self.assertIn(auth[kind]["status"], (401, 403))
                self.assertEqual(
                    auth[kind]["headers"]["Cache-Control"], "no-store"
                )

    def test_sin_datos_reales(self) -> None:
        """AC-03: local-only fixtures, never trigger workflows or provider I/O."""
        self.assertEqual(guard("deploy").returncode, 0)
        self.assertEqual(guard("observe").returncode, 0)
        good = smoke()
        self.assertEqual(good.returncode, 0, good.stderr)
        self.assertEqual(good.stderr, "")
        declaration = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(declaration["phase"], "construccion")
        for path in (DEPLOY, OBSERVE):
            text = path.read_text(encoding="utf-8")
            self.assertIn("persist-credentials: false", text)
            self.assertNotIn("\n  push:", text)
        code = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("matriz" + "-cobertura", code)
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                names = {alias.name.split(".")[0] for alias in node.names}
                self.assertFalse(
                    names & {"requests", "socket", "urllib", "httpx", "pymysql"}
                )


if __name__ == "__main__":
    unittest.main()
