"""PHP-backed regressions for the TEST-ONLY GitHub installation source (#924).
All network requests are simulated by an injected PHP Closure, never an HTTP client.
"""
from __future__ import annotations
import base64
import copy
import hashlib
import hmac
import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = "pl0n3r/ControlBot"
SHA = "a" * 40
SECRET = "controlbot-offline-only-fixture-key-12345678901234567890"
PHP = r"""
use ControlBot\GitHub\GitHubReadOnlyInstallationSource;
require_once 'src/GitHubReadOnlyInstallationSource.php';
try {
    $encoded = base64_decode($argv[1] ?? '', true);
    if ($encoded === false || strlen($encoded) > 65536) {
        throw new InvalidArgumentException('fixture_invalid');
    }
    $input = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
    $calls = [];
    $fake = static function (array $request) use (&$calls, $input): array {
        $calls[] = $request;
        if (($input['fake_mode'] ?? 'ok') === 'timeout') {
            throw new RuntimeException('private-token-owner@example.com');
        }
        return $input['responses'][$request['page'] - 1] ?? ['status' => 429];
    };
    $source = new GitHubReadOnlyInstallationSource($fake, $input['key']);
    $result = $source->project($input['fixture'], $input['request'], $input['now']);
    echo json_encode(['result' => $result, 'calls' => $calls, 'audit' => $source->audit()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode(['error' => 'fixture_invalid'], JSON_THROW_ON_ERROR);
}
"""
def fixture():
    data = {
        "origin": "server_fixture",
        "test_only": True,
        "owner_verified": True,
        "installation_id": 142,
        "owner": "pl0n3r",
        "repository": REPOSITORY,
        "sha": SHA,
        "scopes": ["contents:read", "metadata:read"],
        "observed_at": 1000,
        "expires_at": 1150,
    }
    return seal(data)
def seal(data):
    data = copy.deepcopy(data)
    data.pop("proof", None)
    encoded = json.dumps(data, separators=(",", ":"), ensure_ascii=False).encode()
    data["proof"] = hmac.new(SECRET.encode(), encoded, hashlib.sha256).hexdigest()
    return data
def request():
    return {"repository": REPOSITORY, "sha": SHA, "resource": "issues", "page_size": 2}
def page(number=1, *, next_page=None, items=None):
    if items is None:
        items = [{"id": 1, "state": "open"}]
    return {
        "status": 200,
        "repository": REPOSITORY,
        "sha": SHA,
        "page": number,
        "items": items,
        "complete": next_page is None,
        "truncated": False,
        "next_page": next_page,
    }
def execute(*, f=None, r=None, now=1050, responses=None, fake_mode="ok", key=SECRET):
    payload = {
        "fixture": fixture() if f is None else f,
        "request": request() if r is None else r,
        "now": now,
        "responses": [page()] if responses is None else responses,
        "fake_mode": fake_mode,
        "key": key,
    }
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    proc = subprocess.run(
        ["php", "-r", PHP, encoded],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=20,
    )
    if proc.returncode:
        raise AssertionError(proc.stderr.strip() or proc.stdout.strip())
    return json.loads(proc.stdout)
class GitHubReadOnlyInstallationSourceTests(unittest.TestCase):
    def assert_offline(self, result):
        self.assertFalse(result["runtime_authorized"])
        self.assertFalse(result["production_verified"])
        self.assertFalse(result["execute_actions"])
        self.assertEqual(result["authority"], "unchanged")
    def test_exact_repository_and_sha_read_only_projection(self):
        responses = [
            page(1, next_page=2, items=[{"id": 2, "state": "open"}]),
            page(2, items=[{"id": 3, "state": "closed"}]),
        ]
        out = execute(responses=responses)
        result = out["result"]
        self.assert_offline(result)
        self.assertEqual(result["status"], "CURRENT")
        self.assertEqual(result["reason"], "fixture_only")
        self.assertEqual(result["fixture_projection"], {
            "repository": REPOSITORY, "sha": SHA, "resource": "issues",
            "items_count": 2, "pages": 2, "kind": "fixture_projection",
        })
        self.assertEqual(len(out["calls"]), 2)
        self.assertEqual([q["page"] for q in out["calls"]], [1, 2])
        self.assertEqual({q["method"] for q in out["calls"]}, {"GET"})
        self.assertEqual(out["audit"], [
            {"status": "CURRENT", "reason": "fixture_only", "pages": 2}
        ])
    def test_missing_owner_installation_or_scope_denies_network(self):
        variants = []
        for field, value in (
            ("owner_verified", False),
            ("installation_id", 0),
            ("origin", "browser"),
            ("scopes", ["contents:write", "metadata:read"]),
            ("owner", "attacker"),
            ("test_only", False),
            ("sha", "z" * 40),
        ):
            copy_fixture = fixture()
            copy_fixture[field] = value
            variants.append(seal(copy_fixture))
        variants.append({**fixture(), "proof": "0" * 64})
        for f in variants:
            with self.subTest(fixture=f):
                out = execute(f=f)
                self.assert_offline(out["result"])
                self.assertEqual(out["result"]["status"], "UNKNOWN")
                self.assertIsNone(out["result"]["fixture_projection"])
                self.assertEqual(out["calls"], [])
        for invalid in (
            {**request(), "sha": "b" * 40},
            {**request(), "repository": "other/project"},
            {**request(), "resource": "hooks"},
            {**request(), "page_size": 100},
            {**request(), "token": "private-synthetic-token"},
        ):
            with self.subTest(request=invalid):
                out = execute(r=invalid)
                self.assertEqual(out["result"]["status"], "UNKNOWN")
                self.assertEqual(out["calls"], [])
    def test_403_429_timeout_partial_and_stale_fail_closed(self):
        for code in (403, 429):
            with self.subTest(status=code):
                result = execute(responses=[{**page(), "status": code}])
                self.assert_offline(result["result"])
                self.assertEqual(result["result"]["status"], "UNKNOWN")
                self.assertIsNone(result["result"]["fixture_projection"])
                self.assertEqual(len(result["calls"]), 1)
        for modified in (
            {**page(), "truncated": True},
            {**page(), "complete": False, "next_page": None},
            {**page(), "sha": "b" * 40},
            {**page(), "repository": "attacker/other"},
            {**page(), "items": [{"id": 1, "state": "open", "email": "owner@example.com"}]},
            {**page(), "next_page": 8, "complete": False},
        ):
            with self.subTest(page=modified):
                out = execute(responses=[modified])
                self.assert_offline(out["result"])
                self.assertEqual(out["result"]["status"], "UNKNOWN")
                self.assertIsNone(out["result"]["fixture_projection"])
        for now in (999, 1150, 9000):
            with self.subTest(now=now):
                out = execute(now=now)
                self.assertEqual(out["result"]["status"], "UNKNOWN")
                self.assertEqual(out["calls"], [])
        out = execute(fake_mode="timeout")
        self.assertEqual(out["result"]["status"], "UNKNOWN")
        self.assertEqual(out["result"]["reason"], "fixture_transport_error")
        self.assertNotIn("private-token", json.dumps(out))
        out = execute(responses=[page(1, next_page=2), page(2, next_page=3), page(3, next_page=4)])
        self.assertEqual(out["result"]["status"], "UNKNOWN")
        self.assertEqual(out["result"]["reason"], "page_limit")
        self.assertEqual(len(out["calls"]), 3)
    def test_no_writes_secrets_or_pii_in_audit(self):
        result = execute(responses=[{**page(), "items": [
            {"id": 1, "state": "open", "token": "private-sensitive"}
        ]}])
        self.assertEqual(result["result"]["status"], "UNKNOWN")
        self.assertEqual({x["method"] for x in result["calls"]}, {"GET"})
        self.assertTrue(all(
            x["route"] == "/repos/pl0n3r/ControlBot/issues"
            and x["mode"] == "offline_test_only"
            and set(x) == {"method", "route", "page", "per_page", "mode"}
            for x in result["calls"]
        ))
        audit = json.dumps(result["audit"])
        for sensitive in ("owner@", "private", "token", "email", "installation_id", SHA):
            self.assertNotIn(sensitive, audit)
        source = (ROOT / "src/GitHubReadOnlyInstallationSource.php").read_text()
        for disallowed in ("curl_exec(", "file_get_contents(", "stream_socket_client(",
                           "new GuzzleHttp", "new Client(", "https://api.github.com"):
            self.assertNotIn(disallowed, source)
        doc = (ROOT / "docs/github-readonly-installation-source.md").read_text()
        self.assertIn("runtime_authorized=false", doc)
        self.assertIn("production_verified=false", doc)
if __name__ == "__main__":
    unittest.main()
