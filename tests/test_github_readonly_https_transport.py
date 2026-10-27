"""PHP-backed contract tests for an unconnected, fake-only GET transport (#930)."""
from __future__ import annotations

import base64
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
use ControlBot\GitHub\GitHubReadOnlyHttpsTransport;
require_once 'src/GitHubReadOnlyHttpsTransport.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if ($raw === false || strlen($raw) > 200000) { throw new RuntimeException('fixture'); }
    $a = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    $calls = [];
    $fake = static function (array $envelope) use (&$calls, $a): array {
        $calls[] = $envelope;
        if (($a['mode'] ?? 'ok') === 'timeout') { throw new RuntimeException('private-token-owner@example.com'); }
        return $a['replies'][count($calls) - 1] ?? ['status' => 429];
    };
    $transport = new GitHubReadOnlyHttpsTransport($fake, $a['enabled']);
    $result = $transport->read($a['request']);
    echo json_encode(['out' => $result, 'calls' => $calls, 'audit' => $transport->audit()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid']);
}
'''


def request():
    return {
        "method": "GET", "host": "api.github.com", "repository": "pl0n3r/ControlBot",
        "resource": "issues", "page_size": 2, "observed_at": 1000,
        "expires_at": 1200, "now": 1050,
    }


def page(n=1, next_page=None, items=None):
    obj = {
        "page": n, "items": items if items is not None else [{"id": 1, "state": "open"}],
        "complete": next_page is None, "next_page": next_page,
    }
    return reply(json.dumps(obj, separators=(",", ":")))


def reply(body, status=200, redirected=False, content_type="application/json"):
    return {"status": status, "content_type": content_type, "redirected": redirected, "body": body}


def run(*, enabled=False, req=None, replies=None, mode="ok"):
    arg = {"enabled": enabled, "request": request() if req is None else req,
           "replies": [page()] if replies is None else replies, "mode": mode}
    encoded = base64.b64encode(json.dumps(arg).encode()).decode()
    p = subprocess.run(["php", "-r", PHP, encoded], cwd=ROOT,
                       capture_output=True, text=True, timeout=20, check=False)
    if p.returncode:
        raise AssertionError(p.stderr.strip() or p.stdout.strip())
    return json.loads(p.stdout)


class GitHubReadOnlyHttpsTransportTests(unittest.TestCase):
    def safe(self, data):
        self.assertIn("out", data)
        self.assertIs(data["out"]["runtime_authorized"], False)
        self.assertIs(data["out"]["production_verified"], False)
        self.assertIs(data["out"]["execute_actions"], False)
        self.assertEqual(data["out"]["authority"], "unchanged")

    def test_off_by_default_and_only_explicit_readonly_invocation(self):
        off = run()
        self.safe(off)
        self.assertEqual(off["out"]["status"], "UNKNOWN")
        self.assertEqual(off["calls"], [])
        on = run(enabled=True)
        self.safe(on)
        self.assertEqual(on["out"]["status"], "CURRENT")
        self.assertEqual(on["out"]["projection"]["kind"], "offline_metadata_only")
        self.assertEqual(len(on["calls"]), 1)
        for invalid in ({**request(), "method": "POST"},
                        {**request(), "method": "PATCH"},
                        {**request(), "method": "DELETE"},
                        {**request(), "auth": "secret"},
                        {**request(), "now": 1200},
                        {**request(), "observed_at": 1300}):
            with self.subTest(invalid=invalid):
                denied = run(enabled=True, req=invalid)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertEqual(denied["calls"], [])

    def test_allowlisted_api_host_and_exact_get_routes(self):
        replies = [page(1, 2), page(2, items=[{"id": 2, "state": "closed"}])]
        got = run(enabled=True, replies=replies)
        self.safe(got)
        self.assertEqual(got["out"]["projection"]["items_count"], 2)
        self.assertEqual([x["url"] for x in got["calls"]], [
            "https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page=1",
            "https://api.github.com/repos/pl0n3r/ControlBot/issues?per_page=2&page=2",
        ])
        for envelope in got["calls"]:
            self.assertEqual(envelope["method"], "GET")
            self.assertEqual(envelope["host"], "api.github.com")
            self.assertEqual(envelope["scheme"], "https")
            self.assertIs(envelope["tls_verify_peer"], True)
            self.assertEqual(envelope["tls_verify_host"], 2)
            self.assertIs(envelope["follow_redirects"], False)
            self.assertEqual(envelope["connect_timeout_seconds"], 2)
            self.assertEqual(envelope["timeout_seconds"], 5)
            self.assertEqual(envelope["mode"], "offline_test_only")
        pulls = run(enabled=True, req={**request(), "resource": "pulls"})
        self.assertEqual(pulls["calls"][0]["path"], "/repos/pl0n3r/ControlBot/pulls")
        for bad in ({"host": "evil.example"}, {"resource": "hooks"},
                    {"repository": "other/repo/extra"},
                    {"repository": "../admin"}, {"repository": "o/%2f"},
                    {"page_size": 100}, {"url": "https://evil.example"}):
            inp = {**request(), **bad}
            with self.subTest(bad=bad):
                denied = run(enabled=True, req=inp)
                self.assertEqual(denied["calls"], [])
                self.assertEqual(denied["out"]["projection"], None)

    def test_tls_no_redirect_timeout_403_429_and_response_limits(self):
        cases = [
            [reply("{}", status=403)],
            [reply("{}", status=429)],
            [reply("{}", redirected=True)],
            [reply("{}", content_type="text/html")],
            [reply("{" + " " * 40000 + "}")],
            [reply("{not-json")],
            [page(1, 2)],
            [page(1, 2), page(2, 3), page(3, 4)],
            [page(items=[{"id": 1, "state": "open", "email": "private@example.com"}])],
            [page(items=[{"id": 0, "state": "open"}])],
        ]
        for replies in cases:
            with self.subTest(replies=str(replies)[:80]):
                got = run(enabled=True, replies=replies)
                self.safe(got)
                self.assertEqual(got["out"]["status"], "UNKNOWN")
                self.assertIsNone(got["out"]["projection"])
        timeout = run(enabled=True, mode="timeout")
        self.assertEqual(timeout["out"]["status"], "UNKNOWN")
        self.assertNotIn("private-token", json.dumps(timeout))
        stale = run(enabled=True, req={**request(), "now": 1400, "expires_at": 1600})
        self.assertEqual(stale["calls"], [])
        self.assertEqual(stale["out"]["status"], "UNKNOWN")

    def test_fake_ci_never_uses_tokens_network_or_mutating_methods(self):
        got = run(enabled=True, replies=[page(items=[{"id": 7, "state": "closed"}])])
        self.safe(got)
        self.assertEqual(got["out"]["status"], "CURRENT")
        self.assertEqual({x["method"] for x in got["calls"]}, {"GET"})
        self.assertNotIn("authorization", json.dumps(got).lower())
        self.assertNotIn("owner@example.com", json.dumps(got))
        audit = json.dumps(got["audit"])
        for secret in ("token", "private", "email", "pl0n3r", "ControlBot"):
            self.assertNotIn(secret, audit)
        source = (ROOT / "src/GitHubReadOnlyHttpsTransport.php").read_text()
        for banned in ("curl_exec(", "file_get_contents(", "stream_socket_client(",
                       "fsockopen(", "GuzzleHttp", "Authorization:"):
            self.assertNotIn(banned, source)
        docs = (ROOT / "docs/github-readonly-https-transport.md").read_text()
        for statement in ("runtime_authorized=false", "production_verified=false",
                          "execute_actions=false", "offline_test_only"):
            self.assertIn(statement, docs)


if __name__ == "__main__":
    unittest.main()
