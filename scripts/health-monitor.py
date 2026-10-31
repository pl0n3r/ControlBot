#!/usr/bin/env python3
"""Offline-only periodic health smoke. One invocation per external cron tick.
Construction permits only loopback HTTPS and local synthetic SMTP; no real
provider, recipient or live deployment authority is enabled.
"""
from __future__ import annotations
from datetime import datetime, timezone
from email.message import EmailMessage
import json
import os
from pathlib import Path
import re
import smtplib
import stat
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo
ROOT = Path(__file__).resolve().parents[1]
SMOKE = ROOT / "scripts" / "production-smoke.sh"
COLOMBIA = ZoneInfo("America/Bogota")
ADDRESS = re.compile(r"[A-Za-z0-9._+-]{1,64}@example\.test\Z")
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")

class MonitorError(Exception):
    """Safe error; never echo private environment values."""

def private_file(path: str, limit: int = 4096) -> bytes:
    if not path or not os.path.isabs(path):
        raise MonitorError("private_file_required")
    try:
        meta = os.lstat(path)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_mode & 0o077 or (hasattr(os, "getuid") and meta.st_uid != os.getuid()) or meta.st_size < 1 or meta.st_size > limit):
            raise MonitorError("unsafe_private_file")
        with open(path, "rb") as stream:
            raw = stream.read(limit + 1)
        if not raw or len(raw) > limit:
            raise MonitorError("invalid_private_file")
        return raw
    except (OSError, ValueError) as error:
        raise MonitorError("private_file_unreadable") from error

def recipients_from_private_file(path: str) -> tuple[str, ...]:
    try:
        value = json.loads(private_file(path))
    except (ValueError, UnicodeError) as error:
        raise MonitorError("recipients_invalid") from error
    if (not isinstance(value, dict) or set(value) != {"version", "recipients"} or type(value["version"]) is not int or value["version"] != 1 or not isinstance(value["recipients"], list)
            or not 1 <= len(value["recipients"]) <= 10):
        raise MonitorError("recipients_invalid")
    addresses = value["recipients"]
    if (any(not isinstance(addr, str) or ADDRESS.fullmatch(addr) is None for addr in addresses) or len(set(addresses)) != len(addresses)):
        raise MonitorError("recipients_invalid")
    return tuple(addresses)

def read_state(path: str) -> str:
    if not os.path.isabs(path):
        raise MonitorError("state_path_invalid")
    if not os.path.lexists(path):
        return "UNKNOWN"
    try:
        result = json.loads(private_file(path, 128))
    except (ValueError, UnicodeError) as error:
        raise MonitorError("state_invalid") from error
    if (not isinstance(result, dict) or set(result) != {"version", "state"} or type(result["version"]) is not int or result["version"] != 1 or result["state"] not in ("HEALTHY", "ALERT")):
        raise MonitorError("state_invalid")
    return result["state"]

def persist_state(path: str, state: str) -> None:
    """Atomic replacement; never follow symlink targets in the destination."""
    if not os.path.isabs(path) or state not in ("HEALTHY", "ALERT"):
        raise MonitorError("state_path_invalid")
    parent = Path(path).parent
    if not parent.is_dir() or parent.is_symlink():
        raise MonitorError("state_parent_invalid")
    if os.path.lexists(path):
        private_file(path, 128)
    tmp = ""
    try:
        fd, tmp = tempfile.mkstemp(prefix=".monitor-", dir=parent)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump({"version": 1, "state": state}, stream, separators=(",", ":"))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp, path)
    except OSError as error:
        raise MonitorError("state_write_failed") from error
    finally:
        if tmp and os.path.lexists(tmp):
            os.unlink(tmp)

def format_colombia(now: datetime) -> str:
    if now.tzinfo is None:
        raise MonitorError("timestamp_without_timezone")
    return now.astimezone(COLOMBIA).strftime("%d/%m/%Y %I:%M %p (Colombia)")

def assess(probe, timeout: int) -> tuple[str, str]:
    """Do not relay underlying smoke output: it might include secrets."""
    try:
        result = probe(timeout)
    except subprocess.TimeoutExpired:
        return "ALERT", "timeout_del_smoke"
    except Exception:
        return "ALERT", "verificacion_no_disponible"
    if type(getattr(result, "returncode", None)) is not int:
        return "ALERT", "resultado_sin_verificar"
    if result.returncode == 0:
        return "HEALTHY", "smoke_verificado"
    return "ALERT", "smoke_NO_GO_https_health_login_o_release"

def run_cycle(*, probe, mail, recipients: tuple[str, ...], version: str, previous: str, now: datetime, timeout: int = 15) -> tuple[str, int]:
    """Returns state, exit code; every failed or unverified smoke is nonzero."""
    if not VERSION.fullmatch(version) or previous not in ("UNKNOWN", "HEALTHY", "ALERT"):
        raise MonitorError("monitor_contract_invalid")
    if not isinstance(timeout, int) or not 1 <= timeout <= 60:
        raise MonitorError("timeout_invalid")
    state, reason = assess(probe, timeout)
    if state != previous and (state == "ALERT" or previous == "ALERT"):
        title = "ALERTA" if state == "ALERT" else "RECUPERADO"
        subject = f"ControlBot /health {title} (simulacion)"
        body = (f"Estado: {state}\nHora: {format_colombia(now)}\n" f"Resultado: {reason}\nVersion esperada: {version}\n"
                "Sin datos personales ni credenciales. Evidencia productiva: NO VERIFICADA.\n")
        mail(recipients, subject, body)
    return state, (0 if state == "HEALTHY" else 2)

def local_mail(port: int):

    def send(addresses: tuple[str, ...], subject: str, body: str) -> None:
        email = EmailMessage()
        email["From"] = "monitor@example.test"
        email["To"] = ", ".join(addresses)
        email["Subject"] = subject
        email.set_content(body)
        with smtplib.SMTP("127.0.0.1", port, timeout=3) as smtp:
            smtp.send_message(email)
    return send

def main() -> int:
    if os.environ.get("CONTROLBOT_MONITOR_ENABLED") != "1":
        print("monitor disabled (fail-closed, no probe or email)")
        return 0
    if os.environ.get("CONTROLBOT_PRODUCTION_STAGE") != "construccion":
        raise MonitorError("offline_only_live_not_authorized")
    url = urlsplit(os.environ.get("SMOKE_BASE_URL", ""))
    if (url.scheme != "https" or url.hostname != "127.0.0.1" or url.username or url.password or url.path not in ("", "/") or url.query or url.fragment):
        raise MonitorError("loopback_https_required")
    port = os.environ.get("CONTROLBOT_MONITOR_SMTP_PORT", "")
    if not port.isascii() or not port.isdigit() or not 1 <= int(port) <= 65535:
        raise MonitorError("loopback_smtp_required")
    recipients = recipients_from_private_file(os.environ.get("CONTROLBOT_MONITOR_RECIPIENTS_FILE", ""))
    path = os.environ.get("CONTROLBOT_MONITOR_STATE_FILE", "")
    previous = read_state(path)
    version = os.environ.get("SMOKE_EXPECTED_VERSION", "")

    def probe(timeout: int):
        return subprocess.run(["bash", str(SMOKE)], cwd=ROOT, env=os.environ.copy(), capture_output=True, text=True, timeout=timeout, check=False)
    state, code = run_cycle(probe=probe, mail=local_mail(int(port)), recipients=recipients, version=version, previous=previous, now=datetime.now(timezone.utc))
    persist_state(path, state)
    print("monitor: " + state + " (synthetic; production not verified)")
    return code

if __name__ == "__main__":
    try:
        sys.exit(main())
    except MonitorError:
        print("monitor: NO-GO (invalid or unavailable private configuration)", file=sys.stderr)
        sys.exit(3)
    except Exception:
        print("monitor: NO-GO (unverified result or mail failure)", file=sys.stderr)
        sys.exit(3)
