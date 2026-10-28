"""Synthetic PHP-backed admission contract for ControlBot #998; no network."""
from __future__ import annotations

import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubReadOnlyInstallationAdmission;
require_once 'src/GitHubReadOnlyInstallationAdmission.php';
try {
    $encoded = $argv[1] ?? '';
    if (strlen($encoded) > 65536) { throw new RuntimeException('fixture'); }
    $raw = base64_decode($encoded, true);
    if ($raw === false) { throw new RuntimeException('fixture'); }
    $input = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($input)) { throw new RuntimeException('fixture'); }
    $admission = new GitHubReadOnlyInstallationAdmission();
    $out = $admission->inspect($input['fixture'], $input['grant'], $input['request'], $input['now']);
    echo json_encode(['out' => $out, 'audit' => $admission->audit()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'invalid_test_fixture']);
}
'''


def fixture():
    return {
        "origin": "synthetic_installation_fixture", "test_only": True,
        "installation_id": 142, "owner": "pl0n3r",
        "repository": "pl0n3r/ControlBot",
        "permissions": {"metadata": "read", "issues": "read"},
        "status": "active", "revision": 2,
        "issued_at": 1000, "expires_at": 1200, "revoked_at": None,
    }


def grant():
    return {
        "origin": "synthetic_owner_approval", "test_only": True,
        "owner_verified": True, "installation_id": 142,
        "owner": "pl0n3r", "repository": "pl0n3r/ControlBot",
        "revision": 2, "issued_at": 1010, "expires_at": 1150,
    }


def request():
    return {
        "method": "GET", "repository": "pl0n3r/ControlBot",
        "resource": "issues", "installation_id": 142, "revision": 2,
    }


def run(*, f=None, g=None, req=None, now=1050):
    payload = {"fixture": fixture() if f is None else f,
               "grant": grant() if g is None else g,
               "request": request() if req is None else req, "now": now}
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    proc = subprocess.run(["php", "-r", PHP, encoded], cwd=ROOT,
                          text=True, capture_output=True, timeout=15, check=False)
    if proc.returncode:
        raise AssertionError(proc.stderr.strip() or proc.stdout.strip())
    parsed = json.loads(proc.stdout)
    if "error" in parsed:
        raise AssertionError(parsed["error"])
    return parsed


class GitHubReadOnlyInstallationAdmissionTests(unittest.TestCase):
    def assert_safe(self, result):
        self.assertIn("out", result)
        self.assertIs(result["out"]["runtime_authorized"], False)
        self.assertIs(result["out"]["production_verified"], False)
        self.assertIs(result["out"]["execute_actions"], False)
        self.assertEqual(result["out"]["authority"], "unchanged")
        self.assertNotIn("token", json.dumps(result).lower())
        self.assertEqual(len(result["audit"]), 1)
        self.assertNotIn("installation_id", result["audit"][0])

    def test_explicit_owner_installation_and_repository_read_scopes_are_required(self):
        valid = run()
        self.assert_safe(valid)
        self.assertEqual(valid["out"]["status"], "OFFLINE_VALIDATED")
        self.assertIs(valid["out"]["fixture_validated"], True)
        for data in [
            {"f": {**fixture(), "installation_id": 0}},
            {"f": {**fixture(), "owner": "other"}},
            {"f": {**fixture(), "repository": "pl0n3r/Condor"}},
            {"f": {**fixture(), "permissions": {"metadata": "read"}}},
            {"f": {**fixture(), "permissions": {"metadata": "read", "issues": "read", "pull_requests": "read"}}},
            {"g": {**grant(), "installation_id": 99}},
            {"g": {**grant(), "repository": "pl0n3r/brvtal"}},
        ]:
            with self.subTest(data=data):
                denied = run(**data)
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
        pulls = run(f={**fixture(), "permissions": {"metadata": "read", "pull_requests": "read"}},
                    req={**request(), "resource": "pulls"})
        self.assert_safe(pulls)
        self.assertEqual(pulls["out"]["status"], "OFFLINE_VALIDATED")

    def test_revoked_expired_or_replaced_installation_fails_closed_before_any_sender(self):
        cases = [
            {"f": {**fixture(), "status": "revoked"}},
            {"f": {**fixture(), "revoked_at": 1040}},
            {"f": {**fixture(), "revision": 3}},
            {"req": {**request(), "revision": 1}},
            {"g": {**grant(), "revision": 3}},
            {"f": {**fixture(), "expires_at": 1050}},
            {"g": {**grant(), "expires_at": 1050}},
            {"f": {**fixture(), "issued_at": 1060}},
            {"now": 1200},
        ]
        for data in cases:
            with self.subTest(data=data):
                denied = run(**data)
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertIs(denied["out"]["fixture_validated"], False)
        # The production sender is neither accepted as input nor invoked.
        forbidden = run(req={**request(), "sender": "example.invalid"})
        self.assertEqual(forbidden["out"]["status"], "UNKNOWN")

    def test_mutating_permissions_unknown_scopes_or_unverified_owner_never_admit(self):
        cases = [
            {"f": {**fixture(), "permissions": {"metadata": "read", "issues": "write"}}},
            {"f": {**fixture(), "permissions": {"metadata": "read", "issues": "unknown"}}},
            {"f": {**fixture(), "permissions": {"metadata": "read", "issues": "read", "contents": "read"}}},
            {"f": {**fixture(), "permissions": {"metadata": "admin", "issues": "read"}}},
            {"g": {**grant(), "owner_verified": False}},
            {"g": {**grant(), "origin": "browser"}},
            {"g": {**grant(), "test_only": False}},
            {"req": {**request(), "method": "POST"}},
            {"req": {**request(), "resource": "hooks"}},
            {"req": {**request(), "repository": "other/repo"}},
            {"f": {**fixture(), "token": "not-a-real-token"}},
        ]
        for data in cases:
            with self.subTest(data=data):
                denied = run(**data)
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")

    def test_offline_admission_never_issues_tokens_or_runtime_authority(self):
        for data in [{}, {"now": 1200}, {"g": {**grant(), "owner_verified": False}}]:
            with self.subTest(data=data):
                result = run(**data)
                self.assert_safe(result)
        php_text = (ROOT / "src/GitHubReadOnlyInstallationAdmission.php").read_text()
        for forbidden in ("curl_exec", "file_get_contents(", "http://", "https://", "getenv(",
                          "shell_exec(", "exec(", "socket_create("):
            self.assertNotIn(forbidden, php_text)
        self.assertIn("runtime_authorized' => false", php_text)
        self.assertIn("execute_actions' => false", php_text)


if __name__ == "__main__":
    unittest.main()
