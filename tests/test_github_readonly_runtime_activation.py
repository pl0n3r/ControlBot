"""Build-ahead boundary tests: PHP real, offline fixtures only (ControlBot #1484)."""
from __future__ import annotations

import base64
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubReadOnlyRuntimeActivation;
require_once 'src/GitHubReadOnlyRuntimeActivation.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if ($raw === false || strlen($raw) > 100000) { throw new RuntimeException('fixture'); }
    $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    $calls = [];
    $authCalls = 0;
    $trusted = static function (string $repo, string $resource, int $now) use (&$authCalls, $data): mixed {
        ++$authCalls;
        if (($data['mode'] ?? '') === 'source_error') { throw new RuntimeException('secret-stays-private'); }
        return $data['witness'] ?? null;
    };
    $sender = static function (array $envelope) use (&$calls, $data): array {
        $calls[] = $envelope;
        if (($data['mode'] ?? '') === 'sender_error') { throw new RuntimeException('secret-stays-private'); }
        return $data['replies'][count($calls) - 1] ?? ['status' => 429];
    };
    $activation = new GitHubReadOnlyRuntimeActivation($trusted, $sender, $data['enabled'] ?? false);
    $out = $activation->inspect($data['request'] ?? [], $data['now'] ?? 1050);
    echo json_encode(['out' => $out, 'calls' => $calls, 'auth_calls' => $authCalls, 'audit' => $activation->audit()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid']);
}
'''


def request():
    return {"repository": "pl0n3r/ControlBot", "resource": "issues", "page_size": 2}


def witness():
    return {
        "installation_id": 42, "owner": "pl0n3r", "repository": "pl0n3r/ControlBot",
        "permissions": {"issues": "read"},
        "grant": {"installation_id": 42, "owner": "pl0n3r", "repository": "pl0n3r/ControlBot",
                  "resource": "issues", "approved": True, "revoked": False, "expires_at": 1150},
        "observed_at": 1000, "expires_at": 1200, "revoked": False, "verified": True,
    }


def page(number=1, next_page=None, state="open"):
    body = {"page": number, "items": [{"id": number, "state": state}],
            "complete": next_page is None, "next_page": next_page}
    return reply(json.dumps(body, separators=(",", ":")))


def reply(body, status=200, redirected=False, content_type="application/json"):
    return {"status": status, "content_type": content_type,
            "redirected": redirected, "body": body}


def run(*, enabled=True, r=None, w=None, responses=None, mode="", now=1050):
    fixture = {"enabled": enabled, "request": request() if r is None else r,
               "witness": witness() if w is None else w, "replies": [page()] if responses is None else responses,
               "mode": mode, "now": now}
    b64 = base64.b64encode(json.dumps(fixture).encode()).decode()
    proc = subprocess.run(["php", "-r", PHP, b64], cwd=ROOT, capture_output=True,
                          text=True, timeout=20, check=False)
    if proc.returncode:
        raise AssertionError(proc.stderr or proc.stdout)
    return json.loads(proc.stdout)


class GitHubReadOnlyRuntimeActivationTests(unittest.TestCase):
    def assert_safe(self, result):
        out = result["out"]
        self.assertIs(out["runtime_authorized"], False)
        self.assertIs(out["production_verified"], False)
        self.assertIs(out["execute_actions"], False)
        self.assertEqual(out["authority"], "unchanged")
        self.assertNotIn("secret-stays-private", json.dumps(result))

    def test_requires_verified_installation_owner_scope_and_server_side_grant(self):
        ok = run()
        self.assert_safe(ok)
        self.assertEqual(ok["out"]["status"], "OFFLINE_VALIDATED")
        self.assertEqual(ok["auth_calls"], 1)
        self.assertEqual(len(ok["calls"]), 1)
        self.assertEqual(ok["out"]["projection"]["kind"], "offline_metadata_only")
        for extra in ({"approved": True}, {"token": "should_not_pass"}, {"grant": witness()["grant"]}):
            with self.subTest(extra=extra):
                denied = run(r={**request(), **extra})
                self.assert_safe(denied)
                self.assertEqual(denied["calls"], [])
                self.assertEqual(denied["auth_calls"], 0)
        for bad in (
            {"owner": "other"}, {"repository": "other/repo"}, {"installation_id": 0},
            {"verified": False}, {"permissions": {"issues": "write"}},
            {"permissions": {"issues": "read", "contents": "write"}},
            {"grant": {**witness()["grant"], "owner": "other"}},
            {"grant": {**witness()["grant"], "approved": False}},
        ):
            with self.subTest(bad=bad):
                denied = run(w={**witness(), **bad})
                self.assert_safe(denied)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertEqual(denied["calls"], [])

    def test_revoked_expired_or_mismatched_installation_never_sends_request(self):
        cases = (
            {"revoked": True}, {"expires_at": 1049}, {"observed_at": 1051},
            {"observed_at": 1}, {"expires_at": 1500},
            {"grant": {**witness()["grant"], "revoked": True}},
            {"grant": {**witness()["grant"], "installation_id": 43}},
            {"grant": {**witness()["grant"], "expires_at": 1049}},
            {"grant": {**witness()["grant"], "expires_at": 1300}},
            {"permissions": {}},
        )
        for change in cases:
            with self.subTest(change=change):
                got = run(w={**witness(), **change})
                self.assert_safe(got)
                self.assertEqual(got["out"]["status"], "UNKNOWN")
                self.assertIsNone(got["out"]["projection"])
                self.assertEqual(got["calls"], [])
        source = run(mode="source_error")
        self.assert_safe(source)
        self.assertEqual(source["calls"], [])

    def test_bounded_get_only_and_ambiguous_http_response_fail_closed(self):
        ok = run(responses=[page(1, 2), page(2, state="closed")])
        self.assert_safe(ok)
        self.assertEqual(ok["out"]["status"], "OFFLINE_VALIDATED")
        self.assertEqual(ok["out"]["projection"]["pages"], 2)
        self.assertEqual(ok["out"]["projection"]["items_count"], 2)
        for step in ok["calls"]:
            self.assertEqual(step["method"], "GET")
            self.assertEqual(step["scheme"], "https")
            self.assertEqual(step["host"], "api.github.com")
            self.assertEqual(step["mode"], "offline_test_only")
            self.assertEqual(step["tls_verify_host"], 2)
            self.assertIs(step["tls_verify_peer"], True)
            self.assertIs(step["follow_redirects"], False)
            self.assertLessEqual(step["max_response_bytes"], 32768)
        for responses in ([reply("{}", status=403)], [reply("{}", status=429)],
                          [reply("{}", redirected=True)], [reply("bad-json")],
                          [page(1, 2)]):
            with self.subTest(responses=responses):
                bad = run(responses=responses)
                self.assert_safe(bad)
                self.assertEqual(bad["out"]["status"], "UNKNOWN")
                self.assertIsNone(bad["out"]["projection"])
        timeout = run(mode="sender_error")
        self.assert_safe(timeout)
        self.assertEqual(timeout["out"]["status"], "UNKNOWN")

    def test_disabled_default_no_secrets_no_mutating_routes(self):
        off = run(enabled=False)
        self.assert_safe(off)
        self.assertEqual(off["out"]["status"], "UNKNOWN")
        self.assertEqual(off["auth_calls"], 0)
        self.assertEqual(off["calls"], [])
        for extra in ({"method": "POST"}, {"resource": "hooks"}, {"resource": "actions"},
                      {"repository": "../admin"}, {"page_size": 100}):
            with self.subTest(extra=extra):
                got = run(r={**request(), **extra})
                self.assert_safe(got)
                self.assertEqual(got["auth_calls"], 0)
                self.assertEqual(got["calls"], [])
        src = (ROOT / "src/GitHubReadOnlyRuntimeActivation.php").read_text()
        for banned in ("curl_exec(", "file_get_contents(", "stream_socket_client(",
                       "fsockopen(", "Authorization:", "github_pat_", "ghp_"):
            self.assertNotIn(banned, src)
        self.assertIn("private readonly bool $enabled = false", src)
        self.assertIn("runtime_authorized' => false", src)
        self.assertIn("'execute_actions' => false", src)
        doc = (ROOT / "docs/github-readonly-runtime-activation.md").read_text()
        for statement in ("offline", "GET", "runtime_authorized=false", "production_verified=false",
                          "execute_actions=false", "D-059", "#1338", "#55"):
            self.assertIn(statement, doc)


if __name__ == "__main__":
    unittest.main()
