"""Owner-approved test grant composition, never GitHub runtime authority (#874)."""
from __future__ import annotations

import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ACTIONS = {
    "issue.create": "github.issue.write",
    "issue.update": "github.issue.write",
    "issue.close": "github.issue.write",
    "issue.reserve": "github.issue.write",
    "issue.release": "github.issue.write",
    "pr.review": "github.pr.review",
    "pr.merge": "github.pr.merge",
    "release.approve": "github.release.approve",
    "workflow.dispatch": "github.workflow.dispatch",
    "project.freeze": "github.project.write",
    "project.unfreeze": "github.project.write",
}
FIXTURE_KEY = bytes(range(32, 64)).hex()  # Synthetic, never a service token.


def candidate(action="workflow.dispatch"):
    scope = "project" if action.startswith("project.") else "repository"
    project = "controlbot:project/controlbot"
    repository = "pl0n3r/ControlBot"
    ref = project if scope == "project" else repository
    return {
        "requirements": {
            "version": 1, "state": "requirements", "type": action,
            "capability": ACTIONS[action], "scope": scope, "scope_ref": ref,
            "scope_parameters": {f"{scope}_ref": ref},
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
            "project_ref": project, "repository_ref": repository,
            "nonce": "cd" * 16, "ttl_seconds": 120,
        },
        "seen": [], "revoked": [], "now": 1100, "key": FIXTURE_KEY,
    }


def execute(case):
    encoded = base64.b64encode(json.dumps(case).encode()).decode()
    run = subprocess.run(
        ["php", "tests/github_intent_test_grant_authority_scenarios.php", encoded],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout)
    return json.loads(run.stdout)


class GitHubIntentTestGrantAuthorityTests(unittest.TestCase):
    def assert_runtime_denied(self, result):
        self.assertEqual(result["decision"], "deny")
        for key in ("runtime_authorized", "authorized", "grant_issued",
                    "approval_issued", "approval_verified", "execution"):
            self.assertIs(result[key], False)

    def test_owner_approved_fixture_validates_offline_catalog_without_runtime_authority(self):
        for action, capability in ACTIONS.items():
            with self.subTest(action=action):
                result = execute(candidate(action))
                self.assert_runtime_denied(result)
                self.assertEqual(result["reason"], "verified_test_only")
                self.assertEqual(result["catalog_class"], "owner_required")
                self.assertIs(result["test_grant_valid"], True)
                self.assertEqual(result["fixture_proof"]["capability"], capability)
                self.assertEqual(len(result["fixture_proof"]["signature"]), 64)
                self.assertNotIn("key", result)

    def test_scope_owner_approval_and_catalog_mismatch_fail_closed(self):
        cases = (
            ("approval", "approved", False),
            ("approval", "owner", "other"),
            ("approval", "owner_verified", False),
            ("approval", "origin", "client"),
            ("approval", "scope_ref", "pl0n3r/Other"),
            ("approval", "action_type", "issue.create"),
            ("approval", "test_only", False),
            ("trusted", "origin", "client"),
            ("trusted", "mode", "production"),
            ("trusted", "repository_ref", "pl0n3r/Other"),
            ("requirements", "type", "github.admin"),
            ("requirements", "capability", "github.admin"),
            ("requirements", "authorized", True),
        )
        for section, key, value in cases:
            with self.subTest(section=section, key=key):
                case = candidate()
                case[section][key] = value
                result = execute(case)
                self.assert_runtime_denied(result)
                self.assertIs(result["test_grant_valid"], False)
                self.assertIsNone(result["fixture_proof"])
        case = candidate()
        case["approval"]["token"] = "should-not-appear"
        result = execute(case)
        self.assertIs(result["test_grant_valid"], False)
        self.assertNotIn("should-not-appear", json.dumps(result))

    def test_ttl_nonce_replay_and_revocation_fail_closed(self):
        for section, key, value in (
            ("trusted", "ttl_seconds", 301),
            ("trusted", "ttl_seconds", 0),
            ("trusted", "nonce", "bad"),
            ("approval", "approved_at", 700),
            ("approval", "approved_at", 1200),
            ("approval", "revoked", True),
            ("trusted", "issuer", ""),
            ("key", None, "01" * 12),
        ):
            with self.subTest(section=section, key=key):
                row = candidate()
                if section == "key":
                    row["key"] = value
                else:
                    row[section][key] = value
                result = execute(row)
                self.assert_runtime_denied(result)
                self.assertIs(result["test_grant_valid"], False)
        for field in ("seen", "revoked"):
            with self.subTest(field=field):
                row = candidate()
                row[field].append(row["trusted"]["nonce"])
                result = execute(row)
                self.assert_runtime_denied(result)
                self.assertIs(result["test_grant_valid"], False)

    def test_no_network_secrets_runtime_grants_or_product_catalog_mutation(self):
        source = (ROOT / "src/GitHubIntentTestGrantAuthority.php").read_text()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(",
                          "CapabilityGrant::issue(", "github_pat_",
                          "'decision' => 'allow'", "'execution' => true"):
            self.assertNotIn(forbidden, source)
        catalog = (ROOT / "src/CapabilityPolicy.php").read_text()
        for cap in set(ACTIONS.values()):
            self.assertNotIn("'" + cap + "' =>", catalog)
        docs = (ROOT / "docs/github-intent-test-grant-authority.md").read_text()
        for phrase in ("#873", "#763", "offline_test_only", "No autoriza"):
            self.assertIn(phrase, docs)
        self.assert_runtime_denied(execute(candidate()))


if __name__ == "__main__":
    unittest.main()
