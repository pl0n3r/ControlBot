"""Executable AC-01..04: ControlBot #763 in-memory tests, never GitHub runtime."""
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
        "project.freeze": "github.project.write",
        "project.unfreeze": "github.project.write"}


def candidate(action="workflow.dispatch"):
    scope = "repository" if action == "workflow.dispatch" else "project"
    ref = REPO if scope == "repository" else PROJECT
    params = (
        {"workflow_ref": "ci.yml", "git_ref": "main",
         "inputs_ref": "controlbot:fixture/inputs"}
        if action == "workflow.dispatch" else {"reason_ref": "controlbot:fixture/reason"}
    )
    return {
        "intent": {
            "version": 1, "intent_id": "ab" * 16, "project_ref": PROJECT,
            "repository_ref": REPO, "type": action, "params": params,
            "idempotency_key": "fixture-action-0001",
            "evidence_refs": ["github:fixture/evidence"],
        },
        "fixture": {
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
                "owner_verified": True, "action_type": action,
                "scope_ref": ref, "approved": True, "approved_at": 1040,
                "approval_ref": "fixture:" + "ab" * 8, "revoked": False,
            },
            "trusted": {
                "origin": "server", "mode": "offline_test_only",
                "issuer": "controlbot:authority", "owner": "pl0n3r",
                "project_ref": PROJECT, "repository_ref": REPO,
                "nonce": "cd" * 16, "ttl_seconds": 120,
            },
            "seen": [], "revoked": [], "now": 1100, "key": KEY,
            "allowed_workflows": ["ci.yml"], "allowed_refs": ["main"],
            "inputs": {"environment": "fixture"}, "outcome": "success",
        },
        "state": {"frozen": False, "dispatches": []},
        "audit": [], "ledger": [],
    }


def execute(payload):
    data = base64.b64encode(json.dumps(payload).encode()).decode("ascii")
    cp = subprocess.run(
        ["php", "tests/github_workflow_intent_executor_scenarios.php", data],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if cp.returncode:
        raise AssertionError(cp.stderr or cp.stdout)
    return json.loads(cp.stdout)


class GitHubWorkflowIntentExecutorTests(unittest.TestCase):
    def assert_no_runtime(self, result):
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", result)

    def test_disallowed_workflow_and_ref_never_call_transport(self):
        base = candidate()
        result = execute(base)
        self.assert_no_runtime(result)
        self.assertEqual("simulated", result["fake_effect"]["status"])
        self.assertEqual(1, len(result["fake_state"]["dispatches"]))
        for key, value in [("workflow_ref", "deploy.yml"), ("git_ref", "feature/live")]:
            payload = copy.deepcopy(base)
            payload["intent"]["params"][key] = value
            result = execute(payload)
            self.assert_no_runtime(result)
            self.assertEqual("denied", result["fake_effect"]["status"])
            self.assertEqual(base["state"], result["fake_state"])
            self.assertEqual([], result["audit"])
        payload = copy.deepcopy(base)
        payload["intent"]["params"]["inputs_ref"] = "github:fixture/external"
        self.assertEqual("denied", execute(payload)["fake_effect"]["status"])

    def test_invalid_or_excessive_inputs_fail_closed(self):
        base = candidate()
        cases = []
        for section, name, value in [
            ("fixture", "inputs", {f"input_{i}": "value" for i in range(9)}),
            ("fixture", "inputs", {"private_token": "fake"}),
            ("fixture", "inputs", {"environment": "secret_value"}),
            ("fixture", "inputs", {"environment": "a" * 121}),
            ("fixture", "allowed_refs", ["main", "main"]),
            ("fixture", "allowed_workflows", ["ci.yml", "../unsafe"]),
            ("fixture", "key", "01" * 12),
            ("intent", "execution", True),
        ]:
            payload = copy.deepcopy(base)
            payload[section][name] = value
            cases.append(payload)
        for payload in cases:
            with self.subTest(payload=str(payload)[:100]):
                result = execute(payload)
                self.assert_no_runtime(result)
                self.assertEqual("denied", result["fake_effect"]["status"])
                self.assertEqual([], result["audit"])
        for section, name, value in [
            ("approval", "approved", False),
            ("approval", "owner_verified", False),
            ("trusted", "mode", "production"),
            ("trusted", "nonce", "invalid"),
        ]:
            payload = copy.deepcopy(base)
            payload["fixture"][section][name] = value
            self.assertEqual("denied", execute(payload)["fake_effect"]["status"])
        for field in ("seen", "revoked"):
            payload = copy.deepcopy(base)
            payload["fixture"][field] = ["cd" * 16]
            self.assertEqual("denied", execute(payload)["fake_effect"]["status"])

    def test_authorized_freeze_and_unfreeze_are_audited_reversible_and_idempotent(self):
        payload = candidate("project.freeze")
        first = execute(payload)
        self.assert_no_runtime(first)
        self.assertEqual("simulated", first["fake_effect"]["status"])
        self.assertIs(first["fake_state"]["frozen"], True)
        self.assertEqual(1, len(first["audit"]))
        self.assertIs(first["audit"][0]["reversible"], True)
        replay = copy.deepcopy(payload)
        replay["state"], replay["audit"], replay["ledger"] = (
            first["fake_state"], first["audit"], first["ledger"]
        )
        second = execute(replay)
        self.assert_no_runtime(second)
        self.assertTrue(second["replayed"])
        self.assertEqual(1, len(second["audit"]))
        self.assertEqual(first["fake_state"], second["fake_state"])
        collision = copy.deepcopy(replay)
        collision["intent"]["params"]["reason_ref"] = "controlbot:fixture/different"
        self.assertEqual("idempotency_conflict", execute(collision)["fake_effect"]["reason"])
        thaw = candidate("project.unfreeze")
        thaw["intent"]["idempotency_key"] = "fixture-action-0002"
        thaw["fixture"]["trusted"]["nonce"] = "ef" * 16
        thaw["state"], thaw["audit"], thaw["ledger"] = (
            first["fake_state"], first["audit"], first["ledger"]
        )
        result = execute(thaw)
        self.assert_no_runtime(result)
        self.assertEqual("simulated", result["fake_effect"]["status"])
        self.assertIs(result["fake_state"]["frozen"], False)
        self.assertEqual(2, len(result["audit"]))

    def test_result_contract_is_sanitized_and_transport_failures_are_ambiguous_or_failed(self):
        base = candidate()
        for outcome, expected in [("failure", "failed"), ("ambiguous", "ambiguous")]:
            payload = copy.deepcopy(base)
            payload["fixture"]["outcome"] = outcome
            output = execute(payload)
            self.assert_no_runtime(output)
            self.assertEqual(expected, output["fake_effect"]["status"])
            self.assertEqual(base["state"], output["fake_state"])
            self.assertEqual([], output["audit"])
            self.assertEqual([], output["ledger"])
            self.assertNotIn(KEY, json.dumps(output))
            self.assertNotIn("cd" * 16, json.dumps(output))
        src = (ROOT / "src/GitHubWorkflowIntentExecutor.php").read_text()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(",
                          "CapabilityGrant::issue(", "'decision' => 'allow'",
                          "'execution' => true"):
            self.assertNotIn(forbidden, src)
        docs = (ROOT / "docs/github-workflow-intent-executor.md").read_text()
        for phrase in ("#763", "#877", "offline_test_only", "DENY"):
            self.assertIn(phrase, docs)


if __name__ == "__main__":
    unittest.main()
