"""Contrato puro del payload de identidad de health; sin HTTP ni servicios externos."""

from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_SOURCE = ROOT / "src" / "HealthIdentityPayload.php"
RELEASE_SHA = "a" * 40

PHP_DRIVER = r"""
try {
    require $argv[1];
    $payload = \ControlBot\Health\HealthIdentityPayload::build($argv[2], $argv[3]);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(2);
}
"""


def invoke_php(version: str, release_sha: str) -> subprocess.CompletedProcess[str]:
    if shutil.which("php") is None:
        raise AssertionError("PHP CLI 8.5 debe estar disponible en CI")
    with tempfile.TemporaryDirectory(prefix="controlbot-health-identity-") as temporary:
        driver = Path(temporary) / "health_identity_driver.php"
        driver.write_text(PHP_DRIVER, encoding="utf-8")
        return subprocess.run(
            ["php", str(driver), str(PHP_SOURCE), version, release_sha],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
            timeout=10,
        )


class HealthIdentityPayloadTests(unittest.TestCase):
    def test_valid_server_supplied_identity_is_minimal(self):
        result = invoke_php("0.1.24", RELEASE_SHA)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        self.assertEqual(
            json.loads(result.stdout),
            {"status": "ok", "version": "0.1.24", "release_sha": RELEASE_SHA},
        )

    def test_malformed_version_or_sha_denied_without_echo(self):
        cases = [
            ("", RELEASE_SHA),
            ("1.2", RELEASE_SHA),
            ("01.2.3", RELEASE_SHA),
            ("1.2.3-rc1", RELEASE_SHA),
            ("v1.2.3", RELEASE_SHA),
            ("1.2.3\n", RELEASE_SHA),
            ("0.1.24", "abc123"),
            ("0.1.24", "A" * 40),
            ("0.1.24", "g" * 40),
            ("0.1.24", "secret-token-value"),
        ]
        for version, release_sha in cases:
            with self.subTest(version=version, sha_length=len(release_sha)):
                result = invoke_php(version, release_sha)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, "")
                self.assertEqual(result.stderr, "Invalid health identity.")
                if version:
                    self.assertNotIn(version, result.stderr)
                if release_sha:
                    self.assertNotIn(release_sha, result.stderr)

    def test_no_secrets_global_reads_or_untrusted_fields(self):
        source = PHP_SOURCE.read_text(encoding="utf-8")
        self.assertNotRegex(
            source,
            re.compile(
                r"\$_(?:GET|POST|REQUEST|SERVER|ENV|COOKIE|FILES)\b"
                r"|\$GLOBALS\b|\bgetenv\s*\(|\bfile_get_contents\s*\(",
                re.IGNORECASE,
            ),
        )
        self.assertIn("final readonly class HealthIdentityPayload", source)
        self.assertIn("public static function build(string $version, string $releaseSha): self", source)
        self.assertIn("'release_sha' => $this->releaseSha", source)
        result = invoke_php("0.1.24", RELEASE_SHA)
        payload = json.loads(result.stdout)
        self.assertEqual(set(payload), {"status", "version", "release_sha"})


if __name__ == "__main__":
    unittest.main()
