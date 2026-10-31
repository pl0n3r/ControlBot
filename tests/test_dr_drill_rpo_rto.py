#!/usr/bin/env python3
"""ControlBot #1371: disposable, synthetic disaster-recovery drill.

No production IO, credentials, GitHub calls, actual backups, or deployment.
The reported RPO/RTO are observations of this local simulation only.
"""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import shutil
import tempfile
import time
import unittest


GOAL_UNSET = "[COMPLETAR POR EL DUEÑO]"
ORIGINAL_EVENTS = [
    {"id": "fake-0001", "value": "synthetic fixture A"},
    {"id": "fake-0002", "value": "synthetic fixture B"},
]
UNBACKED_EVENT = {"id": "fake-0003", "value": "synthetic not backed up"}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def encode_events(events: list[dict[str, str]]) -> bytes:
    return (json.dumps(events, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def iso_utc(value: datetime) -> str:
    return value.isoformat(timespec="microseconds").replace("+00:00", "Z")


def perform_disposable_drill(*, tamper_backup: bool = False) -> dict:
    """Write, lose and restore synthetic event data inside one tempfile only.

    The elapsed RTO is timed with a monotonic clock. RPO is the difference
    between failure UTC and the latest intact backup UTC. There is no claim
    that these small local values represent an operational service's SLA.
    """
    with tempfile.TemporaryDirectory(prefix="controlbot-1371-dr-") as dirname:
        sandbox = Path(dirname)
        live_path = sandbox / "synthetic-live.json"
        backup_path = sandbox / "synthetic-backup.json"
        restored_path = sandbox / "synthetic-restored.json"

        # The backup is immutable for the rest of this simulation.
        baseline = encode_events(ORIGINAL_EVENTS)
        live_path.write_bytes(baseline)
        backup_path.write_bytes(live_path.read_bytes())
        backup_sha = sha256(baseline)
        backup_at = utc_now()
        time.sleep(0.02)  # Ensure a real, non-zero observable recovery window.

        # Change the live fixture after the last recoverable point.
        changed = encode_events(ORIGINAL_EVENTS + [UNBACKED_EVENT])
        live_path.write_bytes(changed)
        if live_path.read_bytes() != changed:
            raise AssertionError("The synthetic live-state mutation did not persist")
        time.sleep(0.02)

        failure_at = utc_now()
        started = time.perf_counter()
        live_path.unlink()  # Simulated crash of a disposable fixture only.
        if live_path.exists():
            raise AssertionError("Synthetic incident did not remove live data")

        if tamper_backup:
            backup_path.write_bytes(backup_path.read_bytes() + b"TAMPERED")
        source = backup_path.read_bytes()
        if not source or sha256(source) != backup_sha:
            raise ValueError("Restore blocked: synthetic backup SHA-256 invalid")

        shutil.copyfile(backup_path, restored_path)
        restored = restored_path.read_bytes()
        restored_sha = sha256(restored)
        recovered_events = json.loads(restored.decode("utf-8"))
        if restored_sha != backup_sha or recovered_events != ORIGINAL_EVENTS:
            raise ValueError("Restore blocked: recovered synthetic data mismatch")
        if UNBACKED_EVENT in recovered_events:
            raise AssertionError("Data written after the backup must be lost")

        recovered_at = utc_now()
        measured_rto = time.perf_counter() - started
        measured_rpo = (failure_at - backup_at).total_seconds()
        if measured_rpo <= 0 or measured_rto <= 0:
            raise AssertionError("Missing monotonic RTO / measured RPO")
        return {
            "scope": "disposable_synthetic_local_only",
            "status": "PASSED_SYNTHETIC_ONLY",
            "backup_at_utc": iso_utc(backup_at),
            "failure_at_utc": iso_utc(failure_at),
            "restored_at_utc": iso_utc(recovered_at),
            "rpo_observed_seconds": round(measured_rpo, 6),
            "rto_observed_seconds": round(measured_rto, 6),
            "rpo_objective": GOAL_UNSET,
            "rto_objective": GOAL_UNSET,
            "events_at_last_backup": len(ORIGINAL_EVENTS),
            "events_at_failure": len(ORIGINAL_EVENTS) + 1,
            "events_recovered": len(recovered_events),
            "events_lost_in_simulation": 1,
            "backup_sha256": backup_sha,
            "restored_sha256": restored_sha,
            "integrity_verified": True,
            "real_environment_verified": False,
        }


class DrDrillRpoRtoTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        report = perform_disposable_drill()
        self.assertEqual(report["status"], "PASSED_SYNTHETIC_ONLY")
        self.assertEqual(report["scope"], "disposable_synthetic_local_only")
        self.assertTrue(report["integrity_verified"])
        self.assertFalse(report["real_environment_verified"])
        self.assertEqual(report["events_recovered"], 2)
        self.assertEqual(report["events_at_failure"], 3)
        self.assertEqual(report["events_lost_in_simulation"], 1)
        self.assertEqual(report["backup_sha256"], report["restored_sha256"])
        self.assertRegex(report["restored_sha256"], r"^[0-9a-f]{64}$")
        for name in ("rpo_observed_seconds", "rto_observed_seconds"):
            self.assertGreater(report[name], 0, f"{name} must be measured")
        for name in ("backup_at_utc", "failure_at_utc", "restored_at_utc"):
            self.assertTrue(report[name].endswith("Z"))
            self.assertIsNotNone(datetime.fromisoformat(report[name].replace("Z", "+00:00")))
        self.assertEqual(report["rpo_objective"], GOAL_UNSET)
        self.assertEqual(report["rto_objective"], GOAL_UNSET)

    def test_corrupted_backup_fails_closed(self) -> None:
        with self.assertRaisesRegex(ValueError, "backup SHA-256 invalid"):
            perform_disposable_drill(tamper_backup=True)

    def test_report_does_not_claim_production_or_sla(self) -> None:
        report = perform_disposable_drill()
        self.assertEqual(report["status"], "PASSED_SYNTHETIC_ONLY")
        self.assertEqual(report["real_environment_verified"], False)
        self.assertEqual(report["rpo_objective"], "[COMPLETAR POR EL DUEÑO]")
        self.assertEqual(report["rto_objective"], "[COMPLETAR POR EL DUEÑO]")
        self.assertNotIn("token", json.dumps(report).lower())
        self.assertNotIn("password", json.dumps(report).lower())

    def test_documentation_is_explicitly_offline(self) -> None:
        doc = (Path(__file__).resolve().parents[1] / "docs" / "dr-drill-report.md").read_text(encoding="utf-8")
        for text in ("#1371", "RPO", "RTO", "[COMPLETAR POR EL DUEÑO]",
                     "test_criterio_principal", "NO", "sintéticos"):
            self.assertIn(text, doc)


if __name__ == "__main__":
    unittest.main()
