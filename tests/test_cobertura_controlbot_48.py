"""#1231: behavioral regression for offline Factory backup/deploy adapters.

Execute checked-in production adapters with fabricated fixtures only.  Successful
synthetic validation never conveys Hostinger, backup, deploy or live authority.
No coverage-matrix assertions, network, credentials, or customer data.
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
ADAPTERS = {
    "backup": ROOT / "ops" / "factory" / "backup",
    "deploy": ROOT / "ops" / "factory" / "deploy",
}


def backup(**changes: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic", "source_sha": "a" * 40,
        "artifact_sha256": "b" * 64, "baseline_verified": True,
        "artifact_verified": True, "rollback_verified": True,
    }
    sample.update(changes)
    return sample


def deploy(**changes: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic", "phase": "construccion", "transport": "none",
        "candidate_sha": "a" * 40, "expected_candidate_sha": "a" * 40,
        "artifact_sha256": "c" * 64, "baseline_sha": "b" * 40,
        "expected_baseline_sha": "b" * 40, "baseline_verified": True,
        "artifact_verified": True, "backup_verified": True,
        "rollback_verified": True,
    }
    sample.update(changes)
    return sample


def execute(
    adapter: str, payload: object, *, mode: tuple[str, ...] = ("--offline-dry-run",),
    raw: str | None = None, env: dict[str, str] | None = None,
    cwd: str | None = None,
) -> tuple[subprocess.CompletedProcess[str], dict[str, object]]:
    """Call the actual repository executable; never a mock of its rules."""
    if adapter not in ADAPTERS:
        raise AssertionError("Adapter must be explicitly allowlisted")
    proc = subprocess.run(
        [sys.executable, str(ADAPTERS[adapter]), *mode],
        input=json.dumps(payload) if raw is None else raw,
        text=True, capture_output=True, check=False,
        timeout=10, cwd=cwd or ROOT, env=env,
    )
    try:
        result = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("Adapter failed to return one JSON result") from exc
    if not isinstance(result, dict):
        raise AssertionError("Adapter returned non-object result")
    return proc, result


class CoberturaControlbot48Tests(unittest.TestCase):
    def assert_denied(self, name: str, payload: object, reason: str, **opts: object) -> None:
        proc, result = execute(name, payload, **opts)
        self.assertEqual(proc.returncode, 2, proc.stderr)
        self.assertEqual(proc.stderr, "")
        self.assertEqual(result["mode"], "offline_preflight")
        self.assertEqual(result["status"], "REJECTED")
        self.assertEqual(result["reason"], reason)
        self.assertEqual(result["readiness"], "UNKNOWN")
        if name == "backup":
            self.assertIs(result["backup_verified"], False)
            self.assertIs(result["live_authorized"], False)
        else:
            self.assertIs(result["deploy_executed"], False)
            self.assertIs(result["production_ready"], False)

    def test_contrato_principal(self) -> None:
        """AC-01: every offline-predicate checks actual adapter behavior."""
        bproc, b = execute("backup", backup())
        dproc, d = execute("deploy", deploy())
        mismatched, mismatch = execute("deploy", deploy(expected_baseline_sha="d" * 40))
        bad_mode, no_live = execute("backup", backup(), mode=("--real",))
        bad_transport, transport = execute("deploy", deploy(transport="hostinger-ssh"))
        expected_backup = {
            "mode": "offline_preflight", "status": "SYNTHETIC_ONLY",
            "reason": "synthetic_checks_passed", "backup_verified": False,
            "live_authorized": False, "readiness": "UNKNOWN",
        }
        expected_deploy = {
            "mode": "offline_preflight", "status": "SYNTHETIC_ONLY",
            "reason": "synthetic_checks_passed", "deploy_executed": False,
            "production_ready": False, "readiness": "UNKNOWN",
        }
        predicates = {
            "CB-0794": b == expected_backup and bproc.returncode == 0,
            "CB-0797": (b["backup_verified"] is False and
                        b["live_authorized"] is False and b["readiness"] == "UNKNOWN"),
            "CB-0798": bad_mode.returncode == 2 and no_live["reason"] == "mode_not_authorized",
            "CB-0799": bproc.stderr == "" and bproc.returncode == 0 and
                       "receipt_id" not in bproc.stdout,
            "CB-0801": bproc.stderr == "" and dproc.stderr == "" and
                       b["mode"] == d["mode"] == "offline_preflight",
            "CB-0806": (b["backup_verified"] is False and
                        d["deploy_executed"] is False),
            "CB-0808": (b["readiness"] == "UNKNOWN" and
                        b["status"] != "PRODUCTION_GREEN"),
            "CB-0809": d == expected_deploy and dproc.returncode == 0,
            "CB-0810": (d["status"] == "SYNTHETIC_ONLY" and
                        d["production_ready"] is False),
            "CB-0811": (mismatched.returncode == 2 and
                        mismatch["reason"] == "baseline_sha_mismatch"),
            "CB-0812": (bad_transport.returncode == 2 and
                        transport["reason"] == "invalid_synthetic_fixture"),
            "CB-0813": (d["deploy_executed"] is False and
                        d["production_ready"] is False and d["readiness"] == "UNKNOWN"),
        }
        self.assertEqual(len(predicates), 12)
        for row, actual in predicates.items():
            with self.subTest(row=row):
                self.assertTrue(actual, f"No executable evidence for {row}")

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed, missing, ambiguous or unauthorized input denies."""
        for name, valid in (("backup", backup()), ("deploy", deploy())):
            with self.subTest(adapter=name, case="wrong-mode"):
                self.assert_denied(name, valid, "mode_not_authorized", mode=())
            for raw in ('{"kind":"synthetic","kind":"live"}', "x" * 8193):
                with self.subTest(adapter=name, case="bad-json"):
                    self.assert_denied(name, valid, "invalid_synthetic_fixture", raw=raw)
            with self.subTest(adapter=name, case="extra-secret"):
                self.assert_denied(name, {**valid, "token": "synthetic-canary"},
                                   "invalid_synthetic_fixture")
        for changes, reason in (
            ({"baseline_verified": False}, "baseline_unverified"),
            ({"artifact_verified": False}, "artifact_unverified"),
            ({"rollback_verified": False}, "rollback_unverified"),
            ({"source_sha": "INVALID"}, "invalid_synthetic_fixture"),
            ({"baseline_verified": "true"}, "invalid_synthetic_fixture"),
        ):
            with self.subTest(adapter="backup", change=changes):
                self.assert_denied("backup", backup(**changes), reason)
        for changes, reason in (
            ({"backup_verified": False}, "backup_unverified"),
            ({"rollback_verified": False}, "rollback_unverified"),
            ({"expected_candidate_sha": "d" * 40}, "candidate_sha_mismatch"),
            ({"baseline_sha": "a" * 40, "expected_baseline_sha": "a" * 40},
             "no_release_transition"),
            ({"phase": "live"}, "invalid_synthetic_fixture"),
            ({"kind": "production"}, "invalid_synthetic_fixture"),
        ):
            with self.subTest(adapter="deploy", change=changes):
                self.assert_denied("deploy", deploy(**changes), reason)

    def test_sin_datos_reales(self) -> None:
        """AC-03: canary PATH and environment prove no external calls/echo/files."""
        with tempfile.TemporaryDirectory(prefix="controlbot-offline-48-") as temp:
            folder = Path(temp)
            executable_dir = folder / "bin"
            executable_dir.mkdir()
            sentinel = folder / "external-command-called"
            for cmd in ("gh", "git", "curl", "wget", "ssh", "scp", "hostinger", "rsync"):
                executable = executable_dir / cmd
                executable.write_text(
                    "#!/bin/sh\nprintf called > '" + str(sentinel) + "'\nexit 97\n",
                    encoding="utf-8",
                )
                executable.chmod(0o700)
            env = dict(os.environ)
            env["PATH"] = str(executable_dir) + os.pathsep + env.get("PATH", "")
            env["HOSTINGER_SSH_HOST"] = "invalid.example"
            env["GITHUB_TOKEN"] = "synthetic-canary-not-a-token"
            for name, valid in (("backup", backup()), ("deploy", deploy())):
                with self.subTest(adapter=name):
                    proc, output = execute(name, valid, env=env, cwd=temp)
                    self.assertEqual(proc.returncode, 0, proc.stderr)
                    self.assertEqual(output["status"], "SYNTHETIC_ONLY")
                    self.assertEqual(output["readiness"], "UNKNOWN")
                    self.assertNotIn("synthetic-canary-not-a-token", proc.stdout + proc.stderr)
                    self.assertNotIn("invalid.example", proc.stdout + proc.stderr)
                    self.assertFalse(sentinel.exists())
                    self.assertEqual(sorted(x.name for x in folder.iterdir()), ["bin"])
        self.assertFalse(ADAPTERS["backup"].is_dir())
        self.assertFalse(ADAPTERS["deploy"].is_dir())


if __name__ == "__main__":
    unittest.main()
