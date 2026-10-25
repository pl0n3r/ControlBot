"""Offline issuer requires test-only owner approval; never creates GitHub authority."""
from __future__ import annotations

import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE_KEY = bytes(range(32, 64))  # deterministic fixture, no external token
PHP = r"""
require 'src/GitHubIntentOfflineIssuer.php';
require 'src/GitHubIntentGrantVerification.php';
$data = json_decode(base64_decode($argv[1], true), true, 64, JSON_THROW_ON_ERROR);
$key = hex2bin($data['key']);
$result = \ControlBot\GitHub\GitHubIntentOfflineIssuer::issue(
    $data['requirements'], $data['approval'], $data['trusted'],
    $data['seen'], $data['revoked'], $data['now'], $key
);
$verified = null;
if ($result['proof'] !== null) {
    $verified = \ControlBot\GitHub\GitHubIntentGrantVerification::inspect(
        $data['requirements'], $result['proof'], [
            'origin' => 'server', 'issuer' => $data['trusted']['issuer'],
            'owner' => $data['trusted']['owner'],
            'project_ref' => $data['trusted']['project_ref'],
            'repository_ref' => $data['trusted']['repository_ref'],
        ], $data['seen'], $data['now'], $key
    );
}
echo json_encode(['issuer' => $result, 'verification' => $verified], JSON_THROW_ON_ERROR);
"""


def candidate(action="pr.merge"):
    scoped = action.startswith("project.")
    scope = "project" if scoped else "repository"
    ref = "controlbot:project/controlbot" if scoped else "pl0n3r/ControlBot"
    capability = {
        "pr.merge": "github.pr.merge",
        "issue.update": "github.issue.write",
        "project.freeze": "github.project.write",
        "release.approve": "github.release.approve",
    }[action]
    return {
        "requirements": {
            "version": 1, "state": "requirements", "type": action,
            "capability": capability, "scope": scope, "scope_ref": ref,
            "scope_parameters": {f"{scope}_ref": ref},
            "approval_class": "owner_required", "authorized": False,
            "grant_issued": False, "approval_issued": False,
            "approval_verified": False, "execution": False,
        },
        "approval": {
            "origin": "server", "test_only": True, "owner": "pl0n3r",
            "owner_verified": True, "action_type": action, "scope_ref": ref,
            "approved": True, "approved_at": 1060,
            "approval_ref": "fixture:" + "ab" * 8, "revoked": False,
        },
        "trusted": {
            "origin": "server", "mode": "offline_test_only",
            "issuer": "controlbot:authority", "owner": "pl0n3r",
            "project_ref": "controlbot:project/controlbot",
            "repository_ref": "pl0n3r/ControlBot",
            "nonce": "cd" * 16, "ttl_seconds": 120,
        },
        "seen": [], "revoked": [], "now": 1100, "key": FIXTURE_KEY.hex(),
    }


def execute(case):
    payload = base64.b64encode(json.dumps(case).encode()).decode()
    run = subprocess.run(
        ["php", "--", payload], input="<?php\n" + PHP,
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout)
    return json.loads(run.stdout)


class GitHubIntentOfflineIssuerTests(unittest.TestCase):
    def assert_no_authority(self, issuer):
        self.assertEqual(issuer["decision"], "deny")
        for key in ("authorized", "grant_issued", "approval_issued",
                    "approval_verified", "execution"):
            self.assertIs(issuer[key], False)

    def test_owner_approval_and_server_scope_are_required_without_issuance(self):
        edits = (
            ("approval", "approved", False),
            ("approval", "owner", "other"),
            ("approval", "owner_verified", False),
            ("approval", "origin", "client"),
            ("approval", "test_only", False),
            ("approval", "action_type", "issue.update"),
            ("approval", "scope_ref", "other-repo"),
            ("approval", "approval_ref", "real:123"),
            ("approval", "approved_at", 700),
            ("trusted", "origin", "client"),
            ("trusted", "mode", "production"),
            ("trusted", "repository_ref", "different"),
            ("requirements", "grant_issued", True),
        )
        for section, key, value in edits:
            with self.subTest(section=section, key=key):
                c = candidate()
                c[section][key] = value
                result = execute(c)["issuer"]
                self.assertIsNone(result["proof"])
                self.assert_no_authority(result)
        case = candidate()
        case["approval"]["extra_token"] = "private"
        self.assertIsNone(execute(case)["issuer"]["proof"])

    def test_approved_fixture_signs_verifiable_non_executable_proof(self):
        for action in ("pr.merge", "issue.update", "project.freeze",
                       "release.approve"):
            with self.subTest(action=action):
                row = execute(candidate(action))
                issued, verified = row["issuer"], row["verification"]
                self.assertEqual(issued["reason"], "offline_only")
                self.assert_no_authority(issued)
                self.assertEqual(len(issued["proof"]["signature"]), 64)
                self.assertEqual(verified["reason"], "offline_only")
                self.assertIs(verified["proof_valid"], True)
                self.assertEqual(verified["decision"], "deny")
                self.assertIs(verified["execution"], False)

    def test_unknown_actions_ttl_replay_and_revocation_fail_closed(self):
        for section, key, value in (
            ("requirements", "type", "github.admin"),
            ("requirements", "capability", "github.admin"),
            ("trusted", "ttl_seconds", 301),
            ("trusted", "ttl_seconds", 0),
            ("trusted", "nonce", "bad"),
            ("approval", "revoked", True),
            ("approval", "approved_at", 1101),
            ("trusted", "issuer", ""),
        ):
            with self.subTest(section=section, key=key):
                case = candidate()
                case[section][key] = value
                result = execute(case)["issuer"]
                self.assert_no_authority(result)
                self.assertIsNone(result["proof"])
        for key in ("seen", "revoked"):
            with self.subTest(key=key):
                case = candidate()
                case[key] = [case["trusted"]["nonce"]]
                self.assertIsNone(execute(case)["issuer"]["proof"])

    def test_no_capability_registration_tokens_network_or_real_authority(self):
        source = (ROOT / "src/GitHubIntentOfflineIssuer.php").read_text()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(",
                          "CapabilityGrant::issue(", "GithubClient", "github_pat_",
                          "'decision' => 'allow'", "'execution' => true"):
            self.assertNotIn(forbidden, source)
        capability = (ROOT / "src/CapabilityPolicy.php").read_text()
        self.assertNotIn("'github.pr.merge' =>", capability)
        case = candidate()
        case["key"] = "01" * 12
        self.assertIsNone(execute(case)["issuer"]["proof"])


if __name__ == "__main__":
    unittest.main()
