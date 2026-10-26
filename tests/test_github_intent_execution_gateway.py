"""Real PHP regression tests for the strictly offline GitHub gateway (#907)."""
from __future__ import annotations

import base64
import copy
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROJECT = "controlbot:project/controlbot"
REPO = "pl0n3r/ControlBot"
KEY = bytes(range(32, 64)).hex()  # Public, deterministic TEST fixture key.

PHP = r"""
require_once 'src/GitHubIntentExecutionGateway.php';
use ControlBot\GitHub\GitHubIntentExecutionGateway;
try {
    if ($argc !== 2) throw new InvalidArgumentException('fixture missing');
    $raw = base64_decode($argv[1], true);
    if ($raw === false || strlen($raw) > 65536)
        throw new InvalidArgumentException('fixture oversized');
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['steps']) || !is_array($data['steps']))
        throw new InvalidArgumentException('fixture invalid');
    $calls = 0;
    $mode = $data['sender_mode'] ?? 'ok';
    $gateway = new GitHubIntentExecutionGateway(
        static function (array $request) use (&$calls, $mode): array {
            $calls++;
            if ($mode === 'throw') throw new RuntimeException('fake exception with private payload');
            if ($mode === 'malformed') return ['anything' => 'unexpected'];
            if ($mode === 'false_success') return ['status' => 'executed', 'fixture_digest' => $request['fixture_digest']];
            if ($mode === 'mismatch') return ['status' => 'simulated', 'fixture_digest' => str_repeat('0', 64)];
            if ($mode === 'extra') return ['status' => 'simulated', 'fixture_digest' => $request['fixture_digest'], 'token' => 'DO_NOT_ECHO'];
            return ['status' => 'simulated', 'fixture_digest' => $request['fixture_digest']];
        }
    );
    $receipts = [];
    foreach ($data['steps'] as $step) {
        if (!is_array($step) || !is_array($step['fixture'] ?? null))
            throw new InvalidArgumentException('fixture invalid');
        $f = $step['fixture'];
        $key = hex2bin($f['fixture_key'] ?? '');
        if ($key === false) $key = '';
        $f['fixture_key'] = $key;
        $receipts[] = $gateway->simulate($f, $step['idempotency_key']);
    }
    echo json_encode(['calls' => $calls, 'receipts' => $receipts,
        'audit' => $gateway->audit()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode(['calls' => 0, 'receipts' => [], 'audit' => [],
        'error' => 'invalid_fixture'], JSON_THROW_ON_ERROR);
}
"""


def candidate(action="workflow.dispatch"):
    scope = "project" if action.startswith("project.") else "repository"
    ref = PROJECT if scope == "project" else REPO
    capability = "github.project.write" if scope == "project" else "github.workflow.dispatch"
    return {
        "requirements": {
            "version": 1, "state": "requirements", "type": action,
            "capability": capability, "scope": scope, "scope_ref": ref,
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
        "seen": [], "revoked": [], "now": 1100, "fixture_key": KEY,
        "fake_action": {
            "type": action, "scope_ref": ref,
            "fixture_ref": "controlbot:fixture/acceptance",
        },
    }


def execute(*fixtures, sender_mode="ok", keys=None):
    if keys is None:
        keys = ["fixture-gateway-key-0001"] * len(fixtures)
    payload = {
        "sender_mode": sender_mode,
        "steps": [
            {"fixture": x, "idempotency_key": key}
            for x, key in zip(fixtures, keys, strict=True)
        ],
    }
    encoded = base64.b64encode(json.dumps(payload).encode()).decode("ascii")
    with tempfile.TemporaryDirectory(prefix="cb-github-gateway-") as d:
        script = Path(d) / "fixture.php"
        script.write_text("<?php\n" + PHP, encoding="utf-8")
        p = subprocess.run(
            ["php", str(script), encoded], cwd=ROOT,
            text=True, capture_output=True, timeout=30, check=False,
        )
    if p.returncode:
        raise AssertionError(p.stderr.strip() or p.stdout.strip())
    return json.loads(p.stdout)


class GitHubIntentExecutionGatewayTests(unittest.TestCase):
    def assert_denied_runtime(self, receipt):
        self.assertEqual(receipt["decision"], "deny")
        for key in ("execution", "runtime_authorized", "authorized",
                    "grant_issued", "approval_issued", "approval_verified"):
            self.assertIs(receipt[key], False)

    def test_missing_or_untrusted_grant_denies_without_sender_call(self):
        bad = []
        for section, key, value in (
            ("approval", "approved", False),
            ("approval", "owner_verified", False),
            ("approval", "origin", "client"),
            ("approval", "owner", "attacker"),
            ("trusted", "origin", "client"),
            ("trusted", "mode", "production"),
            ("requirements", "grant_issued", True),
        ):
            row = candidate()
            row[section][key] = value
            bad.append(row)
        missing = candidate()
        missing.pop("approval")
        bad.append(missing)
        r = execute(*bad, keys=[f"fixture-deny-key-{i:04d}" for i in range(len(bad))])
        self.assertEqual(r["calls"], 0)
        self.assertEqual(len(r["receipts"]), len(bad))
        for receipt in r["receipts"]:
            self.assert_denied_runtime(receipt)
            self.assertEqual(receipt["fixture_status"], "denied")
            self.assertIsNone(receipt["fixture_digest"])

    def test_scope_expiration_and_revocation_fail_closed(self):
        bad = []
        for section, key, value in (
            ("requirements", "scope_ref", "pl0n3r/Other"),
            ("approval", "scope_ref", "pl0n3r/Other"),
            ("approval", "revoked", True),
            ("approval", "approved_at", 500),
            ("trusted", "ttl_seconds", 301),
            ("trusted", "ttl_seconds", 0),
            ("trusted", "repository_ref", "pl0n3r/Other"),
            ("fake_action", "scope_ref", "pl0n3r/Other"),
        ):
            row = candidate()
            row[section][key] = value
            bad.append(row)
        for name in ("seen", "revoked"):
            row = candidate()
            row[name] = ["cd" * 16]
            bad.append(row)
        bad.append(candidate("project.freeze") | {"now": 1})
        r = execute(*bad, keys=[f"fixture-revoked-{i:04d}" for i in range(len(bad))])
        self.assertEqual(r["calls"], 0)
        for receipt in r["receipts"]:
            self.assert_denied_runtime(receipt)
            self.assertEqual(receipt["fixture_status"], "denied")

    def test_ambiguous_or_failed_response_never_reports_success(self):
        for mode in ("throw", "malformed", "false_success", "mismatch", "extra"):
            with self.subTest(mode=mode):
                r = execute(candidate(), sender_mode=mode)
                self.assertEqual(r["calls"], 1)
                self.assert_denied_runtime(r["receipts"][0])
                self.assertEqual(r["receipts"][0]["fixture_status"], "ambiguous")
                self.assertEqual(r["receipts"][0]["reason"], "fake_sender_uncertain")
                self.assertNotIn("DO_NOT_ECHO", json.dumps(r))
                self.assertNotIn(KEY, json.dumps(r))

    def test_fake_idempotency_audit_and_no_production_side_effects(self):
        valid = candidate()
        conflict = copy.deepcopy(valid)
        conflict["fake_action"]["fixture_ref"] = "controlbot:fixture/changed"
        revoked = copy.deepcopy(valid)
        revoked["revoked"] = ["cd" * 16]
        r = execute(valid, copy.deepcopy(valid), conflict, revoked)
        self.assertEqual(r["calls"], 1)
        self.assertEqual(r["receipts"][0], r["receipts"][1])
        self.assertEqual(r["receipts"][0]["fixture_status"], "simulated")
        self.assertEqual(r["receipts"][2]["reason"], "idempotency_conflict")
        self.assertEqual(r["receipts"][3]["fixture_status"], "denied")
        self.assertEqual(len(r["audit"]), 3)  # Safe replay is not re-audited.
        for receipt in r["receipts"]:
            self.assert_denied_runtime(receipt)
        self.assertNotIn(KEY, json.dumps(r))
        self.assertNotIn("signature", json.dumps(r))
        source = (ROOT / "src/GitHubIntentExecutionGateway.php").read_text()
        for forbidden in ("curl_exec(", "file_get_contents(", "shell_exec(",
                          "proc_open(", "GitHubHttpTransport", "github.com/repos/"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
