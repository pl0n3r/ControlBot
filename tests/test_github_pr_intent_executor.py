"""Executable AC-01..04 for #762: real PHP with fake memory, never GitHub writes."""
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
HEAD = "a" * 40
CAPABILITIES = {"pr.review": "github.pr.review", "pr.merge": "github.pr.merge",
                "release.approve": "github.release.approve"}
CHECKS = [
    {"name": name, "status": "completed", "conclusion": "success"}
    for name in ("Validar", "SonarCloud Code Analysis", "CodeQL", "Factory policy")
]


def candidate(action="pr.merge"):
    params = {
        "pr.review": {"pr_number": 101, "review_ref": "controlbot:fixture/review"},
        "pr.merge": {"pr_number": 101, "expected_head_sha": HEAD, "merge_method": "squash"},
        "release.approve": {"release_ref": "controlbot:fixture/release",
                            "candidate_sha": HEAD, "approval_ref": "controlbot:fixture/approval"},
    }[action]
    return {
        "intent": {
            "version": 1, "intent_id": "ab" * 16, "project_ref": PROJECT,
            "repository_ref": REPO, "type": action, "params": params,
            "idempotency_key": "fixture-pr-action-0001",
            "evidence_refs": ["github:fixture/evidence"],
        },
        "fixture": {
            "requirements": {
                "version": 1, "state": "requirements", "type": action,
                "capability": CAPABILITIES[action], "scope": "repository",
                "scope_ref": REPO, "scope_parameters": {"repository_ref": REPO},
                "approval_class": "owner_required", "authorized": False,
                "grant_issued": False, "approval_issued": False,
                "approval_verified": False, "execution": False,
            },
            "approval": {
                "origin": "server", "test_only": True, "owner": "pl0n3r",
                "owner_verified": True, "action_type": action,
                "scope_ref": REPO, "approved": True, "approved_at": 1040,
                "approval_ref": "fixture:" + "ab" * 8, "revoked": False,
            },
            "trusted": {
                "origin": "server", "mode": "offline_test_only",
                "issuer": "controlbot:authority", "owner": "pl0n3r",
                "project_ref": PROJECT, "repository_ref": REPO,
                "nonce": "cd" * 16, "ttl_seconds": 120,
            },
            "seen": [], "revoked": [], "now": 1100, "key": KEY,
            "pr": None if action == "release.approve" else {
                "number": 101, "head_sha": HEAD, "mergeable": True,
                "checks": copy.deepcopy(CHECKS),
            },
            "release": {"release_ref": "controlbot:fixture/release",
                        "candidate_sha": HEAD, "ready": True}
            if action == "release.approve" else None,
            "outcome": "success",
        },
        "state": {"reviews": [], "merges": [], "approvals": []},
        "audit": [], "ledger": {},
    }


def execute(payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode("ascii")
    result = subprocess.run(
        ["php", "tests/github_pull_request_intent_executor_scenarios.php", encoded],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(result.stderr or result.stdout)
    return json.loads(result.stdout)


class GitHubPullRequestIntentExecutorTests(unittest.TestCase):
    def assert_runtime_denied(self, result):
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", result)
        self.assertNotIn("transport", result)

    def test_mismatched_head_sha_is_rejected_without_transport_call(self):
        valid = candidate()
        result = execute(valid)
        self.assert_runtime_denied(result)
        self.assertEqual("simulated", result["fake_effect"]["status"])
        self.assertEqual(1, len(result["fake_state"]["merges"]))
        for mutate in (
            lambda p: p["intent"]["params"].update(expected_head_sha="b" * 40),
            lambda p: p["fixture"]["pr"].update(head_sha="b" * 40),
            lambda p: p["fixture"]["pr"].update(number=102),
        ):
            bad = copy.deepcopy(valid)
            mutate(bad)
            output = execute(bad)
            self.assert_runtime_denied(output)
            self.assertEqual("denied", output["fake_effect"]["status"])
            self.assertEqual(valid["state"], output["fake_state"])
            self.assertEqual([], output["audit"])
        no_privileges = copy.deepcopy(valid)
        no_privileges["fixture"]["trusted"]["mode"] = "production"
        self.assertEqual("denied", execute(no_privileges)["fake_effect"]["status"])

    def test_merge_requires_terminal_green_checks_and_trusted_authority(self):
        valid = candidate()
        for mutation in (
            lambda p: p["fixture"]["pr"].update(mergeable=False),
            lambda p: p["fixture"]["pr"]["checks"][0].update(status="in_progress", conclusion=None),
            lambda p: p["fixture"]["pr"]["checks"][1].update(conclusion="failure"),
            lambda p: p["fixture"]["pr"]["checks"].pop(),
            lambda p: p["fixture"]["pr"]["checks"].append(copy.deepcopy(CHECKS[0])),
            lambda p: p["fixture"]["approval"].update(approved=False),
            lambda p: p["fixture"]["approval"].update(owner_verified=False),
            lambda p: p["fixture"]["trusted"].update(nonce="ef" * 16),
            lambda p: p["fixture"].update(now=4000),
            lambda p: p["fixture"].update(seen=["cd" * 16]),
            lambda p: p["fixture"].update(revoked=["cd" * 16]),
            lambda p: p["fixture"].update(key="01" * 10),
        ):
            with self.subTest(mutation=str(mutation)):
                bad = copy.deepcopy(valid)
                mutation(bad)
                result = execute(bad)
                self.assert_runtime_denied(result)
                self.assertEqual("denied", result["fake_effect"]["status"])
                self.assertEqual([], result["fake_state"]["merges"])
                self.assertEqual({}, result["ledger"])

    def test_authorized_retry_reuses_receipt_without_repeating_merge(self):
        initial = candidate()
        first = execute(initial)
        self.assert_runtime_denied(first)
        self.assertEqual("simulated", first["fake_effect"]["status"])
        self.assertEqual(1, len(first["audit"]))
        self.assertEqual(1, len(first["fake_state"]["merges"]))
        retry = copy.deepcopy(initial)
        retry["state"], retry["audit"], retry["ledger"] = (
            first["fake_state"], first["audit"], first["ledger"]
        )
        repeated = execute(retry)
        self.assert_runtime_denied(repeated)
        self.assertTrue(repeated["replayed"])
        self.assertEqual(first["fake_state"], repeated["fake_state"])
        self.assertEqual(first["audit"], repeated["audit"])
        conflict = copy.deepcopy(retry)
        conflict["intent"]["params"]["merge_method"] = "rebase"
        bad = execute(conflict)
        self.assertEqual("idempotency_conflict", bad["fake_effect"]["reason"])
        self.assertEqual(first["fake_state"], bad["fake_state"])
        for outcome in ("ambiguous", "failure"):
            first_attempt = candidate()
            first_attempt["fixture"]["outcome"] = outcome
            failed = execute(first_attempt)
            self.assert_runtime_denied(failed)
            self.assertEqual({}, failed["ledger"])
            self.assertEqual([], failed["fake_state"]["merges"])
            self.assertEqual(outcome, failed["fake_effect"]["status"])

    def test_sanitized_result_contract_and_release_approval_has_no_deploy(self):
        for action, expected_key in (("pr.review", "reviews"),
                                     ("release.approve", "approvals")):
            with self.subTest(action=action):
                result = execute(candidate(action))
                self.assert_runtime_denied(result)
                self.assertEqual("simulated", result["fake_effect"]["status"])
                self.assertEqual(1, len(result["fake_state"][expected_key]))
                self.assertEqual(1, len(result["audit"]))
                self.assertNotIn(KEY, json.dumps(result))
                self.assertNotIn("signature", json.dumps(result))
                self.assertNotIn("http", json.dumps(result).lower())
                self.assertNotIn("token", json.dumps(result).lower())
                self.assertEqual([], result["fake_state"]["merges"])
                if action == "release.approve":
                    self.assertIs(result["fake_state"]["approvals"][0]["published"], False)
        bad = candidate("release.approve")
        bad["fixture"]["release"]["candidate_sha"] = "b" * 40
        self.assertEqual("denied", execute(bad)["fake_effect"]["status"])
        unsupported = candidate()
        unsupported["intent"]["type"] = "workflow.dispatch"
        self.assertEqual("denied", execute(unsupported)["fake_effect"]["status"])


if __name__ == "__main__":
    unittest.main()
