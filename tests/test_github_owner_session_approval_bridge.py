"""Real PHP acceptance tests for the owner-session bridge, offline only (#912)."""
from __future__ import annotations

import base64
import copy
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
require_once 'src/GitHubOwnerSessionApprovalBridge.php';
use ControlBot\GitHub\GitHubOwnerSessionApprovalBridge;
try {
    if ($argc !== 2) throw new RuntimeException('invalid fixture');
    $raw = base64_decode($argv[1], true);
    if ($raw === false || strlen($raw) > 65536) throw new RuntimeException('oversized fixture');
    $input = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_array($input['steps'] ?? null))
        throw new RuntimeException('invalid fixture');
    $calls = 0;
    $sourceCalls = 0;
    $mode = $input['sender_mode'] ?? 'ok';
    $records = $input['records'] ?? [];
    $bridge = new GitHubOwnerSessionApprovalBridge(
        static function (string $sessionRef) use (&$sourceCalls, &$records): ?array {
            $sourceCalls++;
            return $records[$sessionRef] ?? null;
        },
        static function (array $fake) use (&$calls, $mode): array {
            $calls++;
            if ($mode === 'throw') throw new RuntimeException('PRIVATE_TOKEN_DO_NOT_PRINT');
            if ($mode === 'bad') return ['status' => 'executed'];
            if ($mode === 'extra') return ['status' => 'simulated', 'fixture_digest' => $fake['fixture_digest'], 'secret' => 'LEAK'];
            return ['status' => 'simulated', 'fixture_digest' => $fake['fixture_digest']];
        },
    );
    $receipts = [];
    foreach ($input['steps'] as $step) {
        if (!is_array($step)) throw new RuntimeException('invalid step');
        if (isset($step['revoke']) && is_string($step['revoke']) && isset($records[$step['revoke']]))
            $records[$step['revoke']]['revoked'] = true;
        $receipts[] = $bridge->simulate($step['request'] ?? [], $step['now'] ?? -1);
    }
    echo json_encode(['calls' => $calls, 'source_calls' => $sourceCalls,
        'receipts' => $receipts, 'audit' => $bridge->audit()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'invalid_fixture'], JSON_THROW_ON_ERROR);
}
"""


def candidate():
    request = {
        "session_ref": "fixture-session/owner0001", "action": "issue.comment",
        "scope_ref": "pl0n3r/ControlBot", "nonce": "ab" * 16,
    }
    record = {
        "source": "trusted_fixture", "origin": "server", "owner": "pl0n3r",
        "owner_verified": True, "server_verified": True,
        "session_ref": request["session_ref"], "bound_action": request["action"],
        "bound_scope_ref": request["scope_ref"], "bound_nonce": request["nonce"],
        "reauthenticated_at": 1040, "issued_at": 1020, "expires_at": 1120,
        "revoked": False,
    }
    return request, record


def execute(steps, records=None, sender_mode="ok"):
    if records is None:
        req, record = candidate()
        records = {req["session_ref"]: record}
    data = {"steps": steps, "records": records, "sender_mode": sender_mode}
    encoded = base64.b64encode(json.dumps(data).encode()).decode("ascii")
    with tempfile.TemporaryDirectory(prefix="controlbot-bridge-") as dirname:
        script = Path(dirname) / "fixture.php"
        script.write_text("<?php\n" + PHP, encoding="utf-8")
        process = subprocess.run(
            ["php", str(script), encoded], cwd=ROOT,
            capture_output=True, text=True, timeout=30, check=False,
        )
    if process.returncode:
        raise AssertionError(process.stderr.strip() or process.stdout.strip())
    return json.loads(process.stdout)


class GitHubOwnerSessionApprovalBridgeTests(unittest.TestCase):
    def assert_deny_runtime(self, receipt):
        self.assertEqual(receipt["decision"], "deny")
        for key in ("execution", "runtime_authorized", "authorized", "grant_issued",
                    "approval_issued", "approval_verified"):
            self.assertIs(receipt[key], False)

    def test_denies_missing_or_unverified_owner_session(self):
        request, good = candidate()
        variants = [None]
        for key, bad in (
            ("owner", "attacker"), ("owner_verified", False),
            ("server_verified", False), ("origin", "client"),
            ("source", "request"), ("session_ref", "fixture-session/another111"),
        ):
            rec = copy.deepcopy(good)
            rec[key] = bad
            variants.append(rec)
        for rec in variants:
            with self.subTest(record=rec):
                records = {} if rec is None else {request["session_ref"]: rec}
                result = execute([{"request": request, "now": 1060}], records)
                self.assertEqual(result["calls"], 0)
                self.assert_deny_runtime(result["receipts"][0])
                self.assertEqual(result["receipts"][0]["fixture_status"], "denied")
        injected = request | {"server_verified": True}
        result = execute([{"request": injected, "now": 1060}])
        self.assertEqual(result["calls"], 0)
        self.assert_deny_runtime(result["receipts"][0])

    def test_reauth_age_action_scope_and_nonce_are_server_bound(self):
        request, good = candidate()
        for section, key, bad in (
            ("record", "reauthenticated_at", 900),
            ("record", "reauthenticated_at", 1099),
            ("record", "issued_at", 900),
            ("record", "expires_at", 1040),
            ("record", "expires_at", 1200),
            ("record", "bound_action", "pr.merge"),
            ("record", "bound_scope_ref", "pl0n3r/Factory"),
            ("record", "bound_nonce", "ff" * 16),
            ("request", "action", "pr.merge"),
            ("request", "scope_ref", "pl0n3r/Factory"),
            ("request", "nonce", "ff" * 16),
        ):
            with self.subTest(key=key, bad=bad):
                rec, req = copy.deepcopy(good), copy.deepcopy(request)
                if section == "record":
                    rec[key] = bad
                else:
                    req[key] = bad
                result = execute([{"request": req, "now": 1060}], {request["session_ref"]: rec})
                self.assertEqual(result["calls"], 0)
                self.assertEqual(result["receipts"][0]["fixture_status"], "denied")
        result = execute([{"request": request, "now": 1060}])
        self.assertEqual(result["calls"], 1)
        self.assertEqual(result["receipts"][0]["fixture_status"], "simulated")
        self.assert_deny_runtime(result["receipts"][0])

    def test_revocation_or_replay_never_reaches_fake_sender(self):
        request, record = candidate()
        revoked = copy.deepcopy(record)
        revoked["revoked"] = True
        result = execute([{"request": request, "now": 1060}], {request["session_ref"]: revoked})
        self.assertEqual(result["calls"], 0)
        self.assertEqual(result["receipts"][0]["fixture_status"], "denied")
        result = execute([
            {"request": request, "now": 1060},
            {"request": request, "now": 1061},
            {"request": request, "now": 1062, "revoke": request["session_ref"]},
        ], {request["session_ref"]: record})
        self.assertEqual(result["calls"], 1)
        self.assertEqual(result["receipts"][1]["reason"], "replay")
        self.assertEqual(result["receipts"][2]["reason"], "replay")
        result = execute([
            {"request": request, "now": 1060},
            {"request": request, "now": 1061},
        ], {request["session_ref"]: record}, sender_mode="throw")
        self.assertEqual(result["calls"], 1)
        self.assertEqual(result["receipts"][0]["fixture_status"], "ambiguous")
        self.assertEqual(result["receipts"][1]["reason"], "replay")

    def test_bounded_audit_and_no_github_network_or_runtime_allow(self):
        req, record = candidate()
        steps = []
        for i in range(75):
            changed = copy.deepcopy(req)
            changed["nonce"] = f"{i:032x}"
            steps.append({"request": changed, "now": 1060})
        result = execute(steps, {req["session_ref"]: record})
        self.assertEqual(result["calls"], 0)
        self.assertEqual(len(result["receipts"]), 75)
        self.assertEqual(len(result["audit"]), 64)
        for receipt in result["receipts"]:
            self.assert_deny_runtime(receipt)
        for mode in ("ok", "throw", "bad", "extra"):
            with self.subTest(mode=mode):
                result = execute([{"request": req, "now": 1060}], sender_mode=mode)
                self.assertEqual(result["calls"], 1)
                self.assert_deny_runtime(result["receipts"][0])
                self.assertNotIn("PRIVATE_TOKEN_DO_NOT_PRINT", json.dumps(result))
                self.assertNotIn("LEAK", json.dumps(result))
                self.assertNotIn(req["session_ref"], json.dumps(result))
                self.assertNotIn(req["nonce"], json.dumps(result))
        source = (ROOT / "src/GitHubOwnerSessionApprovalBridge.php").read_text()
        for forbidden in ("curl_exec(", "file_get_contents(", "shell_exec(",
                          "proc_open(", "GitHubHttpTransport", "github.com/repos/"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
