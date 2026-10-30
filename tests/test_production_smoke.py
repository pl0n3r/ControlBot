"""#1365: synthetic TLS post-deploy smoke; never contacts production or uses real secrets."""
from __future__ import annotations

import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import secrets
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from urllib.parse import parse_qs

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "production-smoke.sh"
SHA = "a" * 40
VERSION = "0.1.24"
CSRF = secrets.token_urlsafe(24)
USER = "smoke-fixture-owner"
PASSWORD = secrets.token_urlsafe(32)


class LocalFixture(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        return

    def send(self, status: int, body: bytes = b"", **headers):
        self.send_response(status)
        for k, v in headers.items():
            self.send_header(k.replace("_", "-"), v)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        mode = self.server.mode
        if self.path == "/health":
            expected = "Basic " + base64.b64encode((USER + ":" + PASSWORD).encode()).decode()
            if self.headers.get("Authorization") != expected:
                self.send(403)
                return
            if mode == "health_error":
                self.send(503)
                return
            if mode == "health_redirect":
                self.send(302, Location="/login")
                return
            payload = {"status": "ok", "version": VERSION, "release_sha": SHA}
            if mode == "sha_mismatch":
                payload["release_sha"] = "b" * 40
            elif mode == "sha_missing":
                del payload["release_sha"]
            elif mode == "version_mismatch":
                payload["version"] = "9.9.9"
            elif mode == "status_unknown":
                payload["status"] = "unknown"
            elif mode == "extra_field":
                payload["source"] = "unverified"
            if mode == "bad_json":
                body = b"{not-json}"
            else:
                body = json.dumps(payload).encode()
            self.send(200, body, Content_Type="application/json")
        elif self.path == "/overview":
            if mode == "private_exposed":
                self.send(200, b"synthetic protected response")
            elif "sid=synthetic" in self.headers.get("Cookie", "") and mode != "session_invalid":
                self.send(200, b"synthetic authenticated response")
            else:
                self.send(302, Location="/login")
        elif self.path == "/login":
            if mode == "csrf_missing":
                self.send(200, b"<form></form>", Content_Type="text/html")
            else:
                self.send(200, (f'<form><input type="hidden" name="csrf" value="{CSRF}"></form>').encode(), Content_Type="text/html")
        else:
            self.send(404)

    def do_POST(self):
        if self.path != "/login":
            self.send(404)
            return
        size = int(self.headers.get("Content-Length", "0"))
        form = parse_qs(self.rfile.read(min(size, 8192)).decode("utf-8"))
        if self.server.mode == "login_rejected" or any(form.get(k) != [v] for k, v in
                (("csrf", CSRF), ("username", USER), ("password", PASSWORD))):
            self.send(401)
            return
        self.send(303, Location="/overview", Set_Cookie="sid=synthetic; Secure; HttpOnly; SameSite=Strict")


class ProductionSmokeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which("openssl") or not shutil.which("bash"):
            raise unittest.SkipTest("Local OpenSSL/bash are required for synthetic TLS smoke")
        cls.temporary = tempfile.TemporaryDirectory(prefix="controlbot-1365-")
        cls.addClassCleanup(cls.temporary.cleanup)
        private = Path(cls.temporary.name)
        cls.cert = private / "ca.pem"
        cls.key = private / "key.pem"
        proc = subprocess.run([
            "openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048",
            "-keyout", str(cls.key), "-out", str(cls.cert), "-days", "1",
            "-subj", "/CN=localhost", "-addext", "subjectAltName=IP:127.0.0.1",
        ], capture_output=True, text=True, timeout=15)
        if proc.returncode:
            raise AssertionError("Unable to create local synthetic TLS certificate")
        cls.cert.chmod(0o600)
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), LocalFixture)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(certfile=str(cls.cert), keyfile=str(cls.key))
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.server.mode = "good"
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.addClassCleanup(cls.server.server_close)
        cls.addClassCleanup(cls.server.shutdown)
        cls.owner_file = private / "owner.name"
        cls.password_file = private / "owner.password"
        for path, value in ((cls.owner_file, USER), (cls.password_file, PASSWORD)):
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(value)

    def setUp(self):
        self.server.mode = "good"

    def run_smoke(self, **updates):
        env = {
            **os.environ,
            "SMOKE_BASE_URL": f"https://127.0.0.1:{self.server.server_port}",
            "SMOKE_EXPECTED_SHA": SHA,
            "SMOKE_EXPECTED_VERSION": VERSION,
            "SMOKE_USERNAME_FILE": str(self.owner_file),
            "SMOKE_PASSWORD_FILE": str(self.password_file),
            "SMOKE_CA_FILE": str(self.cert),
        }
        env.update(updates)
        return subprocess.run(["bash", str(SCRIPT)], cwd=ROOT, env=env,
                              capture_output=True, text=True, timeout=15)

    def assert_no_go(self, result):
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("NO-GO", result.stderr)
        self.assertNotIn(USER, result.stdout + result.stderr)
        self.assertNotIn(PASSWORD, result.stdout + result.stderr)
        self.assertNotIn(SHA, result.stdout + result.stderr)

    def test_smoke_contract(self):
        """AC-01: HTTPS + unauthenticated rejection + test login + exact SHA and version."""
        result = self.run_smoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("GO: verified HTTPS", result.stdout)
        self.assertNotIn(PASSWORD, result.stdout + result.stderr)
        self.assertNotIn(SHA, result.stdout + result.stderr)

    def test_missing_or_mismatched_release_blocks(self):
        for mode in ("sha_mismatch", "sha_missing", "version_mismatch", "status_unknown", "extra_field", "bad_json", "health_error", "health_redirect"):
            with self.subTest(mode=mode):
                self.server.mode = mode
                self.assert_no_go(self.run_smoke())

    def test_login_and_authorization_fail_closed(self):
        for mode in ("private_exposed", "csrf_missing", "login_rejected", "session_invalid"):
            with self.subTest(mode=mode):
                self.server.mode = mode
                self.assert_no_go(self.run_smoke())

    def test_requires_https_and_valid_server_certificate(self):
        self.assert_no_go(self.run_smoke(SMOKE_BASE_URL=f"http://127.0.0.1:{self.server.server_port}"))
        self.assert_no_go(self.run_smoke(SMOKE_CA_FILE=""))
        self.assert_no_go(self.run_smoke(SMOKE_EXPECTED_SHA=""))

    def test_requires_private_credentials_and_no_inline_secrets(self):
        self.assert_no_go(self.run_smoke(SMOKE_USERNAME_FILE=""))
        self.assert_no_go(self.run_smoke(SMOKE_PASSWORD_FILE=""))
        other = Path(self.temporary.name) / "public-user.txt"
        other.write_text(USER, encoding="utf-8")
        other.chmod(0o644)
        self.assert_no_go(self.run_smoke(SMOKE_USERNAME_FILE=str(other)))
        symlink = Path(self.temporary.name) / "linked-pass"
        symlink.symlink_to(self.password_file)
        self.assert_no_go(self.run_smoke(SMOKE_PASSWORD_FILE=str(symlink)))

    def test_no_live_targets_or_secrets_in_source(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("SMOKE_USERNAME_FILE", source)
        self.assertIn("SMOKE_PASSWORD_FILE", source)
        self.assertIn("SMOKE_EXPECTED_SHA", source)
        self.assertIn("https", source)
        self.assertNotIn("control.condorapp.com.co", source)
        self.assertNotIn("--insecure", source)
        self.assertNotIn("CERT_NONE", source)


if __name__ == "__main__":
    unittest.main()
