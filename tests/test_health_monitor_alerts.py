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

    def test_cli_sintetica_con_alerta_y_recuperacion(self):
        """Exercise the real CLI, HTTPS fixture and fully mocked SMTP transport."""
        with tempfile.TemporaryDirectory(prefix="cb-monitor-tick-") as temp:
            folder = Path(temp)
            recipients = folder / "recipients.json"
            recipients.write_text(json.dumps({
                "version": 1, "recipients": ["owner@example.test"]
            }), encoding="utf-8")
            recipients.chmod(0o600)
            state = folder / "monitor-state.json"
            sent = []

            class FakeSMTP:
                def __init__(self, host, port, timeout):
                    assert (host, port, timeout) == ("127.0.0.1", 1025, 3)

                def __enter__(self):
                    return self

                def __exit__(self, *_args):
                    return False

                def send_message(self, message):
                    sent.append(message)

            config = {
                "CONTROLBOT_MONITOR_ENABLED": "1",
                "CONTROLBOT_PRODUCTION_STAGE": "construccion",
                "CONTROLBOT_MONITOR_RECIPIENTS_FILE": str(recipients),
                "CONTROLBOT_MONITOR_STATE_FILE": str(state),
                "CONTROLBOT_MONITOR_SMTP_PORT": "1025",
                "SMOKE_BASE_URL": f"https://127.0.0.1:{self.server.server_port}",
                "SMOKE_EXPECTED_SHA": base.SHA,
                "SMOKE_EXPECTED_VERSION": base.VERSION,
                "SMOKE_USERNAME_FILE": str(self.owner_file),
                "SMOKE_PASSWORD_FILE": str(self.fixture_file),
                "SMOKE_CA_FILE": str(self.cert),
            }
            with mock.patch.dict(os.environ, config), mock.patch.object(
                monitor.smtplib, "SMTP", FakeSMTP
            ):
                self.server.mode = "health_error"
                self.assertEqual(monitor.main(), 2)
                self.assertEqual(monitor.read_state(str(state)), "ALERT")
                self.assertEqual(len(sent), 1)
                self.assertIn("ALERTA", sent[-1]["Subject"])
                self.assertIn("Version esperada: " + base.VERSION,
                              sent[-1].get_content())
                self.assertNotIn(base.FIXTURE_TOKEN, sent[-1].as_string())

                self.assertEqual(monitor.main(), 2)
                self.assertEqual(len(sent), 1, "Repeated failure is deduplicated")

                self.server.mode = "good"
                self.assertEqual(monitor.main(), 0)
                self.assertEqual(monitor.read_state(str(state)), "HEALTHY")
                self.assertEqual(len(sent), 2)
                self.assertIn("RECUPERADO", sent[-1]["Subject"])
                self.assertEqual(state.stat().st_mode & 0o777, 0o600)

    def test_cli_rechaza_configuracion_insegura_sin_red(self):
        """Reject invalid URL, SMTP and state before opening any network transport."""
        good = {
            "CONTROLBOT_MONITOR_ENABLED": "1",
            "CONTROLBOT_PRODUCTION_STAGE": "construccion",
            "SMOKE_BASE_URL": "https://127.0.0.1:8443",
            "CONTROLBOT_MONITOR_SMTP_PORT": "1025",
        }
        for override in (
            {"SMOKE_BASE_URL": "https://external.example.test"},
            {"SMOKE_BASE_URL": "http://127.0.0.1:8443"},
            {"SMOKE_BASE_URL": "https://user:pass@127.0.0.1"},
            {"CONTROLBOT_MONITOR_SMTP_PORT": "0"},
            {"CONTROLBOT_MONITOR_SMTP_PORT": "not-a-port"},
            {"CONTROLBOT_MONITOR_SMTP_PORT": "65536"},
        ):
            with self.subTest(override=override):
                with mock.patch.dict(os.environ, {**good, **override}), (
                    mock.patch.object(monitor.smtplib, "SMTP",
                                      side_effect=AssertionError("No SMTP expected"))
                ):
                    with self.assertRaises(monitor.MonitorError):
                        monitor.main()

        with tempfile.TemporaryDirectory(prefix="cb-state-invalid-") as temp:
            state = Path(temp) / "state.json"
            state.write_text('{"version":true,"state":"ALERT"}', encoding="utf-8")
            state.chmod(0o600)
            with self.assertRaises(monitor.MonitorError):
                monitor.read_state(str(state))
            state.write_text("not json", encoding="utf-8")
            with self.assertRaises(monitor.MonitorError):
                monitor.read_state(str(state))
            with self.assertRaises(monitor.MonitorError):
                monitor.persist_state(str(state), "INVALID")
            with self.assertRaises(monitor.MonitorError):
                monitor.persist_state("relative/state.json", "ALERT")

        with self.assertRaises(monitor.MonitorError):
            monitor.format_colombia(datetime(2026, 10, 30))
        with self.assertRaises(monitor.MonitorError):
            monitor.run_cycle(probe=lambda _: None, mail=self.fake_mail,
                              recipients=("owner@example.test",), version="bad",
                              previous="HEALTHY", now=self.when)
        with self.assertRaises(monitor.MonitorError):
            monitor.run_cycle(probe=lambda _: None, mail=self.fake_mail,
                              recipients=("owner@example.test",), version=base.VERSION,
                              previous="HEALTHY", now=self.when, timeout=0)

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
