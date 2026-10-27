"""PHP-backed offline-only E2E: authorized ControlBot #945/A, parent #55."""
from __future__ import annotations

import base64
import json
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest

from tests.test_github_readonly_projection_pipeline import run as pipeline_run

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubProjectEntrypoint;
use ControlBot\GitHub\GitHubPublicEntrypoint;
require_once 'src/GitHubProjectEntrypoint.php';
require_once 'src/GitHubPublicEntrypoint.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if (!is_string($raw) || strlen($raw) > 16384) {
        throw new RuntimeException('fixture');
    }
    $a = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $config = ['owner_login' => 'fixture-owner', 'max_age_seconds' => 300];
    $user = $a['user'] ?? 'fixture-owner';
    $method = $a['method'] ?? 'GET';
    $now = $a['now'] ?? 1050;
    $file = $a['projection_path'];
    $project = GitHubProjectEntrypoint::handle([
        'remote_user' => $user, 'method' => $method,
        'path' => '/projects/factory-control/github',
    ], $config, $now, $file);
    $global = GitHubPublicEntrypoint::handle([
        'remote_user' => $user, 'method' => $method, 'path' => '/github',
    ], $config, $now, $file);
    echo json_encode(['project' => $project, 'global' => $global], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid']);
}
'''


def private_fixture(directory: str) -> Path:
    """Writer does not enforce mode 0700; harness must do so and verify it."""
    root = Path(directory)
    root.chmod(0o700)
    assert not root.is_symlink()
    assert stat.S_IMODE(root.stat().st_mode) == 0o700
    return root


def render(projection: Path, *, user: str = "fixture-owner",
           method: str = "GET", now: int = 1050) -> dict:
    arg = base64.b64encode(json.dumps({
        "projection_path": str(projection), "user": user,
        "method": method, "now": now,
    }).encode()).decode()
    process = subprocess.run(
        ["php", "-r", PHP, arg], cwd=ROOT, capture_output=True,
        text=True, timeout=20, check=False,
    )
    if process.returncode:
        raise AssertionError(process.stderr.strip() or process.stdout.strip())
    return json.loads(process.stdout)


class GitHubProjectionOwnerE2ETests(unittest.TestCase):
    def test_pipeline_fixture_renders_owner_project_view(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            root = private_fixture(directory)
            out = pipeline_run(root, enabled=True)
            self.assertEqual(out["result"]["status"], "CURRENT")
            self.assertEqual(
                (out["source_calls"], out["http_calls"], out["witness_calls"]),
                (1, 1, 2),
            )
            output = render(root / "projection.json")
            for page in ("project", "global"):
                with self.subTest(page=page):
                    self.assertEqual(output[page]["status"], 200)
                    self.assertEqual(output[page]["headers"]["Cache-Control"], "no-store")
                    self.assertIn("pl0n3r/ControlBot", output[page]["body"])
            self.assertIn("Estado <strong>CURRENT</strong>", output["project"]["body"])
            self.assertIn("state=current", output["global"]["body"])
            self.assertIn("freshness=current", output["global"]["body"])

    def test_non_owner_and_write_requests_fail_closed(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            root = private_fixture(directory)
            self.assertEqual(pipeline_run(root, enabled=True)["result"]["status"], "CURRENT")
            path = root / "projection.json"
            for user, method, expected in (
                ("not-owner", "GET", 403), ("fixture-owner", "POST", 405),
                ("fixture-owner", "PUT", 405), ("fixture-owner", "DELETE", 405),
            ):
                with self.subTest(user=user, method=method):
                    response = render(path, user=user, method=method)
                    for page in ("project", "global"):
                        self.assertEqual(response[page]["status"], expected)
                        self.assertNotIn("pl0n3r/ControlBot", response[page]["body"])
                        self.assertNotIn("factory-control", response[page]["body"])
                        if expected == 405:
                            self.assertEqual(response[page]["headers"]["Allow"], "GET")

    def test_stale_or_invalid_projection_never_claims_current(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            root = private_fixture(directory)
            self.assertEqual(pipeline_run(root, enabled=True)["result"]["status"], "CURRENT")
            path = root / "projection.json"
            stale = render(path, now=1500)
            self.assertEqual(stale["project"]["status"], 200)
            self.assertIn("Freshness <strong>STALE</strong>", stale["project"]["body"])
            self.assertNotIn("Estado <strong>CURRENT</strong>", stale["project"]["body"])
            self.assertIn("state=unknown", stale["global"]["body"])
            self.assertIn("freshness=stale", stale["global"]["body"])
            path.write_text("{invalid JSON", encoding="utf-8")
            invalid = render(path)
            self.assertEqual(invalid["project"]["status"], 404)
            self.assertIn("state=unknown", invalid["global"]["body"])
            self.assertIn("freshness=unknown", invalid["global"]["body"])
            self.assertNotIn("factory-control", invalid["global"]["body"])

    def test_fixture_permissions_and_runtime_remain_disabled(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            root = private_fixture(directory)
            disabled = pipeline_run(root)
            self.assertEqual(disabled["result"]["status"], "UNKNOWN")
            self.assertEqual((disabled["source_calls"], disabled["http_calls"]), (0, 0))
            self.assertFalse((root / "projection.json").exists())
            out = pipeline_run(root, enabled=True)
            self.assertEqual(out["result"]["status"], "CURRENT")
            file = root / "projection.json"
            self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o700)
            self.assertEqual(stat.S_IMODE(file.stat().st_mode), 0o600)
            self.assertFalse(file.is_symlink())
            self.assertFalse(list(root.glob(".projection-stage-*")))
            for flag in ("runtime_authorized", "production_verified", "execute_actions"):
                self.assertIs(out["result"][flag], False)
            self.assertNotIn("ghp_", out["file"])
            self.assertNotIn("github_pat_", out["file"])
            self.assertNotIn("curl_exec(", (ROOT / "src/GitHubReadOnlyProjectionPipeline.php").read_text())
            self.assertEqual(render(file)["project"]["status"], 200)


if __name__ == "__main__":
    unittest.main()
