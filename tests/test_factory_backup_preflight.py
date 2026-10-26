"""Executable acceptance for the offline-only factory backup preflight (#902).

All inputs are synthetic. These tests never contact a host or use real credentials.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BACKUP = ROOT / "ops" / "factory" / "backup"


def fixture(**overrides: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic",
        "source_sha": "a" * 40,
        "artifact_sha256": "b" * 64,
        "baseline_verified": True,
        "artifact_verified": True,
        "rollback_verified": True,
    }
    sample.update(overrides)
    return sample


def execute(
    payload: object, *args: str, env: dict[str, str] | None = None,
    cwd: str | None = None, raw: str | None = None,
) -> tuple[subprocess.CompletedProcess[str], dict[str, object]]:
    document = json.dumps(payload) if raw is None else raw
    completed = subprocess.run(
        [sys.executable, str(BACKUP), *(args or ("--offline-dry-run",))],
        input=document, text=True, capture_output=True, check=False,
        cwd=cwd or ROOT, env=env, timeout=8,
    )
    return completed, json.loads(completed.stdout)


class FactoryBackupPreflightTests(unittest.TestCase):
    def assert_rejected(self, payload: object, expected: str) -> None:
        run, result = execute(payload)
        self.assertEqual(run.returncode, 2, run.stderr)
        self.assertEqual(run.stderr, "")
        self.assertEqual(result["status"], "REJECTED")
        self.assertEqual(result["reason"], expected)
        self.assertIs(result["backup_verified"], False)
        self.assertIs(result["live_authorized"], False)
        self.assertEqual(result["readiness"], "UNKNOWN")

    def test_missing_verified_baseline_fails_closed(self) -> None:
        self.assert_rejected(fixture(baseline_verified=False), "baseline_unverified")
        without_baseline = fixture()
        del without_baseline["baseline_verified"]
        self.assert_rejected(without_baseline, "invalid_synthetic_fixture")
        self.assert_rejected(fixture(kind="production"), "invalid_synthetic_fixture")
        self.assert_rejected(fixture(baseline_verified="true"), "invalid_synthetic_fixture")

    def test_unverified_rollback_or_artifact_fails_closed(self) -> None:
        self.assert_rejected(fixture(rollback_verified=False), "rollback_unverified")
        self.assert_rejected(fixture(artifact_verified=False), "artifact_unverified")
        self.assert_rejected(fixture(artifact_sha256="not-verified"), "invalid_synthetic_fixture")
        self.assert_rejected(fixture(source_sha="not-a-commit"), "invalid_synthetic_fixture")

    def test_offline_dry_run_never_emits_production_backup_receipt(self) -> None:
        run, result = execute(fixture())
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(run.stderr, "")
        self.assertEqual(result, {
            "mode": "offline_preflight",
            "status": "SYNTHETIC_ONLY",
            "reason": "synthetic_checks_passed",
            "backup_verified": False,
            "live_authorized": False,
            "readiness": "UNKNOWN",
        })
        for forbidden in ("BACKUP_VERIFIED", "backup_receipt", "receipt_id", "restored_at"):
            self.assertNotIn(forbidden, run.stdout)

    def test_no_network_secrets_or_hostinger_side_effects(self) -> None:
        with tempfile.TemporaryDirectory(prefix="cb902-offline-") as tmp:
            work = Path(tmp)
            bin_dir = work / "bin"
            bin_dir.mkdir()
            sentinel = work / "external-called"
            for command in ("ssh", "scp", "curl", "wget", "rsync", "gh", "hostinger"):
                exe = bin_dir / command
                exe.write_text(
                    "#!/bin/sh\nprintf called > " + str(sentinel) + "\nexit 97\n",
                    encoding="utf-8",
                )
                exe.chmod(0o700)
            environment = os.environ.copy()
            environment["PATH"] = str(bin_dir) + os.pathsep + environment.get("PATH", "")
            environment["HOSTINGER_SSH_HOST"] = "invalid.example"
            environment["BACKUP_PASSWORD"] = "do-not-expose-test-token"
            run, result = execute(fixture(), "--real", env=environment, cwd=tmp)
            self.assertEqual(run.returncode, 2)
            self.assertEqual(result["reason"], "mode_not_authorized")
            run, result = execute(
                fixture(api_token="do-not-expose-test-token"),
                env=environment, cwd=tmp,
            )
            self.assertEqual(run.returncode, 2)
            self.assertEqual(result["reason"], "invalid_synthetic_fixture")
            self.assertNotIn("do-not-expose-test-token", run.stdout + run.stderr)
            self.assertFalse(sentinel.exists(), "External command was invoked")
            self.assertFalse((work / "backup.tar.gz").exists())
            run, result = execute(fixture(), env=environment, cwd=tmp)
            self.assertEqual(run.returncode, 0)
            self.assertEqual(result["status"], "SYNTHETIC_ONLY")
            self.assertFalse(sentinel.exists())
            self.assertEqual(sorted(p.name for p in work.iterdir()), ["bin"])
        # Duplicate keys and excessive bodies must fail without echoing the payload.
        run, result = execute(fixture(), raw='{"kind":"synthetic","kind":"production"}')
        self.assertEqual((run.returncode, result["reason"]), (2, "invalid_synthetic_fixture"))
        run, result = execute(fixture(), raw="x" * 8193)
        self.assertEqual((run.returncode, result["reason"]), (2, "invalid_synthetic_fixture"))


if __name__ == "__main__":
    unittest.main()
