"""PHP-backed acceptance for offline-only GitHub projection pipeline (#943)."""
from __future__ import annotations
import base64
import copy
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

from tests.test_github_readonly_installation_source import fixture as source_fixture, request as source_request, SECRET
from tests.test_github_readonly_projection_writer import canonical_snapshot, manifest_for
from tests.test_github_readonly_https_transport import request as transport_request

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubReadOnlyProjectionPipeline;
use ControlBot\GitHub\GitHubReadOnlyInstallationSource;
use ControlBot\GitHub\GitHubReadOnlyHttpsTransport;
use ControlBot\GitHub\GitHubReadOnlyProjectionWriter;
require_once 'src/GitHubReadOnlyProjectionPipeline.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if (!is_string($raw) || strlen($raw) > 100000) { throw new RuntimeException('fixture'); }
    $a = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $sourceCalls = 0; $httpCalls = 0; $witnessCalls = 0;
    $fakeSource = static function (array $query) use (&$sourceCalls, $a): array {
        $sourceCalls++;
        if (($a['source_mode'] ?? '') === 'timeout') { throw new RuntimeException('private@example.com'); }
        return [
            'status' => ($a['source_mode'] ?? '') === 'forbidden' ? 403 : 200,
            'repository' => $a['fixture']['repository'], 'sha' => $a['fixture']['sha'],
            'page' => $query['page'], 'items' => [], 'complete' => true,
            'truncated' => ($a['source_mode'] ?? '') === 'truncated', 'next_page' => null,
        ];
    };
    $fakeHttp = static function (array $request) use (&$httpCalls, $a): array {
        $httpCalls++;
        if (($a['http_mode'] ?? '') === 'timeout') { throw new RuntimeException('private@example.com'); }
        return [
            'status' => ($a['http_mode'] ?? '') === 'limited' ? 429 : 200,
            'content_type' => 'application/json', 'redirected' => false,
            'body' => json_encode([
                'page' => 1, 'items' => [], 'complete' => true, 'next_page' => null
            ], JSON_THROW_ON_ERROR),
        ];
    };
    $witness = static function () use (&$witnessCalls, $a): array {
        $witnessCalls++;
        return ($a['race'] ?? false) && $witnessCalls > 1 ? [] : $a['expected'];
    };
    $src = new GitHubReadOnlyInstallationSource($fakeSource, $a['key']);
    $https = new GitHubReadOnlyHttpsTransport($fakeHttp, $a['adapter_enabled']);
    $writer = new GitHubReadOnlyProjectionWriter($a['adapter_enabled']);
    $pipeline = new GitHubReadOnlyProjectionPipeline($src, $https, $writer, $a['enabled']);
    $result = $pipeline->project(
        $a['fixture'], $a['source_request'], $a['https_request'],
        $a['snapshots'], $a['expected'], $a['directory'], $a['now'],
        $a['omit_witness'] ? null : $witness
    );
    $target = $a['directory'] . '/projection.json';
    echo json_encode([
        'result' => $result, 'source_calls' => $sourceCalls, 'http_calls' => $httpCalls,
        'witness_calls' => $witnessCalls,
        'file' => is_file($target) ? file_get_contents($target) : null
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) { echo json_encode(['error' => 'fixture_invalid']); }
'''

def run(directory, **updates):
    snap = canonical_snapshot(observed=1000)
    snapshots = updates.pop("snapshots", [snap])
    source = source_fixture()
    https = transport_request()
    https["expires_at"] = source["expires_at"]
    params = {
        "enabled": False, "adapter_enabled": True, "omit_witness": False,
        "fixture": source, "source_request": source_request(),
        "https_request": https, "snapshots": snapshots, "expected": manifest_for(snapshots),
        "now": 1050, "directory": str(directory), "key": SECRET,
        "source_mode": "ok", "http_mode": "ok", "race": False,
    }
    params.update(updates)
    arg = base64.b64encode(json.dumps(params).encode()).decode()
    p = subprocess.run(["php", "-r", PHP, arg], cwd=ROOT, capture_output=True,
                       text=True, timeout=20, check=False)
    if p.returncode:
        raise AssertionError(p.stdout + p.stderr)
    return json.loads(p.stdout)

class GitHubReadOnlyProjectionPipelineTests(unittest.TestCase):
    def safe(self, obj):
        r = obj["result"]
        self.assertIs(r["runtime_authorized"], False)
        self.assertIs(r["production_verified"], False)
        self.assertIs(r["execute_actions"], False)
        self.assertEqual(r["authority"], "unchanged")

    def test_pipeline_is_disabled_by_default_and_never_calls_github_or_runtime(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            a = run(root)
            self.safe(a)
            self.assertEqual(a["result"]["status"], "UNKNOWN")
            self.assertEqual((a["source_calls"], a["http_calls"], a["witness_calls"]), (0, 0, 0))
            self.assertIsNone(a["file"])
            b = run(root, enabled=True, omit_witness=True)
            self.assertEqual(b["result"]["status"], "UNKNOWN")
            self.assertEqual((b["source_calls"], b["http_calls"]), (0, 0))
            source = (ROOT / "src/GitHubReadOnlyProjectionPipeline.php").read_text()
            for banned in ("curl_exec(", "getenv(", "file_get_contents(", "stream_socket_client(", "fsockopen("):
                self.assertNotIn(banned, source)

    def test_synthetic_readonly_source_builds_exact_typed_snapshot_and_atomic_fixture_projection(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            got = run(root, enabled=True)
            self.safe(got)
            self.assertEqual(got["result"]["status"], "CURRENT")
            self.assertEqual((got["source_calls"], got["http_calls"], got["witness_calls"]), (1, 1, 2))
            self.assertEqual(json.loads(got["file"]), {
                "version": 1, "projects": [canonical_snapshot(observed=1000)]
            })

    def test_missing_stale_truncated_or_mismatched_source_preserves_previous_projection(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            old = run(root, enabled=True)["file"]
            wrong = canonical_snapshot(observed=1000, sha="b" * 40)
            for patch in (
                {"source_mode": "truncated"}, {"source_mode": "forbidden"},
                {"source_mode": "timeout"}, {"http_mode": "limited"},
                {"http_mode": "timeout"}, {"snapshots": [wrong]},
                {"now": 1150}, {"race": True}, {"expected": []},
                {"https_request": {**transport_request(), "repository": "unknown/repo"}},
            ):
                with self.subTest(patch=patch):
                    got = run(root, enabled=True, **patch)
                    self.safe(got)
                    self.assertEqual(got["result"]["status"], "UNKNOWN")
                    self.assertEqual(got["file"], old)
            self.assertFalse(list(Path(root).glob(".projection-stage-*")))

    def test_pii_secrets_grants_and_real_activation_are_rejected(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            old = run(root, enabled=True)["file"]
            pii = canonical_snapshot(observed=1000)
            pii["repositories"][0]["issues"]["items"] = [
                {"number": 1, "title": "private@example.com", "labels": []}]
            secret = canonical_snapshot(observed=1000)
            secret["repositories"][0]["checks"]["items"][0]["name"] = "ghp_TEST_SECRET"
            for patch in (
                {"snapshots": [pii]}, {"snapshots": [secret]},
                {"fixture": {**source_fixture(), "scopes": ["contents:write", "metadata:read"]}},
                {"fixture": {**source_fixture(), "grants": ["pr.merge"]}},
                {"fixture": {**source_fixture(), "test_only": False}},
                {"source_request": {**source_request(), "authorization": "fake-token"}},
            ):
                with self.subTest(patch=str(patch)[:100]):
                    got = run(root, enabled=True, **patch)
                    self.safe(got)
                    self.assertEqual(got["result"]["status"], "UNKNOWN")
                    self.assertEqual(got["file"], old)
            docs = (ROOT / "docs/github-readonly-projection-pipeline.md").read_text()
            for required in ("OFF", "runtime_authorized=false", "production_verified=false", "execute_actions=false"):
                self.assertIn(required, docs)

if __name__ == "__main__":
    unittest.main()
