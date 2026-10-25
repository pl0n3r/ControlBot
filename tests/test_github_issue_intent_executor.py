"""AC-01..04: #761 offline-only PHP intent simulator, never a GitHub API client."""
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
CAP = "github.issue.write"
def candidate(action="issue.create"):
    params = {
        "issue.create": {"payload_ref": "controlbot:fixture/payload"},
        "issue.update": {"issue_number": 101, "payload_ref": "controlbot:fixture/payload"},
        "issue.close": {"issue_number": 101},
        "issue.reserve": {"issue_number": 101, "reservation_ref": "controlbot:fixture/reservation"},
        "issue.release": {"issue_number": 101, "reservation_ref": "controlbot:fixture/reservation"},
    }[action]
    return {
        "intent": {
            "version": 1, "intent_id": "ab" * 16, "project_ref": PROJECT,
            "repository_ref": REPO, "type": action, "params": params,
            "idempotency_key": "fixture-issue-action-0001",
            "evidence_refs": ["github:fixture/evidence"],
        },
        "fixture": {
            "requirements": {
                "version": 1, "state": "requirements", "type": action,
                "capability": CAP, "scope": "repository", "scope_ref": REPO,
                "scope_parameters": {"repository_ref": REPO},
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
            "issue_number": 101, "outcome": "success",
        },
        "state": {
            "issues": [] if action == "issue.create" else [
                {"number": 101, "state": "open", "reservation_ref":
                 "controlbot:fixture/reservation" if action == "issue.release" else None}
            ],
            "events": [],
        },
        "audit": [], "ledger": {},
    }
def execute(payload):
    encoded = base64.b64encode(json.dumps(payload).encode("utf-8")).decode("ascii")
    process = subprocess.run(
        ["php", "tests/github_issue_intent_executor_scenarios.php", encoded],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if process.returncode:
        raise AssertionError(process.stderr or process.stdout)
    return json.loads(process.stdout)
class GitHubIssueIntentExecutorTests(unittest.TestCase):
    def assert_offline(self, result):
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", result)
        self.assertNotIn("transport", result)
    def test_rejected_policy_never_calls_transport(self):
        original = candidate()
        good = execute(original)
        self.assert_offline(good)
        self.assertEqual("simulated", good["fake_effect"]["status"])
        self.assertEqual(1, len(good["fake_state"]["events"]))
        for path, value in (
            (("trusted", "mode"), "production"),
            (("approval", "approved"), False),
            (("approval", "owner_verified"), False),
            (("trusted", "project_ref"), "controlbot:project/elsewhere"),
        ):
            invalid = copy.deepcopy(original)
            invalid["fixture"][path[0]][path[1]] = value
            result = execute(invalid)
            self.assert_offline(result)
            self.assertEqual("denied", result["fake_effect"]["status"])
            self.assertEqual(original["state"], result["fake_state"])
            self.assertEqual([], result["audit"])
            self.assertEqual([], result["ledger"])
    def test_authorized_retry_does_not_duplicate_issue(self):
        original = candidate()
        created = execute(original)
        self.assert_offline(created)
        self.assertEqual("executed", created["receipt"]["status"])
        self.assertEqual([101], [x["number"] for x in created["fake_state"]["issues"]])
        repeat = copy.deepcopy(original)
        repeat["state"] = created["fake_state"]
        repeat["audit"] = created["audit"]
        repeat["ledger"] = created["ledger"]
        replay = execute(repeat)
        self.assert_offline(replay)
        self.assertTrue(replay["replayed"])
        self.assertEqual("simulated", replay["fake_effect"]["status"])
        self.assertEqual(created["fake_state"], replay["fake_state"])
        self.assertEqual(created["audit"], replay["audit"])
        self.assertEqual(created["receipt"], replay["receipt"])
        wrong = copy.deepcopy(repeat)
        wrong["fixture"]["issue_number"] = 102
        conflict = execute(wrong)
        self.assertEqual("denied", conflict["fake_effect"]["status"])
        self.assertEqual(created["fake_state"], conflict["fake_state"])
    def test_ambiguous_or_failed_transport_is_fail_closed(self):
        for action in ("issue.create", "issue.update", "issue.close",
                       "issue.reserve", "issue.release"):
            for outcome in ("ambiguous", "failure"):
                payload = candidate(action)
                payload["fixture"]["outcome"] = outcome
                result = execute(payload)
                self.assert_offline(result)
                expected = "ambiguous" if outcome == "ambiguous" else "failed"
                self.assertEqual(expected, result["fake_effect"]["status"])
                self.assertEqual(expected, result["receipt"]["status"])
                self.assertEqual(payload["state"], result["fake_state"])
                self.assertEqual([], result["audit"])
                self.assertEqual([], result["ledger"])
        for action in ("issue.update", "issue.close", "issue.reserve", "issue.release"):
            payload = candidate(action)
            result = execute(payload)
            self.assert_offline(result)
            self.assertEqual("simulated", result["fake_effect"]["status"])
            self.assertEqual(1, len(result["fake_state"]["events"]))
        absent = candidate("issue.reserve")
        absent["state"]["issues"] = []
        self.assertEqual("denied", execute(absent)["fake_effect"]["status"])
        mismatched = candidate("issue.release")
        mismatched["state"]["issues"][0]["reservation_ref"] = "controlbot:fixture/other"
        self.assertEqual("denied", execute(mismatched)["fake_effect"]["status"])
    def test_result_contract_and_no_secrets(self):
        payload = candidate()
        result = execute(payload)
        self.assert_offline(result)
        receipt = result["receipt"]
        self.assertEqual({
            "intent_id", "project_id", "repository_id", "type", "status",
            "idempotency_key", "request_digest", "evidence_ref",
            "started_at", "finished_at",
        }, set(receipt))
        self.assertEqual("executed", receipt["status"])
        self.assertEqual(64, len(receipt["request_digest"]))
        self.assertTrue(all(isinstance(receipt[k], int) for k in ("started_at", "finished_at")))
        serialized = json.dumps(result).lower()
        for secret in ("password", "bearer", "private_key", KEY.lower(),
                       "fixture_proof", '"transport"', "access_token"):
            self.assertNotIn(secret, serialized)
        hostile = candidate()
        hostile["fixture"]["trusted"]["key"] = "NEVER-EXPOSE-A-SECRET"
        result = execute(hostile)
        self.assert_offline(result)
        self.assertEqual("denied", result["fake_effect"]["status"])
        self.assertNotIn("NEVER-EXPOSE-A-SECRET", json.dumps(result))
        payload["intent"]["type"] = "pr.merge"
        self.assertEqual("denied", execute(payload)["fake_effect"]["status"])
if __name__ == "__main__":
    unittest.main()
