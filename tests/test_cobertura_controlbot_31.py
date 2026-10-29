"""CB-0507..CB-0513 and CB-0506: real offline release-health contracts.

Only an authenticated server plus a private signed receipt may report release
identity. A PHP CLI test cannot establish live hosting, SRE or deploy health.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
from pathlib import Path
import re
import subprocess
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "src" / "HealthIdentityHttpEndpoint.php"
ENTRYPOINT = ROOT / "public" / "health.php"
HTACCESS = ROOT / ".htaccess"
CI_WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
VERSION = json.loads((ROOT / "config" / "version.json").read_text(encoding="utf-8"))["version"]
SYNTHETIC_KEY = b"offline-health-identity-test-key"  # exactly 32 fake bytes
SHA_A = "a" * 40
SHA_B = "b" * 40
DRIVER = r"""<?php
declare(strict_types=1);
require $argv[1];
$server = json_decode($argv[2], true, 6, JSON_THROW_ON_ERROR);
$config = json_decode($argv[3], true, 6, JSON_THROW_ON_ERROR);
echo json_encode(\ControlBot\Health\HealthIdentityHttpEndpoint::handle($server, $config), JSON_THROW_ON_ERROR);
"""


class CoberturaControlbot31Tests(unittest.TestCase):
    @staticmethod
    def receipt(path: Path, *, version: str = VERSION, sha: str = SHA_A) -> None:
        signed = f"controlbot-release-v1\n{version}\n{sha}".encode("ascii")
        signature = hmac.new(SYNTHETIC_KEY, signed, hashlib.sha256).hexdigest()
        path.write_text(json.dumps(
            {"version": version, "release_sha": sha, "signature": signature},
            separators=(",", ":"),
        ), encoding="utf-8")

    @staticmethod
    def setup_fixture(directory: Path) -> tuple[dict, dict, Path]:
        webroot = directory / "webroot"
        private = directory / "private"
        webroot.mkdir()
        private.mkdir()
        receipt_path = private / "signed-release.json"
        CoberturaControlbot31Tests.receipt(receipt_path)
        server = {
            "REQUEST_METHOD": "GET", "HTTPS": "on", "AUTH_TYPE": "Basic",
            "REMOTE_USER": "fixture-owner", "DOCUMENT_ROOT": str(webroot),
        }
        config = {
            "owner_login": "fixture-owner",
            "release_manifest_path": str(receipt_path),
            "attestation_key": base64.b64encode(SYNTHETIC_KEY).decode("ascii"),
        }
        return server, config, receipt_path

    def invoke(self, server: dict, config: dict) -> dict:
        with TemporaryDirectory(prefix="cb31-php-") as temp:
            driver = Path(temp) / "probe.php"
            driver.write_text(DRIVER, encoding="utf-8")
            result = subprocess.run(
                ["php", "-d", "allow_url_fopen=0", str(driver), str(ENDPOINT),
                 json.dumps(server), json.dumps(config)],
                cwd=ROOT, capture_output=True, text=True, timeout=10, check=False,
            )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def assert_rejected(self, response: dict, expected: int) -> None:
        self.assertEqual(response["status"], expected, response)
        self.assertEqual(response["body"],
                         "Forbidden.\n" if expected == 403 else "Service unavailable.\n")
        self.assertEqual(response["headers"]["Content-Type"], "text/plain; charset=utf-8")
        self.assertIn("no-store", response["headers"]["Cache-Control"])
        self.assertNotIn(SHA_A, response["body"])
        self.assertNotIn("fixture-owner", response["body"])

    def test_contrato_principal(self):
        """A: CB-0507 HTTPS/owner; 0508 private receipt; 0509 verified identity."""
        with TemporaryDirectory(prefix="cb31-release-") as temp:
            server, config, path = self.setup_fixture(Path(temp))
            response = self.invoke(server, config)  # CB-0507 and CB-0508
            self.assertEqual(response["status"], 200)
            self.assertEqual(json.loads(response["body"]), {
                "status": "ok", "version": VERSION, "release_sha": SHA_A,
            })  # CB-0509: real HealthIdentityPayload validation and HMAC
            self.assertEqual(response["headers"]["Content-Type"],
                             "application/json; charset=utf-8")
            self.assertIn("no-store", response["headers"]["Cache-Control"])
            self.assertEqual(response["headers"]["Pragma"], "no-cache")
            self.assertEqual(response["headers"]["X-Frame-Options"], "DENY")
            self.assertEqual(response["headers"]["X-Content-Type-Options"], "nosniff")
            self.assertEqual(response["headers"]["Referrer-Policy"], "no-referrer")
            # The source of identity is the signed private artifact, not a request.
            self.receipt(path, sha=SHA_B)
            second = self.invoke({**server, "HTTP_X_RELEASE_SHA": SHA_A}, config)
            self.assertEqual(second["status"], 200)
            self.assertEqual(json.loads(second["body"])["release_sha"], SHA_B)
            self.assertEqual(json.loads(second["body"])["version"], VERSION)

    def test_falla_cerrado(self):
        """Negative paths for CB-0507, CB-0508 and CB-0509 call real PHP."""
        with TemporaryDirectory(prefix="cb31-reject-") as temp:
            server, config, path = self.setup_fixture(Path(temp))
            denied_owner = [
                ({**server, "HTTPS": "off", "HTTP_X_FORWARDED_PROTO": "https"}, config),
                ({k: v for k, v in server.items() if k != "HTTPS"}, config),
                ({**server, "AUTH_TYPE": "Digest"}, config),
                ({**server, "REMOTE_USER": "other-user"}, config),
                ({k: v for k, v in server.items() if k != "REMOTE_USER"}, config),
                ({**server, "REQUEST_METHOD": "POST"}, config),
                ({**server, "REQUEST_METHOD": "HEAD"}, config),
                ({**server, "HTTP_REMOTE_USER": "fixture-owner", "REMOTE_USER": None}, config),
                (server, {**config, "owner_login": "someone-else"}),
                (server, {**config, "owner_login": None}),
            ]
            for i, (candidate_server, candidate_config) in enumerate(denied_owner):
                with self.subTest(type="owner_or_https", case=i):
                    self.assert_rejected(self.invoke(candidate_server, candidate_config), 403)

            # CB-0508: missing, under webroot, symlink, unreadable/malformed or
            # fake key are not private authenticated deployment evidence.
            under_webroot = Path(temp) / "webroot" / "receipt.json"
            self.receipt(under_webroot)
            symlink = Path(temp) / "private" / "shortcut.json"
            symlink.symlink_to(path)
            denied_artifacts = [
                {**config, "release_manifest_path": None},
                {**config, "release_manifest_path": ""},
                {**config, "release_manifest_path": "relative/path.json"},
                {**config, "release_manifest_path": str(Path(temp) / "absent.json")},
                {**config, "release_manifest_path": str(under_webroot)},
                {**config, "release_manifest_path": str(symlink)},
                {**config, "release_manifest_path": str(Path(temp) / "private")},
                {**config, "attestation_key": None},
                {**config, "attestation_key": base64.b64encode(b"z" * 32).decode("ascii")},
                {**config, "attestation_key": base64.b64encode(b"too-short").decode("ascii")},
            ]
            for i, candidate_config in enumerate(denied_artifacts):
                with self.subTest(type="private_receipt", case=i):
                    self.assert_rejected(self.invoke(server, candidate_config), 503)

            # CB-0509: correct HMAC over a wrong tree version still fails; so
            # do a bad SHA, wrong signature, duplicate keys and noncanonical JSON.
            mismatched = "99.99.99" if VERSION != "99.99.99" else "99.99.98"
            bad_receipts = [
                lambda: self.receipt(path, version=mismatched),
                lambda: self.receipt(path, sha="not-a-commit"),
                lambda: path.write_text('{"version":"' + VERSION + '","release_sha":"' + SHA_A + '","signature":"' + '0' * 64 + '"}'),
                lambda: path.write_text(' ' + json.dumps({"version": VERSION, "release_sha": SHA_A, "signature": "0" * 64})),
                lambda: path.write_text('{"version":"' + VERSION + '","release_sha":"' + SHA_A + '","signature":"' + '0' * 64 + '","version":"' + VERSION + '"}'),
                lambda: path.write_text("z" * 513),
                lambda: path.write_text("[]"),
            ]
            for i, write_bad in enumerate(bad_receipts):
                with self.subTest(type="identity_receipt", case=i):
                    write_bad()
                    self.assert_rejected(self.invoke(server, config), 503)
            self.receipt(path)
            self.assertEqual(self.invoke(server, config)["status"], 200)

    def test_sin_datos_reales(self):
        """No synthetic CLI success is promoted to live or deploy authorization."""
        entry = ENTRYPOINT.read_text(encoding="utf-8")
        rules = HTACCESS.read_text(encoding="utf-8")
        endpoint = ENDPOINT.read_text(encoding="utf-8")
        self.assertIn("HealthIdentityHttpEndpoint::handle", entry)
        self.assertIn("getenv('CONTROLBOT_RELEASE_MANIFEST_PATH')", entry)
        self.assertIn("getenv('CONTROLBOT_RELEASE_ATTESTATION_KEY')", entry)
        self.assertNotRegex(rules, r"(?m)^\s*SetEnv\s+CONTROLBOT_RELEASE_MANIFEST_PATH\b")
        self.assertIn("AuthType Basic", rules)
        self.assertIn("Require valid-user", rules)
        self.assertIn(r"RewriteRule ^health\.php$ public/health.php [L,NC]", rules)
        self.assertIn(r"RewriteRule ^(?:src|config|docs|scripts|tests|vendor", rules)
        self.assertIn(r"RewriteRule ^public/health\.php$ - [R=404,L,NC]", rules)
        self.assertIn("hash_hmac('sha256'", endpoint)
        for forbidden in ("curl_init(", "curl_exec(", "$_GET", "$_POST", "$_REQUEST", "deploy_enabled"):
            self.assertNotIn(forbidden.lower(), (entry + endpoint).lower())
        observer = (ROOT / ".github" / "workflows" / "observar.yml").read_text(encoding="utf-8")
        self.assertIn("if: vars.DOMAIN != '' && vars.DEPLOY_ENABLED == 'true'", observer)

    def test_ci_contract(self):
        """Static CI wiring guard; terminal GitHub checks are separate hard gates."""
        workflow = CI_WORKFLOW.read_text(encoding="utf-8")
        # Extract actual (non-comment) top-level jobs, not whole-file matches.
        jobs = {}
        current = None
        for line in workflow.splitlines():
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            match = re.fullmatch(r"  ([a-z_][a-z0-9_-]*):\s*", line)
            if match:
                current = match.group(1)
                jobs[current] = []
            elif current is not None:
                jobs[current].append(line)
        self.assertIn("contrato", jobs)
        self.assertIn("validar", jobs)
        contrato = "\n".join(jobs["contrato"])
        validar = "\n".join(jobs["validar"])
        self.assertRegex(contrato, r"(?m)^    name: Pruebas de contrato e integración$")
        self.assertRegex(contrato, r"(?m)^        run: python3 scripts/generate_coverage\.py$")
        self.assertRegex(validar, r"(?m)^    needs: \[factory, contrato\]$")
        self.assertRegex(validar, r"(?m)^    name: Validar$")


if __name__ == "__main__":
    unittest.main()
