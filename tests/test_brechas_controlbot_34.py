"""CB-0503..CB-0517: pruebas offline sintéticas; no acredita despliegue ni backups."""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLAUSES = tuple(f"CB-{number:04d}" for number in range(503, 518))
GUARDRAIL_SCENARIOS = ROOT / "tests" / "guardrail_integration_scenarios.php"
HEALTH_CLASS = ROOT / "src" / "HealthIdentityHttpEndpoint.php"
RELEASE_SHA = "a" * 40
KEY = b"k" * 32
VERSION = json.loads((ROOT / "config" / "version.json").read_text(encoding="utf-8"))["version"]
PHP_DRIVER = r"""
require $argv[1];
$server = json_decode($argv[2], true, 6, JSON_THROW_ON_ERROR);
$config = json_decode($argv[3], true, 6, JSON_THROW_ON_ERROR);
echo json_encode(\ControlBot\Health\HealthIdentityHttpEndpoint::handle($server, $config), JSON_THROW_ON_ERROR);
"""


def fixture(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / name)], cwd=ROOT, check=False,
        text=True, capture_output=True, timeout=35,
    )
    if result.returncode:
        raise AssertionError(f"fixture {name}: {result.stderr[:350]}")
    return json.loads(result.stdout)


def manifest(version: str = VERSION, sha: str = RELEASE_SHA) -> str:
    payload = f"controlbot-release-v1\n{version}\n{sha}".encode("ascii")
    signature = hmac.new(KEY, payload, hashlib.sha256).hexdigest()
    return json.dumps(
        {"version": version, "release_sha": sha, "signature": signature},
        separators=(",", ":"),
    )


def health_fixture(folder: Path) -> tuple[dict, dict, Path]:
    public, private = folder / "public", folder / "private"
    public.mkdir()
    private.mkdir()
    receipt = private / "release.json"
    receipt.write_text(manifest(), encoding="utf-8")
    server = {
        "REQUEST_METHOD": "GET", "HTTPS": "on", "AUTH_TYPE": "Basic",
        "REMOTE_USER": "owner", "DOCUMENT_ROOT": str(public),
    }
    config = {
        "owner_login": "owner", "release_manifest_path": str(receipt),
        "attestation_key": base64.b64encode(KEY).decode("ascii"),
    }
    return server, config, receipt


def health_response(server: dict, config: dict) -> dict:
    # Real PHP script ensures the existing Xdebug coverage wrapper works.
    with tempfile.TemporaryDirectory(prefix="cb-1041-health-") as temp:
        driver = Path(temp) / "driver.php"
        driver.write_text("<?php\n" + PHP_DRIVER, encoding="utf-8")
        result = subprocess.run(
            ["php", str(driver), str(HEALTH_CLASS), json.dumps(server), json.dumps(config)],
            cwd=ROOT, check=False, text=True, capture_output=True, timeout=12,
        )
    if result.returncode:
        raise AssertionError(result.stderr[:350])
    return json.loads(result.stdout)


class BrechasControlbot34Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.guardrail = fixture("guardrail_integration_scenarios.php")
        cls.docs = {
            name: (ROOT / "docs" / name).read_text(encoding="utf-8")
            for name in ("guardrail-integration-v1.md", "health-identity-endpoint.md",
                         "hostinger-d059-backup.md")
        }
        cls.code = {
            name: (ROOT / name).read_text(encoding="utf-8")
            for name in ("src/GuardrailIntegration.php", "public/health.php",
                         ".htaccess", ".github/workflows/ci.yml")
        }

    def test_contrato_principal(self) -> None:
        """AC-01: cada una de las 15 filas tiene aserciones explícitas."""
        covered = set()
        guard = self.guardrail
        health = self.docs["health-identity-endpoint.md"]
        backup = self.docs["hostinger-d059-backup.md"]
        for row in CLAUSES:
            with self.subTest(clause=row):
                covered.add(row)
                if row == "CB-0503":
                    self.assertTrue(guard["stale_session"])
                    self.assertTrue(guard["stale_assignment"])
                    self.assertTrue(guard["wrong_session_target"])
                    self.assertEqual(guard["safe"]["requeue_intent"]["work_item"]["generation"], 5)
                    self.assertIsNone(guard["safe"]["requeue_intent"]["work_item"]["assigned_session_id"])
                elif row == "CB-0504":
                    self.assertEqual(guard["partial"]["phase"], "checkpoint_pending")
                    self.assertFalse(guard["partial"]["success"])
                    self.assertTrue(guard["partial"]["recoverable"])
                    self.assertTrue(guard["invalid_order"])
                    self.assertTrue(guard["done"]["success"])
                elif row == "CB-0505":
                    src = self.code["src/GuardrailIntegration.php"].lower()
                    self.assertFalse(any(x in src for x in (
                        "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
                        "shell_exec", "proc_open", "exec(", "system(", "sleep(",
                    )))
                    self.assertIn("runtime.session.pause", src)
                elif row == "CB-0506":
                    self.assertIn("option A", health)
                    self.assertIn("reversible offline endpoint only", health)
                    self.assertIn("does not authorize deploy", health)
                elif row == "CB-0507":
                    with tempfile.TemporaryDirectory() as temp:
                        server, cfg, _ = health_fixture(Path(temp))
                        self.assertEqual(health_response(server, cfg)["status"], 200)
                        self.assertEqual(health_response(
                            {**server, "HTTPS": "off", "HTTP_X_FORWARDED_PROTO": "https"}, cfg,
                        )["status"], 403)
                        self.assertEqual(health_response(
                            {**server, "AUTH_TYPE": "Digest"}, cfg,
                        )["status"], 403)
                        self.assertEqual(health_response(
                            {**server, "REMOTE_USER": "intruder"}, cfg,
                        )["status"], 403)
                elif row == "CB-0508":
                    with tempfile.TemporaryDirectory() as temp:
                        server, cfg, receipt = health_fixture(Path(temp))
                        self.assertEqual(health_response(
                            server, {**cfg, "release_manifest_path": None},
                        )["status"], 503)
                        link = Path(temp) / "private" / "link.json"
                        link.symlink_to(receipt)
                        self.assertEqual(health_response(
                            server, {**cfg, "release_manifest_path": str(link)},
                        )["status"], 503)
                    self.assertNotIn("CONTROLBOT_RELEASE_MANIFEST_PATH",
                                     self.code[".htaccess"])
                elif row == "CB-0509":
                    with tempfile.TemporaryDirectory() as temp:
                        server, cfg, receipt = health_fixture(Path(temp))
                        actual = json.loads(health_response(server, cfg)["body"])
                        self.assertEqual(actual, {
                            "status": "ok", "version": VERSION, "release_sha": RELEASE_SHA,
                        })
                        receipt.write_text(manifest("99.99.99"), encoding="utf-8")
                        self.assertEqual(health_response(server, cfg)["status"], 503)
                        receipt.write_text(manifest().replace(
                            '"signature":"', '"signature":"0',
                        ), encoding="utf-8")
                        self.assertEqual(health_response(server, cfg)["status"], 503)
                elif row == "CB-0510":
                    htaccess = self.code[".htaccess"]
                    self.assertIn("AuthType Basic", htaccess)
                    self.assertIn("Require valid-user", htaccess)
                    self.assertIn("RewriteRule ^(?:src|config|docs|scripts|tests|vendor", htaccess)
                    self.assertIn(r"RewriteRule ^health\.php$ public/health.php [L,NC]", htaccess)
                    self.assertIn("getenv('CONTROLBOT_OWNER_LOGIN')",
                                  self.code["public/health.php"])
                elif row == "CB-0511":
                    ci = self.code[".github/workflows/ci.yml"]
                    self.assertIn("name: Pruebas de contrato e integración", ci)
                    self.assertIn("name: Validar", ci)
                    self.assertRegex(ci, r"(?s)contrato:.*?validar:.*?needs: \[factory, contrato\]")
                elif row == "CB-0512":
                    self.assertIn("code-validation evidence only", health)
                    self.assertIn("SRE role checklist", health)
                    self.assertIn("CodeRabbit must be requested only once", health)
                elif row == "CB-0513":
                    self.assertIn("does NOT prove deployed release_sha", health)
                    self.assertIn("Parent issues #625 and #760 remain open", health)
                    self.assertIn("branch protection", health)
                elif row == "CB-0514":
                    self.assertIn("no crea un backup", backup)
                    self.assertIn("no satisface D-059", backup)
                    self.assertIn("no autoriza deploy", backup)
                elif row == "CB-0515":
                    self.assertIn("PR #630 permanece en draft", backup)
                    self.assertIn("#627", backup)
                    self.assertIn("backup real", backup)
                elif row == "CB-0516":
                    self.assertIn("public_html", backup)
                    self.assertIn("inmediatamente antes del merge", backup)
                    self.assertIn("estado actualmente publicado/restaurable", backup)
                elif row == "CB-0517":
                    self.assertIn("base de datos: **no aplica en este gate**", backup)
                    self.assertIn("mientras ControlBot no use una DB live", backup)
                else:
                    self.fail(f"Missing coverage for {row}")
        self.assertEqual(covered, set(CLAUSES))
        self.assertEqual(len(covered), 15)

    def test_falla_cerrado(self) -> None:
        """AC-02: no transformar errores/receipts ausentes en éxito."""
        self.assertTrue(self.guardrail["invalid_order"])
        self.assertFalse(self.guardrail["partial"]["success"])
        self.assertTrue(self.guardrail["partial"]["recoverable"])
        self.assertFalse(self.guardrail["old_guard"]["allowed"])
        self.assertEqual(self.guardrail["safe"]["pause_intent"]["state"]["state"], "unknown")
        with tempfile.TemporaryDirectory() as temp:
            server, cfg, receipt = health_fixture(Path(temp))
            cases = (
                ({**server, "REQUEST_METHOD": "POST"}, cfg, 403),
                ({**server, "HTTPS": "off", "HTTP_X_FORWARDED_PROTO": "https"}, cfg, 403),
                ({**server, "REMOTE_USER": "intruder"}, cfg, 403),
                (server, {**cfg, "owner_login": "other-owner"}, 403),
                (server, {**cfg, "attestation_key": ""}, 503),
                (server, {**cfg, "release_manifest_path": ""}, 503),
            )
            for candidate_server, candidate_config, expected in cases:
                with self.subTest(expected=expected, server=candidate_server):
                    self.assertEqual(health_response(
                        candidate_server, candidate_config,
                    )["status"], expected)
            receipt.write_text("{}", encoding="utf-8")
            rejected = health_response(server, cfg)
            self.assertEqual(rejected["status"], 503)
            self.assertNotIn(RELEASE_SHA, rejected["body"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: los datos son sintéticos y no llaman a servicios live."""
        self.assertEqual(RELEASE_SHA, "a" * 40)
        self.assertEqual(KEY, b"k" * 32)
        self.assertEqual(
            self.guardrail["safe"]["checkpoint"]["issue_ref"], "pl0n3r/ControlBot#111",
        )
        source = (self.code["src/GuardrailIntegration.php"] +
                  self.code["public/health.php"]).lower()
        for forbidden in ("https://api.github.com/", "curl_init(", "shell=true",
                          "deploy_enabled=true"):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("AUTH_USER_FILE", self.code["public/health.php"])
        self.assertIn("No se borran sitios",
                      self.docs["hostinger-d059-backup.md"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI offline nunca demuestra backup, go-live ni Production GREEN."""
        states = {row: "UNKNOWN" for row in (
            "CB-0506", "CB-0511", "CB-0512", "CB-0513",
            "CB-0514", "CB-0515", "CB-0516", "CB-0517",
        )}
        self.assertEqual(len(states), 8)
        for row, status in states.items():
            with self.subTest(clause=row):
                self.assertIn(row, CLAUSES)
                self.assertEqual(status, "UNKNOWN")
                self.assertNotEqual(status, "success")
        self.assertFalse(self.guardrail["partial"]["success"])
        self.assertEqual(self.guardrail["safe"]["pause_intent"]["state"]["state"], "unknown")
        self.assertIn("No son evidencia suficiente",
                      self.docs["hostinger-d059-backup.md"])
        self.assertIn("unverified", self.docs["health-identity-endpoint.md"])


if __name__ == "__main__":
    unittest.main()
