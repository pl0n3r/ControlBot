"""AC-01..04: deploying without real owner authorization remains impossible."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "ops/factory/deploy"


def sample(**changes: object) -> dict[str, object]:
    result: dict[str, object] = {
        "kind": "synthetic", "phase": "construccion", "transport": "none",
        "candidate_sha": "a" * 40, "expected_candidate_sha": "a" * 40,
        "artifact_sha256": "c" * 64, "baseline_sha": "b" * 40,
        "expected_baseline_sha": "b" * 40, "baseline_verified": True,
        "artifact_verified": True, "backup_verified": True, "rollback_verified": True,
    }
    result.update(changes)
    return result


def run(payload: object, *args: str, raw: str | None = None,
        env: dict[str, str] | None = None, cwd: str | None = None):
    proc = subprocess.run(
        [sys.executable, str(ADAPTER), *(args or ("--offline-dry-run",))],
        input=json.dumps(payload) if raw is None else raw, text=True,
        capture_output=True, cwd=cwd or ROOT, env=env, timeout=8, check=False,
    )
    return proc, json.loads(proc.stdout)


class FactoryDeployPreflightTests(unittest.TestCase):
    def assert_denied(self, payload: object, reason: str) -> None:
        proc, result = run(payload)
        self.assertEqual(proc.returncode, 2, proc.stderr)
        self.assertEqual(proc.stderr, "")
        self.assertEqual(result["status"], "REJECTED")
        self.assertEqual(result["reason"], reason)
        self.assertIs(result["deploy_executed"], False)
        self.assertIs(result["production_ready"], False)
        self.assertEqual(result["readiness"], "UNKNOWN")

    def test_missing_backup_or_rollback_denied(self) -> None:
        self.assert_denied(sample(backup_verified=False), "backup_unverified")
        self.assert_denied(sample(rollback_verified=False), "rollback_unverified")
        self.assert_denied(sample(baseline_verified=False), "baseline_unverified")
        self.assert_denied(sample(artifact_verified=False), "artifact_unverified")
        missing = sample()
        del missing["backup_verified"]
        self.assert_denied(missing, "invalid_synthetic_fixture")
        self.assert_denied(sample(backup_verified=1), "invalid_synthetic_fixture")

    def test_mismatched_release_identity_rejected(self) -> None:
        self.assert_denied(sample(expected_candidate_sha="d" * 40), "candidate_sha_mismatch")
        self.assert_denied(sample(expected_baseline_sha="d" * 40), "baseline_sha_mismatch")
        self.assert_denied(sample(baseline_sha="a" * 40, expected_baseline_sha="a" * 40), "no_release_transition")
        self.assert_denied(sample(artifact_sha256="invalid"), "invalid_synthetic_fixture")
        self.assert_denied(sample(candidate_sha="A" * 40), "invalid_synthetic_fixture")
        self.assert_denied(sample(transport="hostinger-ssh"), "invalid_synthetic_fixture")
        self.assert_denied(sample(phase="live"), "invalid_synthetic_fixture")

    def test_valid_synthetic_never_reports_deploy(self) -> None:
        proc, result = run(sample())
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertEqual(result, {
            "mode": "offline_preflight", "status": "SYNTHETIC_ONLY",
            "reason": "synthetic_checks_passed", "deploy_executed": False,
            "production_ready": False, "readiness": "UNKNOWN",
        })
        for forbidden in ("VALIDATED_IN_PRODUCTION", "DEPLOYED", "receipt_id", "DEPLOY_VERIFIED"):
            self.assertNotIn(forbidden, proc.stdout)

    def test_real_mode_and_network_side_effects_denied(self) -> None:
        with TemporaryDirectory(prefix="cb909-offline-") as tmp:
            base = Path(tmp)
            bin_dir = base / "bin"
            bin_dir.mkdir()
            sentinel = base / "external-called"
            for name in ("ssh", "scp", "curl", "wget", "rsync", "git", "gh", "hostinger"):
                p = bin_dir / name
                p.write_text(f"#!/bin/sh\nprintf called > '{sentinel}'\nexit 97\n", encoding="utf-8")
                p.chmod(0o700)
            env = dict(os.environ)
            env["PATH"] = str(bin_dir) + os.pathsep + env.get("PATH", "")
            env["DEPLOY_SSH_KEY"] = "synthetic-secret-never-print"
            proc, result = run(sample(), "--real", env=env, cwd=tmp)
            self.assertEqual(proc.returncode, 2)
            self.assertEqual(result["reason"], "mode_not_authorized")
            proc, result = run(sample(secret="synthetic-secret-never-print"), env=env, cwd=tmp)
            self.assertEqual(proc.returncode, 2)
            self.assertEqual(result["reason"], "invalid_synthetic_fixture")
            self.assertNotIn("synthetic-secret-never-print", proc.stdout + proc.stderr)
            proc, result = run(sample(), env=env, cwd=tmp)
            self.assertEqual(proc.returncode, 0)
            self.assertEqual(result["status"], "SYNTHETIC_ONLY")
            self.assertFalse(sentinel.exists())
            self.assertEqual(sorted(p.name for p in base.iterdir()), ["bin"])
        for invalid in ('{"kind":"synthetic","kind":"real"}', "x" * 8193, '{"kind":NaN}'):
            proc, result = run(sample(), raw=invalid)
            self.assertEqual(proc.returncode, 2)
            self.assertEqual(result["reason"], "invalid_synthetic_fixture")


if __name__ == "__main__":
    unittest.main()
