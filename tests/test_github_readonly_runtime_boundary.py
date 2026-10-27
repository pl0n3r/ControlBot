"""PHP-backed offline regression for the GET-only runtime boundary (#927).
No real network is used: the transport is a caller-supplied synthetic Closure.
"""
from __future__ import annotations

import base64
import json
import subprocess
import unittest
from pathlib import Path

from tests.test_github_readonly_installation_source import (
    SECRET, SHA, REPOSITORY, fixture, request, page,
)

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
use ControlBot\GitHub\GitHubReadOnlyRuntimeBoundary;
require_once 'src/GitHubReadOnlyRuntimeBoundary.php';
try {
    $raw = base64_decode($argv[1] ?? '', true);
    if ($raw === false || strlen($raw) > 65536) {
        throw new InvalidArgumentException('oversize');
    }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $calls = [];
    $fake = static function (array $query) use (&$calls, $data): array {
        $calls[] = $query;
        if (($data['mode'] ?? 'ok') === 'timeout') {
            throw new RuntimeException('private-token-secret@example.com');
        }
        return $data['pages'][$query['page'] - 1] ?? ['status' => 429];
    };
    $boundary = new GitHubReadOnlyRuntimeBoundary($fake, $data['key']);
    $out = $boundary->inspect($data['fixture'], $data['grant'],
        $data['request'], $data['now']);
    echo json_encode(['out' => $out, 'calls' => $calls, 'audit' => $boundary->audit()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode(['error' => 'offline_fixture_invalid']);
}
"""


def grant():
    return {
        "origin": "server_test_grant", "test_only": True, "owner_verified": True,
        "installation_id": 142, "owner": "pl0n3r", "repository": REPOSITORY,
        "sha": SHA, "issued_at": 1000, "expires_at": 1150,
    }


def run(*, f=None, g=None, req=None, now=1050, pages=None, mode="ok"):
    payload = {
        "fixture": fixture() if f is None else f,
        "grant": g,
        "request": request() if req is None else req,
        "now": now,
        "pages": [page()] if pages is None else pages,
        "mode": mode,
        "key": SECRET,
    }
    proc = subprocess.run(
        ["php", "-r", PHP, base64.b64encode(json.dumps(payload).encode()).decode()],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
    )
    if proc.returncode:
        raise AssertionError(proc.stderr.strip() or proc.stdout.strip())
    return json.loads(proc.stdout)


class GitHubReadOnlyRuntimeBoundaryTests(unittest.TestCase):
    def assert_safe(self, out):
        self.assertIn("out", out)
        for name in ("runtime_authorized", "production_verified", "execute_actions"):
            self.assertIs(out["out"][name], False)
        self.assertEqual(out["out"]["authority"], "unchanged")

    def test_denied_by_default_without_explicit_owner_grant(self):
        for g in [None, {}, {**grant(), "owner_verified": False},
                  {**grant(), "test_only": False},
                  {**grant(), "origin": "browser_payload"},
                  {**grant(), "issued_at": 2000},
                  {**grant(), "expires_at": 1050}]:
            with self.subTest(grant=g):
                result = run(g=g)
                self.assert_safe(result)
                self.assertEqual(result["out"]["status"], "UNKNOWN")
                self.assertIsNone(result["out"]["fixture_projection"])
                self.assertEqual(result["calls"], [])

    def test_exact_installation_repository_sha_and_get_route(self):
        result = run(g=grant(), pages=[
            page(1, next_page=2, items=[{"id": 3, "state": "open"}]),
            page(2, items=[{"id": 5, "state": "closed"}]),
        ])
        self.assert_safe(result)
        self.assertEqual(result["out"]["status"], "CURRENT")
        self.assertEqual(result["out"]["reason"], "fixture_only")
        self.assertEqual(result["out"]["fixture_projection"], {
            "repository": REPOSITORY, "sha": SHA, "resource": "issues",
            "items_count": 2, "pages": 2, "kind": "fixture_projection",
        })
        self.assertEqual([x["page"] for x in result["calls"]], [1, 2])
        self.assertEqual({x["method"] for x in result["calls"]}, {"GET"})
        self.assertEqual({x["mode"] for x in result["calls"]}, {"offline_test_only"})
        for name, changed in [
            ("owner", "outsider"), ("installation_id", 143),
            ("repository", "other/repo"), ("sha", "b" * 40),
        ]:
            with self.subTest(field=name):
                g = {**grant(), name: changed}
                denied = run(g=g)
                self.assertEqual(denied["out"]["status"], "UNKNOWN")
                self.assertEqual(denied["calls"], [])
        for modified_request in [
            {**request(), "resource": "hooks"},
            {**request(), "repository": "other/repo"},
            {**request(), "sha": "b" * 40},
            {**request(), "page_size": 100},
        ]:
            denied = run(g=grant(), req=modified_request)
            self.assertEqual(denied["out"]["status"], "UNKNOWN")
            self.assertIsNone(denied["out"]["fixture_projection"])

    def test_403_429_timeout_partial_truncation_and_stale_unknown(self):
        responses = [
            [{**page(), "status": 403}],
            [{**page(), "status": 429}],
            [{**page(), "truncated": True}],
            [{**page(), "sha": "b" * 40}],
            [{**page(), "complete": False, "next_page": None}],
            [page(1, next_page=2), {**page(2), "items": ["invalid"]}],
            [page(1, next_page=2), page(2, next_page=3), page(3, next_page=4)],
        ]
        for pages in responses:
            with self.subTest(pages=pages):
                got = run(g=grant(), pages=pages)
                self.assert_safe(got)
                self.assertEqual(got["out"]["status"], "UNKNOWN")
                self.assertIsNone(got["out"]["fixture_projection"])
        for now in (999, 1150, 9999):
            with self.subTest(now=now):
                got = run(g=grant(), now=now)
                self.assertEqual(got["out"]["status"], "UNKNOWN")
                self.assertIsNone(got["out"]["fixture_projection"])
        got = run(g=grant(), mode="timeout")
        self.assertEqual(got["out"]["status"], "UNKNOWN")
        self.assertNotIn("private-token", json.dumps(got))

    def test_no_secrets_pii_network_or_mutations_during_offline_tests(self):
        got = run(g=grant(), pages=[{**page(), "items": [
            {"id": 1, "state": "open", "email": "private@example.com"}]}])
        self.assert_safe(got)
        self.assertEqual(got["out"]["status"], "UNKNOWN")
        self.assertEqual({q["method"] for q in got["calls"]}, {"GET"})
        self.assertTrue(all(q["route"] == "/repos/pl0n3r/ControlBot/issues"
                            for q in got["calls"]))
        audit = json.dumps(got["audit"])
        for word in ("private", "token", "email", "installation_id", SHA, REPOSITORY):
            self.assertNotIn(word, audit)
        source = (ROOT / "src/GitHubReadOnlyRuntimeBoundary.php").read_text()
        for banned in ("curl_exec(", "file_get_contents(", "stream_socket_client(",
                       "new GuzzleHttp", "https://api.github.com"):
            self.assertNotIn(banned, source)
        docs = (ROOT / "docs/github-readonly-runtime-boundary.md").read_text()
        self.assertIn("runtime_authorized=false", docs)
        self.assertIn("production_verified=false", docs)


if __name__ == "__main__":
    unittest.main()
