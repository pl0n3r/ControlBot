"""Isolated owner/HMAC fixture harness, with no GitHub runtime authority (#875)."""
from __future__ import annotations

import base64
import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KEY = bytes(range(32, 64)).hex()
PROJECT = "controlbot:project/controlbot"
REPO = "pl0n3r/ControlBot"
CAPS = {"workflow.dispatch": "github.workflow.dispatch",
        "project.freeze": "github.project.write"}

# Inline PHP runner, deliberately not an additional claimed source file.
PHP = r"""
require_once 'src/GitHubIntentOfflineGrantHarness.php';
try {
    if ($argc !== 2) throw new InvalidArgumentException('fixture missing');
    $raw = base64_decode($argv[1], true);
    if ($raw === false || strlen($raw) > 16384)
        throw new InvalidArgumentException('fixture too large');
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['key'] ?? null))
        throw new InvalidArgumentException('invalid fixture');
    $key = hex2bin($data['key']);
    if ($key === false) throw new InvalidArgumentException('invalid fixture key');
    $result = \ControlBot\GitHub\GitHubIntentOfflineGrantHarness::simulate(
        $data['requirements'], $data['approval'], $data['trusted'],
        $data['seen'], $data['revoked'], $data['now'], $key,
        $data['fake_action']
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode([
        'decision' => 'deny', 'execution' => false,
        'runtime_authorized' => false, 'authorized' => false,
        'grant_issued' => false, 'approval_issued' => false,
        'approval_verified' => false, 'test_grant_valid' => false,
        'fixture_status' => 'rejected',
        'fake_effect' => ['status' => 'denied',
            'reason' => 'invalid_fixture', 'fixture_digest' => null]
    ], JSON_THROW_ON_ERROR);
}
"""


def candidate(action="workflow.dispatch"):
    scope = "project" if action.startswith("project.") else "repository"
    ref = PROJECT if scope == "project" else REPO
    return {
        "requirements": {
            "version": 1, "state": "requirements", "type": action,
            "capability": CAPS[action], "scope": scope, "scope_ref": ref,
            "scope_parameters": {scope + "_ref": ref},
            "approval_class": "owner_required", "authorized": False,
            "grant_issued": False, "approval_issued": False,
            "approval_verified": False, "execution": False,
        },
        "approval": {
            "origin": "server", "test_only": True, "owner": "pl0n3r",
            "owner_verified": True, "action_type": action, "scope_ref": ref,
            "approved": True, "approved_at": 1040,
            "approval_ref": "fixture:" + "ab" * 8, "revoked": False,
        },
        "trusted": {
            "origin": "server", "mode": "offline_test_only",
            "issuer": "controlbot:authority", "owner": "pl0n3r",
            "project_ref": PROJECT, "repository_ref": REPO,
            "nonce": "cd" * 16, "ttl_seconds": 120,
        },
        "seen": [], "revoked": [], "now": 1100, "key": KEY,
        "fake_action": {
            "type": action, "scope_ref": ref,
            "fixture_ref": "controlbot:fixture/acceptance",
        },
    }


def execute(case):
    payload = base64.b64encode(json.dumps(case).encode()).decode("ascii")
    result = subprocess.run(
        ["php", "-r", PHP, payload], cwd=ROOT, text=True,
        capture_output=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stderr or result.stdout)
    return json.loads(result.stdout)


class GitHubIntentOfflineGrantHarnessTests(unittest.TestCase):
    def assert_denied_runtime(self, output):
        self.assertEqual(output["decision"], "deny")
        for name in ("execution", "runtime_authorized", "authorized",
                     "grant_issued", "approval_issued", "approval_verified"):
            self.assertIs(output[name], False)

    def test_exact_owner_and_scope_required(self):
        for action in CAPS:
            with self.subTest(action=action):
                valid = execute(candidate(action))
                self.assert_denied_runtime(valid)
                self.assertEqual(valid["fixture_status"], "accepted_offline")
        for section, field, value in (
            ("approval", "owner", "someone-else"),
            ("approval", "owner_verified", False),
            ("approval", "scope_ref", "pl0n3r/Other"),
            ("approval", "approved", False),
            ("approval", "origin", "client"),
            ("trusted", "owner", "someone-else"),
            ("trusted", "repository_ref", "pl0n3r/Other"),
            ("trusted", "origin", "client"),
            ("trusted", "mode", "production"),
            ("requirements", "scope_ref", "pl0n3r/Other"),
            ("fake_action", "scope_ref", "pl0n3r/Other"),
        ):
            with self.subTest(section=section, field=field):
                row = candidate()
                row[section][field] = value
                result = execute(row)
                self.assert_denied_runtime(result)
                self.assertEqual(result["fake_effect"]["status"], "denied")

    def test_signed_fixture_accepts_only_isolated_fake_action(self):
        row = candidate()
        result = execute(row)
        self.assert_denied_runtime(result)
        self.assertTrue(result["test_grant_valid"])
        self.assertEqual(result["fixture_status"], "accepted_offline")
        self.assertEqual(result["fake_effect"]["status"], "simulated")
        digest = result["fake_effect"]["fixture_digest"]
        self.assertEqual(len(digest), 64)
        self.assertEqual(result, execute(copy.deepcopy(row)))
        self.assertNotIn(KEY, json.dumps(result))
        self.assertNotIn("signature", result)
        for change in (
            ("type", "pr.merge"),
            ("fixture_ref", "https://github.com/pl0n3r/ControlBot"),
            ("fixture_ref", "../outside"),
        ):
            altered = copy.deepcopy(row)
            altered["fake_action"][change[0]] = change[1]
            denied = execute(altered)
            self.assert_denied_runtime(denied)
            self.assertEqual(denied["fake_effect"]["status"], "denied")

    def test_ttl_replay_revocation_and_unknown_action_fail_closed(self):
        for section, field, value in (
            ("trusted", "ttl_seconds", 301),
            ("trusted", "ttl_seconds", 0),
            ("trusted", "nonce", "invalid"),
            ("approval", "approved_at", 700),
            ("approval", "approved_at", 1200),
            ("approval", "revoked", True),
            ("requirements", "type", "github.admin"),
            ("requirements", "capability", "github.admin"),
            ("fake_action", "type", "github.admin"),
        ):
            with self.subTest(section=section, field=field):
                row = candidate()
                row[section][field] = value
                result = execute(row)
                self.assert_denied_runtime(result)
                self.assertEqual(result["fake_effect"]["status"], "denied")
        for field in ("seen", "revoked"):
            row = candidate()
            row[field] = ["cd" * 16]
            result = execute(row)
            self.assert_denied_runtime(result)
            self.assertEqual(result["fake_effect"]["status"], "denied")

    def test_production_authority_remains_denied_without_mutation(self):
        source = (ROOT / "src/GitHubIntentOfflineGrantHarness.php").read_text()
        for forbidden in (
            "curl_exec(", "file_get_contents(", "shell_exec(",
            "exec(", "proc_open(", "GitHubClient", "GitHubApi",
        ):
            self.assertNotIn(forbidden, source)
        for variant in (candidate(), candidate("project.freeze")):
            before = copy.deepcopy(variant)
            result = execute(variant)
            self.assertEqual(variant, before)
            self.assert_denied_runtime(result)
            self.assertEqual(result["fake_effect"]["status"], "simulated")
            self.assertNotIn("authorization", result)
            self.assertNotIn("token", json.dumps(result).lower())
        altered = candidate()
        altered["fake_action"]["callback"] = "unexpected"
        denied = execute(altered)
        self.assert_denied_runtime(denied)
        self.assertEqual(denied["fake_effect"]["status"], "denied")


if __name__ == "__main__":
    unittest.main()
