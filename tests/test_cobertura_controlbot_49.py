"""Cobertura ejecutable de rollback offline ControlBot (#1232).

El código invoca el ejecutable real con JSON sintético. No lee la matriz de
cobertura ni contacta hosting/CI; SYNTHETIC_ONLY no autoriza un rollback.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROLLBACK = ROOT / "ops" / "factory" / "rollback"
REASONS = {
    "synthetic_checks_passed", "invalid_synthetic_fixture",
    "mode_not_authorized", "baseline_unverified",
    "previous_artifact_unverified", "healthy_sha_mismatch",
    "no_prior_release", "rollback_plan_unverified",
}
OUTPUT_KEYS = {
    "mode", "status", "reason", "rollback_executed",
    "production_ready", "readiness",
}


def fixture(**changes):
    result = {
        "kind": "synthetic",
        "phase": "construccion",
        "plan_kind": "git_revert",
        "candidate_sha": "a" * 40,
        "previous_healthy_sha": "b" * 40,
        "expected_healthy_sha": "b" * 40,
        "baseline_verified": True,
        "previous_artifact_verified": True,
        "rollback_plan_verified": True,
    }
    result.update(changes)
    return result


def execute(value=None, *, raw=None, args=("--offline-dry-run",), env=None, cwd=None):
    text = raw if raw is not None else json.dumps(fixture() if value is None else value)
    process = subprocess.run(
        [sys.executable, "-B", str(ROLLBACK), *args],
        input=text, text=True, capture_output=True, check=False,
        timeout=15, cwd=cwd or ROOT, env=env,
    )
    lines = process.stdout.splitlines()
    if len(lines) != 1:
        raise AssertionError(f"Expected exactly one JSON line, got {len(lines)}")
    if process.stderr:
        raise AssertionError(f"Unexpected stderr: {process.stderr[-500:]}")
    return process, json.loads(lines[0])


class CoberturaControlbot49Tests(unittest.TestCase):
    def assert_output(self, process, output, *, reason, accepted):
        self.assertEqual(process.returncode, 0 if accepted else 2)
        self.assertEqual(set(output), OUTPUT_KEYS)
        self.assertEqual(output["mode"], "offline_preflight")
        self.assertEqual(output["status"], "SYNTHETIC_ONLY" if accepted else "REJECTED")
        self.assertEqual(output["reason"], reason)
        self.assertIn(output["reason"], REASONS)
        self.assertIs(output["rollback_executed"], False)
        self.assertIs(output["production_ready"], False)
        self.assertEqual(output["readiness"], "UNKNOWN")
        self.assertEqual(len(process.stdout.splitlines()), 1)

    def test_contrato_principal(self):
        """AC-01: CB-0814..0819: comportamiento offline real y suite del rollback."""
        # CB-0814: la prueba de reversión es una preparación, no un rollback.
        process, result = execute()
        self.assert_output(process, result, reason="synthetic_checks_passed", accepted=True)
        self.assertNotIn("ROLLBACK_VERIFIED", process.stdout)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", process.stdout)
        # CB-0815: 8192 bytes exactos de JSON válido, sin atributos extra.
        encoded = json.dumps(fixture(), separators=(",", ":"))
        self.assertLess(len(encoded), 8192)
        exactly_limit = encoded + " " * (8192 - len(encoded))
        process, result = execute(raw=exactly_limit)
        self.assert_output(process, result, reason="synthetic_checks_passed", accepted=True)
        # CB-0816: SHA ficticios; declaraciones true nunca elevan readiness.
        process, result = execute(fixture(
            candidate_sha="f" * 40, previous_healthy_sha="0" * 40,
            expected_healthy_sha="0" * 40,
        ))
        self.assert_output(process, result, reason="synthetic_checks_passed", accepted=True)
        # CB-0817: una sola línea y solamente la lista de claves/estados permitidos.
        self.assertEqual(set(result), OUTPUT_KEYS)
        self.assertEqual(result["readiness"], "UNKNOWN")
        # CB-0818: no se genera autorización, restauración o recibo.
        for forbidden in ("receipt_id", "restored_at", "D-059", "ROLLBACK_VERIFIED"):
            self.assertNotIn(forbidden, process.stdout)
        # CB-0819: ejecutar la suite REAL de cuatro escenarios del adaptador.
        suite = subprocess.run(
            [sys.executable, "-B", "-m", "unittest",
             "tests/test_factory_rollback_preflight.py", "-v"],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=60,
        )
        self.assertEqual(suite.returncode, 0, suite.stderr[-900:])
        self.assertIn("Ran 4 tests", suite.stderr)
        self.assertIn("OK", suite.stderr)

    def test_falla_cerrado(self):
        """AC-02: payload/mode/SHA/booleans inválidos no habilitan rollback."""
        cases = [
            (fixture(baseline_verified=False), "baseline_unverified"),
            (fixture(previous_artifact_verified=False), "previous_artifact_unverified"),
            (fixture(rollback_plan_verified=False), "rollback_plan_unverified"),
            (fixture(expected_healthy_sha="c" * 40), "healthy_sha_mismatch"),
            (fixture(candidate_sha="b" * 40), "no_prior_release"),
            (fixture(phase="live"), "invalid_synthetic_fixture"),
            (fixture(kind="real"), "invalid_synthetic_fixture"),
            (fixture(plan_kind="shell"), "invalid_synthetic_fixture"),
            (fixture(candidate_sha="A" * 40), "invalid_synthetic_fixture"),
            (fixture(previous_healthy_sha="not-a-sha"), "invalid_synthetic_fixture"),
            (fixture(baseline_verified=1), "invalid_synthetic_fixture"),
            (fixture(previous_artifact_verified="true"), "invalid_synthetic_fixture"),
            (fixture(rollback_plan_verified=None), "invalid_synthetic_fixture"),
            (fixture(secret="synthetic-secret-canary"), "invalid_synthetic_fixture"),
            ({}, "invalid_synthetic_fixture"),
        ]
        missing = fixture()
        del missing["expected_healthy_sha"]
        cases.append((missing, "invalid_synthetic_fixture"))
        for payload, reason in cases:
            with self.subTest(reason=reason, payload_keys=sorted(payload)):
                process, output = execute(payload)
                self.assert_output(process, output, reason=reason, accepted=False)
                self.assertNotIn("synthetic-secret-canary", process.stdout)
        for raw in (
            "",
            '{"kind":"synthetic","kind":"real"}',
            '{"unused":NaN}',
            "not JSON",
            json.dumps(fixture()) + " " * 8193,
        ):
            with self.subTest(invalid_bytes=len(raw)):
                p, out = execute(raw=raw)
                self.assert_output(p, out, reason="invalid_synthetic_fixture", accepted=False)
        for flags in ((), ("--real",), ("--offline-dry-run", "--real")):
            with self.subTest(flags=flags):
                p, out = execute(args=flags)
                self.assert_output(p, out, reason="mode_not_authorized", accepted=False)

    def test_sin_datos_reales(self):
        """AC-03: fixtures inventados, ausencia de I/O externo y secretos ocultos."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        with TemporaryDirectory(prefix="cb1232-offline-") as temp:
            work = Path(temp)
            bin_dir = work / "bin"
            bin_dir.mkdir()
            sentinel = work / "external-command-called"
            for name in ("ssh", "scp", "curl", "wget", "rsync", "gh",
                         "git", "hostinger", "mysql", "mariadb", "cp", "mv"):
                mock = bin_dir / name
                mock.write_text(
                    f"#!/bin/sh\nprintf called > '{sentinel}'\nexit 71\n",
                    encoding="utf-8",
                )
                mock.chmod(0o700)
            env = os.environ.copy()
            env["PATH"] = str(bin_dir) + os.pathsep + env.get("PATH", "")
            env["DEPLOY_SSH_KEY"] = "synthetic-secret-canary"
            env["PYTHONDONTWRITEBYTECODE"] = "1"
            p, out = execute(env=env, cwd=work)
            self.assert_output(p, out, reason="synthetic_checks_passed", accepted=True)
            bad = fixture(secret="synthetic-secret-canary")
            p2, out2 = execute(bad, env=env, cwd=work)
            self.assert_output(p2, out2, reason="invalid_synthetic_fixture", accepted=False)
            self.assertNotIn("synthetic-secret-canary", p.stdout + p2.stdout)
            self.assertFalse(sentinel.exists())
            self.assertEqual(list(work.iterdir()), [bin_dir])


if __name__ == "__main__":
    unittest.main()
