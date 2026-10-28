"""Contract tests for ControlBot #1003: REAL offline PHP admission + pipeline + writer."""
from __future__ import annotations
import base64
import copy
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from tests.test_github_readonly_installation_admission import (
    fixture as installation_fixture,
    grant as owner_grant,
    request as admission_request,
)
from tests.test_github_readonly_installation_source import (
    fixture as source_fixture,
    request as source_request,
    SECRET,
)
from tests.test_github_readonly_projection_writer import canonical_snapshot, manifest_for
from tests.test_github_readonly_https_transport import request as https_request
ROOT = Path(__file__).resolve().parents[1]
GATE = ROOT / "src/GitHubReadOnlyAdmissionProjectionGate.php"
PHP = r"""
use ControlBot\GitHub\GitHubReadOnlyAdmissionProjectionGate;
use ControlBot\GitHub\GitHubReadOnlyInstallationAdmission;
use ControlBot\GitHub\GitHubReadOnlyProjectionPipeline;
use ControlBot\GitHub\GitHubReadOnlyInstallationSource;
use ControlBot\GitHub\GitHubReadOnlyHttpsTransport;
use ControlBot\GitHub\GitHubReadOnlyProjectionWriter;
require_once 'src/GitHubReadOnlyAdmissionProjectionGate.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if (!is_string($raw) || strlen($raw) > 150000) { throw new RuntimeException('fixture'); }
    $a = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $sourceCalls = 0; $httpCalls = 0; $bindingCalls = 0; $manifestCalls = 0;
    $fakeSource = static function (array $request) use (&$sourceCalls, $a): array {
        $sourceCalls++;
        if (($a['source_mode'] ?? '') === 'timeout') { throw new RuntimeException('synthetic only'); }
        return [
            'status' => 200, 'repository' => $a['source_fixture']['repository'],
            'sha' => $a['source_fixture']['sha'], 'page' => $request['page'],
            'items' => [], 'complete' => true, 'truncated' => false,
            'next_page' => null,
        ];
    };
    $fakeHttp = static function (array $envelope) use (&$httpCalls): array {
        $httpCalls++;
        return [
            'status' => 200, 'content_type' => 'application/json',
            'redirected' => false,
            'body' => '{"page":1,"items":[],"complete":true,"next_page":null}',
        ];
    };
    $binding = static function () use (&$bindingCalls, $a): array {
        $bindingCalls++;
        return (($a['race_at'] ?? 0) > 0 && $bindingCalls >= $a['race_at'])
            ? [] : $a['binding'];
    };
    $manifest = static function () use (&$manifestCalls, $a): array {
        $manifestCalls++;
        return ($a['manifest_wrong'] ?? false) ? [] : $a['expected'];
    };
    $pipeline = new GitHubReadOnlyProjectionPipeline(
        new GitHubReadOnlyInstallationSource($fakeSource, $a['key']),
        new GitHubReadOnlyHttpsTransport($fakeHttp, true),
        new GitHubReadOnlyProjectionWriter(true),
        true
    );
    $gate = new GitHubReadOnlyAdmissionProjectionGate(
        new GitHubReadOnlyInstallationAdmission(), $pipeline, $a['enabled']
    );
    $result = $gate->project(
        $a['installation'], $a['grant'], $a['admission_request'],
        $a['source_fixture'], $a['source_request'], $a['https_request'],
        $a['snapshots'], $a['expected'], $a['directory'], $a['now'],
        $a['omit_binding'] ? null : $binding,
        $a['omit_manifest'] ? null : $manifest
    );
    $target = $a['directory'] . '/projection.json';
    echo json_encode([
        'result' => $result, 'source_calls' => $sourceCalls,
        'http_calls' => $httpCalls, 'binding_calls' => $bindingCalls,
        'manifest_calls' => $manifestCalls,
        'file' => is_file($target) ? file_get_contents($target) : null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) { echo json_encode(['error' => 'synthetic_fixture_invalid']); }
"""
def fixture_payload(directory):
    installation = installation_fixture()
    source = source_fixture()
    snapshot = canonical_snapshot(observed=1000)
    https = https_request()
    https["expires_at"] = source["expires_at"]
    payload = {
        "enabled": True, "installation": installation, "grant": owner_grant(), "admission_request": admission_request(), "source_fixture": source, "source_request": source_request(), "https_request": https,
        "snapshots": [snapshot], "expected": manifest_for([snapshot]), "now": 1050, "directory": str(directory), "key": SECRET, "race_at": 0, "manifest_wrong": False, "omit_binding": False, "omit_manifest": False,
        "source_mode": "ok",
    }
    payload["binding"] = {
        "installation_id": installation["installation_id"], "revision": installation["revision"], "owner": installation["owner"], "repository": installation["repository"], "resource": "issues", "main_sha": source["sha"],
        "observed_at": source["observed_at"], "expires_at": source["expires_at"],
    }
    return payload
def run(directory, **changes):
    payload = fixture_payload(directory)
    for name, value in changes.items():
        payload[name] = copy.deepcopy(value)
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    p = subprocess.run(["php", "-r", PHP, encoded], cwd=ROOT, text=True,
                       capture_output=True, timeout=25, check=False)
    if p.returncode:
        raise AssertionError(p.stderr or p.stdout)
    result = json.loads(p.stdout)
    if "error" in result:
        raise AssertionError(result["error"])
    return result
class GitHubReadOnlyAdmissionProjectionGateTests(unittest.TestCase):
    def safe(self, data):
        r = data["result"]
        self.assertIs(r["runtime_authorized"], False)
        self.assertIs(r["execute_actions"], False)
        self.assertIs(r["production_verified"], False)
        self.assertEqual(r["authority"], "unchanged")
        for value in ("Authorization", "Bearer", "github_pat_", "ghp_"):
            self.assertNotIn(value, json.dumps(r))
    def denied_without_sender(self, directory, **updates):
        got = run(directory, **updates)
        self.safe(got)
        self.assertEqual(got["result"]["status"], "UNKNOWN")
        self.assertEqual(got["source_calls"], 0)
        self.assertEqual(got["http_calls"], 0)
        return got
    def test_matching_synthetic_installation_and_projection_stay_offline(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            got = run(root)
            self.safe(got)
            self.assertEqual(got["result"]["status"], "OFFLINE_VALIDATED")
            self.assertEqual(got["result"]["projection_status"], "CURRENT")
            self.assertEqual((got["source_calls"], got["http_calls"]), (1, 1))
            self.assertEqual(json.loads(got["file"])["projects"], [canonical_snapshot(observed=1000)])
            self.assertGreaterEqual(got["binding_calls"], 5)
            # No opt-in to runtime: default remains off.
            self.denied_without_sender(root, enabled=False)
            self.denied_without_sender(root, omit_binding=True)
            self.denied_without_sender(root, omit_manifest=True)
    def test_revoked_or_expired_installation_never_reaches_projection(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            old = run(root)["file"]
            f = installation_fixture()
            g = owner_grant()
            cases = [
                {"installation": {**f, "status": "revoked"}},
                {"installation": {**f, "revoked_at": 1040}},
                {"installation": {**f, "expires_at": 1050}},
                {"installation": {**f, "issued_at": 1051}},
                {"installation": {**f, "revision": 3}},
                {"grant": {**g, "expires_at": 1050}},
                {"grant": {**g, "issued_at": 1051}},
                {"grant": {**g, "issued_at": 900}},
                {"grant": {**g, "expires_at": 1250}},
                {"grant": {**g, "owner_verified": False}},
            ]
            for case in cases:
                with self.subTest(case=case):
                    result = self.denied_without_sender(root, **case)
                    self.assertEqual(result["file"], old)
    def test_repository_revision_scope_or_evidence_mismatch_fails_closed(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            good = fixture_payload(root)
            old = run(root)["file"]
            variants = [
                {"admission_request": {**good["admission_request"], "resource": "pulls"}},
                {"admission_request": {**good["admission_request"], "revision": 3}},
                {"admission_request": {**good["admission_request"], "method": "POST"}},
                {"installation": {**good["installation"], "permissions": { "metadata": "read", "pull_requests": "read"}}},
                {"source_fixture": {**good["source_fixture"], "installation_id": 888}},
                {"source_fixture": {**good["source_fixture"], "repository": "pl0n3r/Condor"}},
                {"source_request": {**good["source_request"], "resource": "pulls"}},
                {"https_request": {**good["https_request"], "repository": "pl0n3r/Condor"}},
                {"expected": []},
                {"binding": {**good["binding"], "revision": 9}},
                {"manifest_wrong": True},
            ]
            for case in variants:
                with self.subTest(case=case):
                    result = self.denied_without_sender(root, **case)
                    self.assertEqual(result["file"], old)
            for race_at in (2, 4, 5):
                with self.subTest(race_at=race_at):
                    result = run(root, race_at=race_at)
                    self.safe(result)
                    self.assertEqual(result["result"]["status"], "UNKNOWN")
                    self.assertEqual(result["file"], old)
                    self.assertFalse(list(Path(root).glob(".projection-stage-*")))
            # This tests the real writer's before/after-staging witness calls,
            # NOT a hypothetical revocation after its final check/rename.
    def test_no_runtime_permissions_network_tokens_or_mutations_are_enabled(self):
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as root:
            result = run(root)
            self.safe(result)
            self.assertEqual(result["result"]["status"], "OFFLINE_VALIDATED")
            source = GATE.read_text()
            for forbidden in ("curl_exec(", "getenv(", "stream_socket_client(",
                              "fsockopen(", "shell_exec(", "system(", "exec(", "file_get_contents(",
                              "Authorization:", "github_pat_", "ghp_"):
                self.assertNotIn(forbidden, source)
            self.assertIn("'runtime_authorized' => false", source)
            self.assertIn("'execute_actions' => false", source)
            self.assertIn("'production_verified' => false", source)
            self.assertNotIn("https://", source)
            doc = (ROOT / "docs/github-readonly-admission-projection-gate.md").read_text()
            for contract in ("runtime_authorized=false", "execute_actions=false",
                             "production_verified=false", "OFFLINE", "rename"):
                self.assertIn(contract, doc)
            self.assertFalse(list(Path(root).glob(".projection-stage-*")))
if __name__ == "__main__":
    unittest.main()
