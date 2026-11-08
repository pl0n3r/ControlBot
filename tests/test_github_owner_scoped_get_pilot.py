"""Contract tests for dormant, owner-scoped real GitHub GET pilot (#1501).

PHP production class is exercised with a fake sender only: no account, token,
network, Hostinger or operational evidence is used by this suite.
"""
from __future__ import annotations

import base64
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubOwnerScopedGetPilot;
require_once 'src/GitHubOwnerScopedGetPilot.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if ($raw === false || strlen($raw) > 160000) { throw new RuntimeException('fixture'); }
    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $calls = []; $sourceCalls = 0; $tokenCalls = 0;
    $source = static function ($repo, $resource, $now) use ($data, &$sourceCalls): mixed {
        ++$sourceCalls;
        if (($data['mode'] ?? '') === 'source_error') {
            throw new RuntimeException('secret-source@example.com');
        }
        return $data['witness'] ?? null;
    };
    $token = static function ($id) use ($data, &$tokenCalls): mixed {
        ++$tokenCalls;
        if (($data['mode'] ?? '') === 'token_error') {
            throw new RuntimeException('secret-token@example.com');
        }
        return $data['token'] ?? 'local-test-token-not-a-credential';
    };
    $fake = static function ($url, $token, $guards) use ($data, &$calls): mixed {
        $calls[] = ['url' => $url, 'guards' => $guards];
        if (($data['mode'] ?? '') === 'sender_error') {
            throw new RuntimeException('secret-sender@example.com');
        }
        return $data['replies'][count($calls) - 1] ?? ['status' => 429];
    };
    $pilot = new GitHubOwnerScopedGetPilot(
        $source, $token, $fake, $data['enabled'] ?? false
    );
    $out = $pilot->read($data['request'] ?? [], $data['now'] ?? 1050);
    echo json_encode([
        'out' => $out, 'calls' => $calls, 'source_calls' => $sourceCalls,
        'token_calls' => $tokenCalls, 'audit' => $pilot->audit(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid']);
}
'''


def request():
    return {"repository": "pl0n3r/ControlBot", "resource": "issues", "page_size": 2}


def witness():
    return {
        "origin": "trusted_installation_source", "installation_id": 42,
        "owner": "pl0n3r", "repository": "pl0n3r/ControlBot",
        "permissions": {"metadata": "read", "issues": "read"},
        "grant": {
            "origin": "owner_approved_grant", "installation_id": 42,
            "owner": "pl0n3r", "repository": "pl0n3r/ControlBot",
            "resource": "issues", "approved": True, "revoked": False,
            "issued_at": 1000, "expires_at": 1150,
        },
        "observed_at": 1000, "expires_at": 1200,
        "verified": True, "revoked": False,
    }


def reply(items=None, status=200, content_type="application/json", redirected=False,
          tls_verified=True):
    if items is None:
        items = [{"number": 1, "state": "open"}]
    return {"status": status, "content_type": content_type,
            "redirected": redirected, "tls_verified": tls_verified,
            "body": json.dumps(items)}


def run(*, enabled=True, req=None, w=None, replies=None, mode="", token=None, now=1050):
    payload = {
        "enabled": enabled, "request": request() if req is None else req,
        "witness": witness() if w is None else w,
        "replies": [reply()] if replies is None else replies,
        "mode": mode, "now": now,
    }
    if token is not None:
        payload["token"] = token
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    process = subprocess.run(["php", "-r", PHP, encoded], cwd=ROOT,
                             capture_output=True, text=True, check=False, timeout=25)
    if process.returncode:
        raise AssertionError(process.stderr.strip() or process.stdout.strip())
    return json.loads(process.stdout)


class GitHubOwnerScopedGetPilotTests(unittest.TestCase):
    def assert_safe(self, result):
        self.assertIn("out", result)
        self.assertIs(result["out"]["runtime_authorized"], False)
        self.assertIs(result["out"]["production_verified"], False)
        self.assertIs(result["out"]["execute_actions"], False)
        self.assertEqual(result["out"]["authority"], "unchanged")
        self.assertNotIn("secret-", json.dumps(result))
        self.assertNotIn("local-test-token", json.dumps(result))

    def test_requires_server_verified_owner_installation_and_exact_repo_scope(self):
        good = run()
        self.assert_safe(good)
        self.assertEqual(good["out"]["status"], "OFFLINE_VALIDATED")
        self.assertEqual(good["token_calls"], 1)
        self.assertEqual(len(good["calls"]), 1)
        self.assertEqual(good["out"]["projection"]["items"],
                         [{"number": 1, "state": "open"}])
        for change in (
            {"origin": "caller_verified"}, {"installation_id": 0},
            {"verified": False}, {"owner": "other"}, {"repository": "other/repo"},
            {"permissions": {"issues": "write", "metadata": "read"}},
            {"permissions": {"issues": "read", "metadata": "read", "contents": "write"}},
            {"grant": {**witness()["grant"], "owner": "other"}},
            {"grant": {**witness()["grant"], "approved": False}},
            {"grant": {**witness()["grant"], "resource": "pulls"}},
        ):
            with self.subTest(change=change):
                denied = run(w={**witness(), **change})
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertEqual(denied["token_calls"], 0)
                self.assertEqual(denied["calls"], [])
        for additional in ({"token": "browser-token"}, {"installation_id": 42},
                           {"url": "https://evil.example/"}):
            with self.subTest(additional=additional):
                denied = run(req={**request(), **additional})
                self.assertEqual(denied["source_calls"], 0)
                self.assertEqual(denied["calls"], [])

    def test_revocation_expiry_and_token_failure_fail_closed(self):
        for change in (
            {"revoked": True}, {"expires_at": 1050}, {"observed_at": 1051},
            {"observed_at": 500}, {"expires_at": 1500},
            {"grant": {**witness()["grant"], "revoked": True}},
            {"grant": {**witness()["grant"], "expires_at": 1050}},
            {"grant": {**witness()["grant"], "expires_at": 1300}},
            {"grant": {**witness()["grant"], "issued_at": 1020, "expires_at": 1350}},
        ):
            with self.subTest(change=change):
                denied = run(w={**witness(), **change})
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertEqual(denied["token_calls"], 0)
                self.assertEqual(denied["calls"], [])
        for case in (
            run(mode="source_error"), run(mode="token_error"), run(token="tiny"),
            run(token="bad\nheader"), run(w=None if False else {}),
        ):
            self.assert_safe(case)
            self.assertEqual(case["out"]["status"], "UNKNOWN")
            self.assertEqual(case["calls"], [])

    def test_https_get_bounded_pages_and_ambiguous_responses(self):
        two_pages = run(replies=[
            reply([{"number": 1, "state": "open"}, {"number": 2, "state": "closed"}]),
            reply([{"number": 3, "state": "open"}], content_type="application/vnd.github+json; charset=utf-8"),
        ])
        self.assert_safe(two_pages)
        self.assertEqual(two_pages["out"]["status"], "OFFLINE_VALIDATED")
        self.assertEqual([x["number"] for x in two_pages["out"]["projection"]["items"]],
                         [1, 2, 3])
        self.assertEqual([x["url"] for x in two_pages["calls"]], [
            "https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page=1",
            "https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page=2",
        ])
        for call in two_pages["calls"]:
            guards = call["guards"]
            self.assertEqual(guards["method"], "GET")
            self.assertIs(guards["tls_verify_peer"], True)
            self.assertEqual(guards["tls_verify_host"], 2)
            self.assertIs(guards["follow_redirects"], False)
            self.assertLessEqual(guards["timeout_seconds"], 5)
            self.assertLessEqual(guards["max_response_bytes"], 32768)
        for failures in (
            [reply(status=403)], [reply(status=429)],
            [reply(redirected=True)], [reply(tls_verified=False)],
            [reply(content_type="text/html")],
            [reply([{"number": 0, "state": "open"}])],
            [reply([{"number": 1, "state": "open", "email": "private@example.com"}] * 3)],
            [reply([{"number": 1, "state": "open"}] * 2)],
            [reply([{"number": 1, "state": "open"}, {"number": 2, "state": "open"}])] * 3,
        ):
            with self.subTest(failure=str(failures)[:70]):
                result = run(replies=failures)
                self.assert_safe(result)
                self.assertEqual(result["out"]["status"], "UNKNOWN")
                self.assertIsNone(result["out"]["projection"])
        timed_out = run(mode="sender_error")
        self.assert_safe(timed_out)
        self.assertEqual(timed_out["out"]["status"], "UNKNOWN")

    def test_disabled_default_no_web_wiring_or_writes(self):
        off = run(enabled=False)
        self.assert_safe(off)
        self.assertEqual(off["out"]["status"], "UNKNOWN")
        self.assertEqual(off["source_calls"], 0)
        self.assertEqual(off["token_calls"], 0)
        self.assertEqual(off["calls"], [])
        for bad in (
            {"resource": "hooks"}, {"resource": "actions"},
            {"repository": "../admin"}, {"repository": "a/%2f"},
            {"repository": "a/b/extra"}, {"page_size": 100},
            {"method": "POST"}, {"url": "https://evil.example"},
        ):
            with self.subTest(bad=bad):
                result = run(req={**request(), **bad})
                self.assert_safe(result)
                self.assertEqual(result["source_calls"], 0)
                self.assertEqual(result["calls"], [])
        source = (ROOT / "src/GitHubOwnerScopedGetPilot.php").read_text()
        self.assertIn("private readonly bool $enabled = false", source)
        self.assertIn("CURLOPT_SSL_VERIFYPEER => true", source)
        self.assertIn("CURLOPT_FOLLOWLOCATION => false", source)
        self.assertIn("CURLOPT_HTTPGET => true", source)
        self.assertNotIn("CURLOPT_POST => true", source)
        self.assertNotIn("CURLOPT_CUSTOMREQUEST", source)
        entrypoint = (ROOT / "src/ControlBotWebEntrypoint.php").read_text()
        self.assertNotIn("GitHubOwnerScopedGetPilot", entrypoint)
        document = (ROOT / "docs/github-owner-scoped-get-pilot.md").read_text()
        for term in ("#55", "#1493", "#1501", "OFF", "GET", "D-043",
                     "runtime_authorized=false", "production_verified=false",
                     "execute_actions=false"):
            self.assertIn(term, document)


if __name__ == "__main__":
    unittest.main()
