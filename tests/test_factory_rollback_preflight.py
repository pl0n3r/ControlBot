"""AC-01..04: synthetic rollback preflight cannot acquire production authority."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "ops/factory/rollback"


def sample(**changes: object) -> dict[str, object]:
    result: dict[str, object] = {
        "kind": "synthetic", "phase": "construccion", "plan_kind": "git_revert",
        "candidate_sha": "a" * 40, "previous_healthy_sha": "b" * 40,
        "expected_healthy_sha": "b" * 40, "baseline_verified": True,
        "previous_artifact_verified": True, "rollback_plan_verified": True,
    }
    result.update(changes)
    return result


def run(payload: object, *args: str, raw: str | None = None,
        env: dict[str, str] | None = None, cwd: str | None = None):
    process = subprocess.run(
        [sys.executable, str(ADAPTER), *(args or ("--offline-dry-run",))],
        input=json.dumps(payload) if raw is None else raw,
        text=True, capture_output=True, cwd=cwd or ROOT,
        env=env, timeout=8, check=False,
    )
    return process, json.loads(process.stdout)


class FactoryRollbackPreflightTests(unittest.TestCase):
    def assert_denied(self, payload: object, reason: str) -> None:
        process, output = run(payload)
        self.assertEqual(process.returncode, 2, process.stderr)
        self.assertEqual(process.stderr, "")
        self.assertEqual(output["status"], "REJECTED")
        self.assertEqual(output["reason"], reason)
        self.assertIs(output["rollback_executed"], False)
        self.assertIs(output["production_ready"], False)
        self.assertEqual(output["readiness"], "UNKNOWN")

    def test_missing_safe_restore_baseline_rejected(self) -> None:
        self.assert_denied(sample(baseline_verified=False), "baseline_unverified")
        self.assert_denied(sample(previous_artifact_verified=False), "previous_artifact_unverified")
        self.assert_denied(sample(rollback_plan_verified=False), "rollback_plan_unverified")
        incomplete = sample()
        del incomplete["baseline_verified"]
        self.assert_denied(incomplete, "invalid_synthetic_fixture")
        self.assert_denied(sample(baseline_verified=1), "invalid_synthetic_fixture")

    def test_mismatched_or_stale_release_identity_rejected(self) -> None:
        self.assert_denied(sample(expected_healthy_sha="c" * 40), "healthy_sha_mismatch")
        self.assert_denied(sample(candidate_sha="b" * 40), "no_prior_release")
        self.assert_denied(sample(candidate_sha="A" * 40), "invalid_synthetic_fixture")
        self.assert_denied(sample(previous_healthy_sha="not-sha"), "invalid_synthetic_fixture")
        self.assert_denied(sample(phase="live"), "invalid_synthetic_fixture")

    def test_valid_synthetic_dry_run_never_claims_rollback(self) -> None:
        process, output = run(sample())
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(output, {
            "mode": "offline_preflight", "status": "SYNTHETIC_ONLY",
            "reason": "synthetic_checks_passed", "rollback_executed": False,
            "production_ready": False, "readiness": "UNKNOWN",
        })
        for disallowed in ("ROLLBACK_VERIFIED", "VALIDATED_IN_PRODUCTION", "receipt_id", "restored_at"):
            self.assertNotIn(disallowed, process.stdout)

    def test_real_mode_and_external_side_effects_denied(self) -> None:
        with TemporaryDirectory(prefix="cb905-offline-") as temp:
            work = Path(temp)
            bin_dir = work / "bin"
            bin_dir.mkdir()
            sentinel = work / "external-called"
            for cmd in ("ssh", "scp", "curl", "wget", "rsync", "gh", "hostinger", "git"):
                tool = bin_dir / cmd
                tool.write_text(f"#!/bin/sh\nprintf called > {sentinel}\nexit 97\n", encoding="utf-8")
                tool.chmod(0o700)
            env = os.environ.copy()
            env["PATH"] = str(bin_dir) + os.pathsep + env.get("PATH", "")
            env["DEPLOY_SSH_KEY"] = "test-secret-not-for-output"
            process, output = run(sample(), "--real", env=env, cwd=temp)
            self.assertEqual(process.returncode, 2)
            self.assertEqual(output["reason"], "mode_not_authorized")
            process, output = run(sample(secret="test-secret-not-for-output"), env=env, cwd=temp)
            self.assertEqual(process.returncode, 2)
            self.assertEqual(output["reason"], "invalid_synthetic_fixture")
            self.assertNotIn("test-secret-not-for-output", process.stdout + process.stderr)
            process, output = run(sample(), env=env, cwd=temp)
            self.assertEqual(process.returncode, 0)
            self.assertEqual(output["status"], "SYNTHETIC_ONLY")
            self.assertFalse(sentinel.exists())
            self.assertEqual(sorted(p.name for p in work.iterdir()), ["bin"])
        for malformed in ('{"kind":"synthetic","kind":"real"}', "x" * 8193, '{"a":NaN}'):
            process, output = run(sample(), raw=malformed)
            self.assertEqual(process.returncode, 2)
            self.assertEqual(output["reason"], "invalid_synthetic_fixture")


if __name__ == "__main__":
    unittest.main()
