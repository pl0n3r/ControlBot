"""#1370: offline periodic monitor, simulated HTTPS server and mail transport."""
from __future__ import annotations

from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "health-monitor.py"
SMOKE_TEST = ROOT / "tests" / "test_production_smoke.py"


def load(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Missing synthetic test dependency")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


monitor = load(SCRIPT, "controlbot_health_monitor")
base = load(SMOKE_TEST, "controlbot_fixture_smoke")


class HealthMonitorAlertsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Reuse actual synthetic TLS /health, protected route and login fixture #1365.
        base.ProductionSmokeTests.setUpClass.__func__(cls)

    def setUp(self):
        self.server.mode = "good"
        self.sent = []
        self.when = datetime(2026, 10, 30, 17, 45, tzinfo=timezone.utc)

    def fake_mail(self, recipients, subject, body):
        self.sent.append((recipients, subject, body))

    def real_synthetic_smoke(self, _timeout):
        return base.ProductionSmokeTests.run_smoke(self)

    def test_fallo_timeout_y_recuperacion(self):
        """AC-01: synthetic HTTPS server, mock email, failure, timeout, recovery."""
        recipients = ("owner@example.test", "qa@example.test")
        self.server.mode = "health_error"
        state, code = monitor.run_cycle(
            probe=self.real_synthetic_smoke, mail=self.fake_mail,
            recipients=recipients, version=base.VERSION,
            previous="HEALTHY", now=self.when,
        )
        self.assertEqual((state, code), ("ALERT", 2))
        self.assertEqual(len(self.sent), 1)
        to, subject, body = self.sent[-1]
        self.assertEqual(to, recipients)
        self.assertIn("ALERTA", subject)
        self.assertIn("smoke_NO_GO", body)
        self.assertIn("30/10/2026 12:45 PM (Colombia)", body)
        self.assertIn("Version esperada: " + base.VERSION, body)
        self.assertNotIn(base.SHA, body)
        self.assertNotIn(base.FIXTURE_TOKEN, body)
        self.assertNotIn(base.USER, body)

        def timeout(_):
            raise subprocess.TimeoutExpired(["synthetic"], timeout=2)

        state, code = monitor.run_cycle(
            probe=timeout, mail=self.fake_mail, recipients=recipients,
            version=base.VERSION, previous="HEALTHY", now=self.when,
        )
        self.assertEqual((state, code), ("ALERT", 2))
        self.assertIn("timeout_del_smoke", self.sent[-1][2])

        before = len(self.sent)
        state, code = monitor.run_cycle(
            probe=timeout, mail=self.fake_mail, recipients=recipients,
            version=base.VERSION, previous="ALERT", now=self.when,
        )
        self.assertEqual((state, code), ("ALERT", 2))
        self.assertEqual(len(self.sent), before, "Deduplicate until recovery")

        self.server.mode = "good"
        state, code = monitor.run_cycle(
            probe=self.real_synthetic_smoke, mail=self.fake_mail,
            recipients=recipients, version=base.VERSION,
            previous="ALERT", now=self.when,
        )
        self.assertEqual((state, code), ("HEALTHY", 0))
        self.assertIn("RECUPERADO", self.sent[-1][1])
        self.assertIn("smoke_verificado", self.sent[-1][2])

    def test_no_puede_verificar_y_mail_fallido_cierran(self):
        for probe in (lambda _: None, lambda _: object(),
                      lambda _: (_ for _ in ()).throw(OSError("fixture secret would leak"))):
            with self.subTest(probe=str(probe)):
                state, code = monitor.run_cycle(
                    probe=probe, mail=self.fake_mail, recipients=("owner@example.test",),
                    version=base.VERSION, previous="HEALTHY", now=self.when,
                )
                self.assertEqual((state, code), ("ALERT", 2))
        self.assertNotIn("fixture secret would leak", str(self.sent))

        def unavailable(*_args):
            raise OSError("SMTP unavailable")

        with self.assertRaises(OSError):
            monitor.run_cycle(probe=lambda _: subprocess.CompletedProcess([], 1),
                              mail=unavailable, recipients=("owner@example.test",),
                              version=base.VERSION, previous="HEALTHY", now=self.when)

    def test_receptores_privados_y_estado_atomico(self):
        with tempfile.TemporaryDirectory(prefix="cb-monitor-1370-") as name:
            folder = Path(name)
            recipients = folder / "recipients.json"
            recipients.write_text(json.dumps({"version": 1, "recipients": [
                "owner@example.test", "qa@example.test"]}))
            recipients.chmod(0o600)
            self.assertEqual(monitor.recipients_from_private_file(str(recipients)),
                             ("owner@example.test", "qa@example.test"))
            state_path = folder / "monitor.json"
            self.assertEqual(monitor.read_state(str(state_path)), "UNKNOWN")
            monitor.persist_state(str(state_path), "ALERT")
            self.assertEqual(monitor.read_state(str(state_path)), "ALERT")
            monitor.persist_state(str(state_path), "HEALTHY")
            self.assertEqual(monitor.read_state(str(state_path)), "HEALTHY")
            self.assertEqual(state_path.stat().st_mode & 0o777, 0o600)

            for content in (
                {"version": 1, "recipients": ["owner@real-company.com"]},
                {"version": 1, "recipients": ["owner@example.test", "owner@example.test"]},
                {"version": 1, "recipients": []},
                {"version": True, "recipients": ["owner@example.test"]},
                {"version": 1, "recipients": ["owner@example.test"], "secret": "unexpected"},
            ):
                recipients.write_text(json.dumps(content))
                with self.subTest(content=content), self.assertRaises(monitor.MonitorError):
                    monitor.recipients_from_private_file(str(recipients))
            recipients.chmod(0o644)
            with self.assertRaises(monitor.MonitorError):
                monitor.recipients_from_private_file(str(recipients))
            symlink = folder / "link.json"
            symlink.symlink_to(state_path)
            with self.assertRaises(monitor.MonitorError):
                monitor.read_state(str(symlink))
            with self.assertRaises(monitor.MonitorError):
                monitor.persist_state(str(symlink), "ALERT")

    def test_inactivo_y_produccion_no_hacen_red_ni_envian_correos(self):
        with mock.patch.dict(os.environ, {"CONTROLBOT_MONITOR_ENABLED": "0"}), \
                mock.patch.object(monitor.smtplib, "SMTP", side_effect=AssertionError("NO SMTP")):
            self.assertEqual(monitor.main(), 0)
        with mock.patch.dict(os.environ, {"CONTROLBOT_MONITOR_ENABLED": "1",
                                       "CONTROLBOT_PRODUCTION_STAGE": "live"}), \
                mock.patch.object(monitor.smtplib, "SMTP", side_effect=AssertionError("NO SMTP")):
            with self.assertRaises(monitor.MonitorError):
                monitor.main()
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn('"127.0.0.1"', source)
        self.assertIn('"construccion"', source)
        self.assertIn("production-smoke.sh", source)
        self.assertNotIn("hostinger.com", source)


if __name__ == "__main__":
    unittest.main()
