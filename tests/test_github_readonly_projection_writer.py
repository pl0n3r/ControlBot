"""PHP-backed offline-only projection writer tests. No network or production target."""
from __future__ import annotations

import base64
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubReadOnlyProjectionWriter;
require_once 'src/GitHubReadOnlyProjectionWriter.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if ($raw === false || strlen($raw) > 200000) { throw new RuntimeException('fixture'); }
    $arg = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $calls = 0;
    $witness = static function () use (&$calls, $arg): array {
        $calls++;
        return ($arg['race'] ?? false) && $calls > 1
            ? [] : $arg['manifest'];
    };
    $writer = new GitHubReadOnlyProjectionWriter($arg['enabled']);
    $result = $writer->write($arg['snapshots'], $arg['manifest'],
        $arg['directory'], $arg['now'], $arg['max_age'],
        ($arg['omit_witness'] ?? false) ? null : $witness);
    $target = $arg['directory'] . '/projection.json';
    echo json_encode(['result' => $result, 'calls' => $calls,
        'file' => is_file($target) ? file_get_contents($target) : null], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid']);
}
'''


def canonical_snapshot(observed=900, repo="pl0n3r/ControlBot", sha="a" * 40, project="factory-control"):
    return {
        "version": 1, "project_id": project, "observed_at": observed,
        "repositories": [{
            "repository_id": "controlbot", "repository": repo,
            "source_ref": "https://github.com/" + repo,
            "observed_at": observed, "main_sha": sha,
            "checks": {"items": [{"name": "CI", "status": "completed", "conclusion": "success"}], "truncated": False},
            "pull_requests": {"items": [], "truncated": False},
            "issues": {"items": [], "truncated": False},
            "latest_release": None, "latest_workflow": None,
        }],
    }


def manifest_for(snapshots):
    return [{
        "project_id": x["project_id"], "observed_at": x["observed_at"],
        "repositories": [{
            "repository": r["repository"], "main_sha": r["main_sha"],
            "observed_at": r["observed_at"],
        } for r in x["repositories"]],
    } for x in snapshots]


def run(directory, snapshots=None, *, enabled=False, manifest=None, now=1000, max_age=300,
        race=False, omit_witness=False):
    snapshots = [canonical_snapshot()] if snapshots is None else snapshots
    inp = {"snapshots": snapshots, "manifest": manifest_for(snapshots) if manifest is None else manifest,
           "enabled": enabled, "now": now, "max_age": max_age, "directory": str(directory),
           "race": race, "omit_witness": omit_witness}
    encoded = base64.b64encode(json.dumps(inp).encode()).decode()
    proc = subprocess.run(["php", "-r", PHP, encoded], cwd=ROOT, capture_output=True,
                          text=True, timeout=25, check=False)
    if proc.returncode:
        raise AssertionError(proc.stderr.strip() or proc.stdout.strip())
    return json.loads(proc.stdout)


class GitHubReadOnlyProjectionWriterTests(unittest.TestCase):
    def test_disabled_without_explicit_owner_fixture(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            path = Path(root) / "projection.json"
            disabled = run(root)
            self.assertEqual(disabled["result"]["status"], "UNKNOWN")
            self.assertIsNone(disabled["file"])
            self.assertEqual(disabled["calls"], 0)
            self.assertFalse(path.exists())
            no_witness = run(root, enabled=True, omit_witness=True)
            self.assertEqual(no_witness["result"]["status"], "UNKNOWN")
            self.assertFalse(path.exists())

    def test_atomic_projection_validates_project_sha_and_ttl(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            snap = canonical_snapshot()
            first = run(root, [snap], enabled=True)
            self.assertEqual(first["result"]["status"], "CURRENT")
            self.assertEqual(first["calls"], 2)
            self.assertEqual(json.loads(first["file"]), {"version": 1, "projects": [snap]})
            self.assertEqual(first["result"]["repository_count"], 1)
            other = canonical_snapshot(repo="pl0n3r/Condor", project="factory-other", sha="b" * 40)
            second = run(root, [snap, other], enabled=True)
            self.assertEqual(second["result"]["status"], "CURRENT")
            self.assertEqual(len(json.loads(second["file"])["projects"]), 2)
            for result in (first, second):
                self.assertIs(result["result"]["production_verified"], False)
                self.assertIs(result["result"]["runtime_authorized"], False)
                self.assertIs(result["result"]["execute_actions"], False)

    def test_partial_stale_racy_or_duplicate_projects_fail_closed(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            good = canonical_snapshot()
            accepted = run(root, enabled=True)
            previous = accepted["file"]
            altered = canonical_snapshot(sha="b" * 40)
            bad_cases = [
                {"snapshots": [good], "manifest": manifest_for([altered])},
                {"snapshots": [good], "race": True},
                {"snapshots": [good, good]},
                {"snapshots": [canonical_snapshot(observed=600)]},
                {"snapshots": [canonical_snapshot(observed=1100)]},
                {"snapshots": [{**good, "repositories": [{**good["repositories"][0],
                                "checks": {"items": [], "truncated": True}}]}]},
                {"snapshots": [{**good, "repositories": []}]},
                {"snapshots": [canonical_snapshot(sha="not-a-sha")]},
                {"snapshots": [{**good, "repositories": [{**good["repositories"][0],
                                "issues": {"items": [{"number": 1, "title": "private@example.com", "labels": []}], "truncated": False}}]}]},
                {"snapshots": [canonical_snapshot(repo="pl0n3r/ControlBot")],
                 "manifest": []},
            ]
            for case in bad_cases:
                with self.subTest(case=str(case)[:100]):
                    got = run(root, enabled=True, **case)
                    self.assertEqual(got["result"]["status"], "UNKNOWN")
                    self.assertEqual(got["file"], previous, "a rejected write must preserve the last valid snapshot")
            self.assertFalse(list(Path(root).glob(".projection-stage-*")))

    def test_no_network_tokens_pii_or_runtime_activation(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            got = run(root, enabled=True)
            self.assertEqual(got["result"]["status"], "CURRENT")
            source = (ROOT / "src/GitHubReadOnlyProjectionWriter.php").read_text()
            for banned in ("curl_exec(", "file_get_contents('https", "stream_socket_client(",
                           "fsockopen(", "Authorization:", "GuzzleHttp", "getenv("):
                self.assertNotIn(banned, source)
            for secret in ("token", "private", "email", "credential"):
                self.assertNotIn(secret, json.dumps(got["result"]).lower())
            self.assertNotIn("production_verified\":true", json.dumps(got))
            self.assertIn("$target = $directory . '/projection.json';", source)
            docs = (ROOT / "docs/github-readonly-projection-writer.md").read_text()
            for value in ("runtime_authorized=false", "production_verified=false", "execute_actions=false"):
                self.assertIn(value, docs)
            self.assertIn("OFF", docs)


if __name__ == "__main__":
    unittest.main()
