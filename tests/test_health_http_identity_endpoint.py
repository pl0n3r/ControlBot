"""Owner-gated health HTTP contract, exercised with PHP CLI and private fixtures."""

from __future__ import annotations

import json
import base64
import hashlib
import hmac
import os
from pathlib import Path
import re
import subprocess
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "src" / "HealthIdentityHttpEndpoint.php"
ENTRY = ROOT / "public" / "health.php"
RULES = ROOT / ".htaccess"
GOOD_SHA = "a" * 40
DEPLOYED_VERSION = json.loads((ROOT / "config" / "version.json").read_text(encoding="utf-8"))["version"]
MISMATCH_VERSION = "99.99.99" if DEPLOYED_VERSION != "99.99.99" else "99.99.98
ATTESTATION_KEY = b"k" * 32
PHP_DRIVER = r"""
require $argv[1];
$server = json_decode($argv[2], true, 6, JSON_THROW_ON_ERROR);
$config = json_decode($argv[3], true, 6, JSON_THROW_ON_ERROR);
echo json_encode(\ControlBot\Health\HealthIdentityHttpEndpoint::handle($server, $config), JSON_THROW_ON_ERROR);
"""


class HealthHttpIdentityEndpointTests(unittest.TestCase):
    @staticmethod
    def write_receipt(path: Path, version: str, sha: str) -> None:
        signed = f"controlbot-release-v1\n{version}\n{sha}".encode("ascii")
        signature = hmac.new(ATTESTATION_KEY, signed, hashlib.sha256).hexdigest()
        path.write_text(json.dumps(
            {"version": version, "release_sha": sha, "signature": signature},
            separators=(",", ":"),
        ), encoding="utf-8")

    def invoke(self, server: dict, configuration: dict) -> dict:
        # A real PHP script is required for Xdebug's auto_prepend_file coverage.
        with TemporaryDirectory(prefix="cb-health-driver-") as temporary:
            driver = Path(temporary) / "driver.php"
            driver.write_text("<?php\n" + PHP_DRIVER, encoding="utf-8")
            result = subprocess.run(
                ["php", str(driver), str(ENDPOINT), json.dumps(server), json.dumps(configuration)],
                cwd=ROOT, check=False, capture_output=True, text=True, timeout=10,
            )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    @staticmethod
    def fixture(root: Path) -> tuple[dict, dict, Path]:
        public = root / "public"
        private = root / "private"
        public.mkdir()
        private.mkdir()
        artifact = private / "release.json"
        HealthHttpIdentityEndpointTests.write_receipt(artifact, DEPLOYED_VERSION, GOOD_SHA)
        server = {"REQUEST_METHOD": "GET", "HTTPS": "on", "AUTH_TYPE": "Basic", "REMOTE_USER": "owner", "DOCUMENT_ROOT": str(public)}
        configuration = {"owner_login": "owner", "release_manifest_path": str(artifact), "attestation_key": base64.b64encode(ATTESTATION_KEY).decode("ascii")}
        return server, configuration, artifact

    def test_authenticated_artifact_identity_is_exact_and_nocache(self):
        with TemporaryDirectory() as tmp:
            server, config, artifact = self.fixture(Path(tmp))
            response = self.invoke(server, config)
            self.assertEqual(response["status"], 200)
            self.assertEqual(json.loads(response["body"]), {"status": "ok", "version": DEPLOYED_VERSION, "release_sha": GOOD_SHA})
            self.assertEqual(set(json.loads(response["body"])), {"status", "version", "release_sha"})
            self.assertIn("no-store", response["headers"]["Cache-Control"])
            self.assertEqual(response["headers"]["X-Content-Type-Options"], "nosniff")
            self.assertEqual(response["headers"]["Content-Type"], "application/json; charset=utf-8")
            self.assertNotIn("token", response["body"].lower())
            # Re-read the private artifact for each call, never a caller-provided SHA.
            self.write_receipt(artifact, DEPLOYED_VERSION, "b" * 40)
            second = self.invoke(server, config)
            self.assertEqual(json.loads(second["body"])["release_sha"], "b" * 40)

    def test_unauthenticated_or_ambiguous_owner_fails_closed(self):
        with TemporaryDirectory() as tmp:
            server, config, artifact = self.fixture(Path(tmp))
            cases = [
                ({**server, "REMOTE_USER": "intruder"}, config, 403),
                ({**server, "AUTH_TYPE": "Digest"}, config, 403),
                ({**server, "HTTPS": "off", "HTTP_X_FORWARDED_PROTO": "https"}, config, 403),
                ({k: v for k, v in server.items() if k != "REMOTE_USER"}, config, 403),
                ({**server, "REQUEST_METHOD": "POST"}, config, 403),
                (server, {**config, "owner_login": "another-owner"}, 403),
                (server, {**config, "release_manifest_path": ""}, 503),
                (server, {**config, "attestation_key": ""}, 503),
                (server, {**config, "attestation_key": base64.b64encode(b"w" * 32).decode("ascii")}, 503),
                ({k: v for k, v in server.items() if k != "DOCUMENT_ROOT"}, config, 503),
                (server, {**config, "release_manifest_path": str(Path(tmp) / "missing.json")}, 503),
                (server, {**config, "release_manifest_path": str(Path(tmp) / "public" / "release.json")}, 503),
                (server, {**config, "release_manifest_path": "php://filter/convert.base64-encode/resource=/etc/passwd"}, 503),
            ]
            (Path(tmp) / "public" / "release.json").write_text("{}")
            for candidate_server, candidate_config, status in cases:
                with self.subTest(status=status, case=len(candidate_config)):
                    result = self.invoke(candidate_server, candidate_config)
                    self.assertEqual(result["status"], status)
                    self.assertNotIn(GOOD_SHA, result["body"])
                    self.assertIn("no-store", result["headers"]["Cache-Control"])
            for bad in ({"version": DEPLOYED_VERSION, "release_sha": "wrong"},
                        {"version": DEPLOYED_VERSION, "release_sha": GOOD_SHA, "secret": "token-secret"},
                        [DEPLOYED_VERSION, GOOD_SHA]):
                artifact.write_text(json.dumps(bad))
                result = self.invoke(server, config)
                self.assertEqual(result["status"], 503)
                self.assertNotIn("token-secret", result["body"])
            artifact.write_text("X" * 513)
            self.assertEqual(self.invoke(server, config)["status"], 503)
            self.write_receipt(artifact, DEPLOYED_VERSION, GOOD_SHA)
            link = Path(tmp) / "private" / "link.json"
            link.symlink_to(artifact)
            self.assertEqual(self.invoke(server, {**config, "release_manifest_path": str(link)})["status"], 503)
            # Client-side query and headers cannot supply identity when server configuration is missing.
            forged = {**server, "QUERY_STRING": "release_sha=" + GOOD_SHA, "HTTP_X_RELEASE_SHA": GOOD_SHA}
            self.assertEqual(self.invoke(forged, {**config, "release_manifest_path": None})["status"], 503)
            # Repeated object members are ambiguous to PHP json_decode; canonical bytes reject them.
            self.write_receipt(artifact, DEPLOYED_VERSION, GOOD_SHA)
            raw = artifact.read_text(encoding="utf-8")
            artifact.write_text(raw[:-1] + ',"version":DEPLOYED_VERSION}', encoding="utf-8")
            self.assertEqual(self.invoke(server, config)["status"], 503)
            self.write_receipt(artifact, MISMATCH_VERSION, GOOD_SHA)
            self.assertEqual(self.invoke(server, config)["status"], 503)
            self.write_receipt(artifact, DEPLOYED_VERSION, GOOD_SHA)
            artifact.write_text(artifact.read_text().replace('"signature":"', '"signature":"f'))
            self.assertEqual(self.invoke(server, config)["status"], 503)

    def test_php_internal_paths_remain_denied(self):
        rules = RULES.read_text()
        self.assertIn("AuthType Basic", rules)
        self.assertIn("Require valid-user", rules)
        self.assertIn("RewriteRule ^(?:src|config|docs|scripts|tests|vendor", rules)
        self.assertIn("RewriteRule ^health\\.php$ public/health.php [L,NC]", rules)
        self.assertIn(r"RewriteCond %{THE_REQUEST} \\s/+public/health\\.php", rules)
        self.assertIn(r"RewriteRule ^public/health\\.php$ - [F,L,NC]", rules)
        self.assertLess(rules.index("RewriteRule ^public/health\\.php$"), rules.index("RewriteRule ^(?!(?:index"))
        php_deny = next(line for line in rules.splitlines() if line.startswith("RewriteRule ^(?!"))
        self.assertIn("public/health\\.php", php_deny)
        self.assertIn("[F,L,NC]", php_deny)
        self.assertNotIn("SetEnv CONTROLBOT_RELEASE_MANIFEST_PATH", rules)
        self.assertIn("getenv('CONTROLBOT_OWNER_LOGIN')", ENTRY.read_text())
        self.assertIn("getenv('CONTROLBOT_RELEASE_MANIFEST_PATH')", ENTRY.read_text())
        self.assertIn("getenv('CONTROLBOT_RELEASE_ATTESTATION_KEY')", ENTRY.read_text())

    def test_no_deploy_network_secrets_or_live_enablement(self):
        code = (ENDPOINT.read_text() + ENTRY.read_text()).lower()
        for forbidden in ("curl_init(", "file_get_contents('http", "curl_exec(", "shell_exec(", "proc_open(",
                          "$_get", "$_post", "$_request", "http_x_release_sha", "deploy_enabled", "putenv("):
            self.assertNotIn(forbidden, code)
        self.assertIn("release_manifest_path", code)
        self.assertIn("realpath(", code)
        self.assertTrue((ROOT / ".github" / "workflows" / "observar.yml").is_file())
        observer = (ROOT / ".github" / "workflows" / "observar.yml").read_text()
        self.assertIn("if: vars.DOMAIN != '' && vars.DEPLOY_ENABLED == 'true'", observer)
        self.assertNotIn("\n  push:", observer)
        self.assertIsNone(re.search(r"^SetEnv CONTROLBOT_RELEASE_MANIFEST_PATH", RULES.read_text(), re.M))

    def test_coverage_wrapper_records_health_php_lines(self):
        """CI must count real PHP execution rather than treating php -r as covered."""
        raw = os.environ.get("CONTROLBOT_PHP_COVERAGE_DIR")
        if not raw:
            self.skipTest("Requires the CI coverage wrapper")
        with TemporaryDirectory(prefix="cb-health-coverage-") as tmp:
            server, config, _ = self.fixture(Path(tmp))
            self.assertEqual(self.invoke(server, config)["status"], 200)
        found = False
        for fragment in Path(raw).glob("*.json"):
            try:
                data = json.loads(fragment.read_text(encoding="utf-8"))
            except (ValueError, OSError):
                continue
            if not isinstance(data, dict):
                continue
            for path, lines in data.items():
                if path.replace("\\", "/").endswith("/src/HealthIdentityHttpEndpoint.php") and isinstance(lines, dict):
                    found |= any(isinstance(value, int) and value > 0 for value in lines.values())
        self.assertTrue(found, "HealthIdentityHttpEndpoint.php has no executed PHP coverage lines")

    def test_public_entrypoint_executes_in_php_coverage(self):
        """The actual entrypoint is executed with synthetic, server-scoped environment."""
        with TemporaryDirectory(prefix="cb-health-entry-") as tmp:
            server, config, _ = self.fixture(Path(tmp))
            source = r"""
$_SERVER = json_decode($argv[2], true, 6, JSON_THROW_ON_ERROR);
putenv('CONTROLBOT_OWNER_LOGIN=' . $argv[3]);
putenv('CONTROLBOT_RELEASE_MANIFEST_PATH=' . $argv[4]);
putenv('CONTROLBOT_RELEASE_ATTESTATION_KEY=' . $argv[5]);
require $argv[1];
"""
            driver = Path(tmp) / "entrypoint-driver.php"
            driver.write_text("<?php\n" + source, encoding="utf-8")
            result = subprocess.run(
                ["php", str(driver), str(ENTRY), json.dumps(server),
                 config["owner_login"], config["release_manifest_path"], config["attestation_key"]],
                cwd=ROOT, check=False, capture_output=True, text=True, timeout=10,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)["release_sha"], GOOD_SHA)


if __name__ == "__main__":
    unittest.main()
