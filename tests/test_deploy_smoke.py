"""Deterministic, local-only post-deploy smoke contract for ControlBot #810."""

from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
from threading import Thread
import unittest
from urllib.error import HTTPError
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parents[1]
SMOKE = ROOT / "scripts/deploy-smoke.php"
WORKFLOW = ROOT / ".github/workflows/deploy.yml"
VERSION = ROOT / "config/version.json"
GOOD_SHA = "a" * 40


class DeployReproducibleTests(unittest.TestCase):
    """Verify the same downloaded artifact checked by the deploy workflow."""

    def run_smoke(self, health_file: Path, sha: str = GOOD_SHA) -> subprocess.CompletedProcess[str]:
        """Execute the actual PHP verifier with a locally published fixture."""
        return subprocess.run(
            ["php", str(SMOKE), str(health_file), str(VERSION), sha],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
            timeout=10,
        )

    def test_acceptance(self) -> None:
        """A locally published /health must match version and SHA exactly."""
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("needs: [readiness, deploy]", workflow)
        self.assertIn("php scripts/deploy-smoke.php", workflow)
        self.assertIn("--proto '=https'", workflow)
        self.assertIn("--max-redirs 0", workflow)
        self.assertIn("if: vars.DEPLOY_ENABLED == 'true'", workflow)
        self.assertNotIn("\n  push:", workflow)

        with TemporaryDirectory(prefix="cb-deploy-smoke-") as directory:
            root = Path(directory)
            published = root / "published"
            published.mkdir()
            response_file = published / "health"
            captured = root / "downloaded.json"
            version = json.loads(VERSION.read_text(encoding="utf-8"))["version"]

            class QuietHandler(SimpleHTTPRequestHandler):
                """Serve only fixtures from the synthetic release directory."""
                def log_message(self, *_: object) -> None:
                    pass

            handler = partial(QuietHandler, directory=str(published))
            server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
            worker = Thread(target=server.serve_forever, daemon=True)
            worker.start()
            try:
                url = f"http://127.0.0.1:{server.server_port}/health"
                response_file.write_text(json.dumps({
                    "status": "ok", "version": version, "release_sha": GOOD_SHA,
                }), encoding="utf-8")
                with urlopen(url, timeout=5) as response:
                    self.assertEqual(response.status, 200)
                    captured.write_bytes(response.read())
                success = self.run_smoke(captured)
                self.assertEqual(success.returncode, 0, success.stderr)
                self.assertEqual(success.stderr, "")
                self.assertIn("release identity verified", success.stdout)

                for wrong in (
                    {"status": "ok", "version": version, "release_sha": "b" * 40},
                    {"status": "ok", "version": "0.0.0", "release_sha": GOOD_SHA},
                    {"status": "degraded", "version": version, "release_sha": GOOD_SHA},
                    {"status": "ok", "version": version, "release_sha": GOOD_SHA, "token": "secret-value"},
                ):
                    with self.subTest(wrong=wrong):
                        response_file.write_text(json.dumps(wrong), encoding="utf-8")
                        with urlopen(url, timeout=5) as response:
                            captured.write_bytes(response.read())
                        failed = self.run_smoke(captured)
                        self.assertEqual(failed.returncode, 1)
                        self.assertEqual(failed.stdout, "")
                        self.assertNotIn("secret-value", failed.stderr)
                        self.assertNotIn(GOOD_SHA, failed.stderr)

                response_file.unlink()
                with self.assertRaises(HTTPError) as not_found:
                    urlopen(url, timeout=5)
                self.assertEqual(not_found.exception.code, 404)
            finally:
                server.shutdown()
                server.server_close()
                worker.join(timeout=5)

    def test_bad_input_fails_closed_without_echo(self) -> None:
        """Reject malformed, oversized and network-sourced identity fixtures."""
        with TemporaryDirectory(prefix="cb-deploy-smoke-neg-") as directory:
            fixture = Path(directory) / "health.json"
            for content in ("", "{bad-json", "[]", json.dumps([1, 2]), "X" * 8193):
                with self.subTest(length=len(content)):
                    fixture.write_text(content, encoding="utf-8")
                    result = self.run_smoke(fixture)
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(result.stdout, "")
                    self.assertEqual(result.stderr, "Health smoke failed: identity unavailable or mismatched.\n")
            network = self.run_smoke(Path("https://example.test/health"))
            self.assertEqual(network.returncode, 1)


if __name__ == "__main__":
    unittest.main()
