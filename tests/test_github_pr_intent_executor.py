"""AC-01..04: execute PHP against synthetic PR/release fixtures only."""
import base64
import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KEY = bytes(range(32, 64)).hex()
HEAD = "a" * 40
REPO = "pl0n3r/ControlBot"
PROJECT = "controlbot:project/controlbot"
CHECKS = [{"name": n, "status": "completed", "conclusion": "success"}
          for n in ("Validar", "SonarCloud Code Analysis", "CodeQL", "Factory policy")]
def candidate(action="pr.merge"):
    params = {
        "pr.review": {"pr_number": 101, "review_ref": "controlbot:fixture/review"},
        "pr.merge": {"pr_number": 101, "expected_head_sha": HEAD, "merge_method": "squash"},
        "release.approve": {"release_ref": "controlbot:fixture/release",
                            "candidate_sha": HEAD, "approval_ref": "controlbot:fixture/approval"},
    }
    return {
        "intent": {"version": 1, "intent_id": "ab" * 16, "project_ref": PROJECT,
                   "repository_ref": REPO, "type": action, "params": params[action],
                   "idempotency_key": "fixture-pr-action-0001",
                   "evidence_refs": ["github:fixture/evidence"]},
        "fixture": {
            "requirements": {"version": 1, "state": "requirements", "type": action,
                             "capability": {"pr.review": "github.pr.review", "pr.merge": "github.pr.merge",
                                            "release.approve": "github.release.approve"}[action],
                             "scope": "repository", "scope_ref": REPO,
                             "scope_parameters": {"repository_ref": REPO},
                             "approval_class": "owner_required", "authorized": False,
                             "grant_issued": False, "approval_issued": False,
                             "approval_verified": False, "execution": False},
            "approval": {"origin": "server", "test_only": True, "owner": "pl0n3r",
                         "owner_verified": True, "action_type": action,
                         "scope_ref": REPO, "approved": True, "approved_at": 1040,
                         "approval_ref": "fixture:" + "ab" * 8, "revoked": False},
            "trusted": {"origin": "server", "mode": "offline_test_only",
                        "issuer": "controlbot:authority", "owner": "pl0n3r",
                        "project_ref": PROJECT, "repository_ref": REPO,
                        "nonce": "cd" * 16, "ttl_seconds": 120},
            "seen": [], "revoked": [], "now": 1100, "key": KEY,
            "pr": None if action == "release.approve" else
                  {"number": 101, "head_sha": HEAD, "mergeable": True,
                   "checks": copy.deepcopy(CHECKS)},
            "release": {"release_ref": "controlbot:fixture/release", "candidate_sha": HEAD,
                        "ready": True} if action == "release.approve" else None,
            "outcome": "success"},
        "state": {"reviews": [], "merges": [], "approvals": []}, "audit": [], "ledger": {},
    }
def execute(payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode("ascii")
    result = subprocess.run(["php", "tests/github_pull_request_intent_executor_scenarios.php", encoded],
                            cwd=ROOT, text=True, capture_output=True, timeout=30, check=False)
    if result.returncode:
        raise AssertionError(result.stderr or result.stdout)
    return json.loads(result.stdout)
class GitHubPullRequestIntentExecutorTests(unittest.TestCase):
    def assert_denied_runtime(self, result):
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", result)
    def test_mismatched_head_sha_is_rejected_without_transport_call(self):
        valid = candidate()
        self.assertEqual(1, len(execute(valid)["fake_state"]["merges"]))
        for kind, field, value in (("intent", "expected_head_sha", "b" * 40),
                                   ("pr", "head_sha", "b" * 40), ("pr", "number", 102)):
            bad = copy.deepcopy(valid)
            target = bad["intent"]["params"] if kind == "intent" else bad["fixture"]["pr"]
            target[field] = value
            result = execute(bad)
            self.assert_denied_runtime(result)
            self.assertEqual("denied", result["fake_effect"]["status"])
            self.assertEqual(valid["state"], result["fake_state"])
    def test_merge_requires_terminal_green_checks_and_trusted_authority(self):
        changes = (
            lambda p: p["fixture"]["pr"].update(mergeable=False),
            lambda p: p["fixture"]["pr"]["checks"][0].update(status="in_progress", conclusion=None),
            lambda p: p["fixture"]["pr"]["checks"][1].update(conclusion="failure"),
            lambda p: p["fixture"]["pr"]["checks"].pop(),
            lambda p: p["fixture"]["pr"]["checks"].append(copy.deepcopy(CHECKS[0])),
            lambda p: p["fixture"]["approval"].update(approved=False),
            lambda p: p["fixture"]["approval"].update(owner_verified=False),
            lambda p: p["fixture"]["trusted"].update(nonce="invalid"),
            lambda p: p["fixture"].update(now=4000),
            lambda p: p["fixture"].update(seen=["cd" * 16]),
            lambda p: p["fixture"].update(revoked=["cd" * 16]),
            lambda p: p["fixture"].update(key="01" * 10),
        )
        for index, change in enumerate(changes):
            with self.subTest(case=index):
                bad = candidate()
                change(bad)
                result = execute(bad)
                self.assert_denied_runtime(result)
                self.assertEqual("denied", result["fake_effect"]["status"])
                self.assertEqual([], result["fake_state"]["merges"])
                self.assertEqual({}, result["ledger"])
    def test_authorized_retry_reuses_receipt_without_repeating_merge(self):
        payload = candidate()
        first = execute(payload)
        self.assert_denied_runtime(first)
        self.assertEqual("simulated", first["fake_effect"]["status"])
        self.assertEqual(1, len(first["fake_state"]["merges"]))
        retry = copy.deepcopy(payload)
        for key, output_key in (("state", "fake_state"), ("audit", "audit"), ("ledger", "ledger")):
            retry[key] = first[output_key]
        repeat = execute(retry)
        self.assertTrue(repeat["replayed"])
        self.assertEqual(first["fake_state"], repeat["fake_state"])
        conflict = copy.deepcopy(retry)
        conflict["intent"]["params"]["merge_method"] = "rebase"
        self.assertEqual("idempotency_conflict", execute(conflict)["fake_effect"]["reason"])
        for outcome, status in (("ambiguous", "ambiguous"), ("failure", "failed")):
            bad = candidate()
            bad["fixture"]["outcome"] = outcome
            result = execute(bad)
            self.assert_denied_runtime(result)
            self.assertEqual(status, result["fake_effect"]["status"])
            self.assertEqual([], result["fake_state"]["merges"])
            self.assertEqual({}, result["ledger"])
    def test_sanitized_result_contract_and_release_approval_has_no_deploy(self):
        for action, key in (("pr.review", "reviews"), ("release.approve", "approvals")):
            with self.subTest(action=action):
                result = execute(candidate(action))
                self.assert_denied_runtime(result)
                self.assertEqual("simulated", result["fake_effect"]["status"])
                self.assertEqual(1, len(result["fake_state"][key]))
                self.assertEqual([], result["fake_state"]["merges"])
                for forbidden in (KEY, "signature", "http", "token"):
                    self.assertNotIn(forbidden, json.dumps(result).lower())
                if action == "release.approve":
                    self.assertIs(result["fake_state"]["approvals"][0]["published"], False)
        bad = candidate("release.approve")
        bad["fixture"]["release"]["candidate_sha"] = "b" * 40
        self.assertEqual("denied", execute(bad)["fake_effect"]["status"])
if __name__ == "__main__":
    unittest.main()
