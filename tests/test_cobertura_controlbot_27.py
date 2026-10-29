"""#1210: behavioral coverage for 20 Github governance clauses, wholly offline.

The PHP production domains are exercised by existing synthetic PHP scenarios,
not copied into Python models. Five normative/documentary clauses are C:
CB-0432, CB-0435, CB-0436, CB-0437 and CB-0442. Neither the fake transports
nor a green CI result authorize real GitHub mutations or a production session.
"""
from __future__ import annotations

import copy
import json
from pathlib import Path
import runpy
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
EXECUTABLE = frozenset({
    "CB-0433", "CB-0434", "CB-0438", "CB-0439", "CB-0440",
    "CB-0441", "CB-0443", "CB-0444", "CB-0445", "CB-0446",
    "CB-0447", "CB-0448", "CB-0449", "CB-0450", "CB-0451",
})
CLASSIFIED_C = {
    "CB-0432": "Owner approval history and legal scope are governance, not a runtime grant",
    "CB-0435": "Command line for running tests is documentary guidance",
    "CB-0436": "Squash rollback and gates are deployment policy, not executable behavior",
    "CB-0437": "An authentic /decidir A journal is a human decision, not a fake PHP grant",
    "CB-0442": "Acceptance checklist and revert instructions are documentation",
}
FIXTURES = {
    "owner": "tests/test_github_owner_session_approval_bridge.py",
    "intent": "tests/test_github_pr_intent_executor.py",
    "snapshot": "tests/test_github_project_snapshot.py",
    "entry": "tests/test_github_project_entrypoint.py",
}
PHP_NORMALIZATION = r"""
require 'src/GitHubProjectSnapshot.php';
$cls = \ControlBot\GitHub\GitHubProjectSnapshot::class;
$merge = new ReflectionMethod($cls, 'mergeability');
$review = new ReflectionMethod($cls, 'reviewState');
$mergeResults = [];
foreach (['true' => true, 'false' => false, 'null' => null, 'bad' => 'yes'] as $key => $value) {
  try { $mergeResults[$key] = $merge->invoke(null, $value); }
  catch (Throwable) { $mergeResults[$key] = 'rejected'; }
}
function row(string $state, string $reviewer = 'fixture-reviewer'): array {
  return ['state' => $state, 'user' => ['login' => $reviewer]];
}
$cases = [
  'approved' => [row('APPROVED')],
  'changes_requested' => [row('CHANGES_REQUESTED')],
  'commented' => [row('COMMENTED')],
  'dismissed' => [row('DISMISSED')],
  'pending' => [],
  'latest_per_reviewer' => [
      row('CHANGES_REQUESTED'), row('APPROVED')],
  'unresolved_other_reviewer' => [
      row('CHANGES_REQUESTED', 'fixture-one'),
      row('APPROVED', 'fixture-two')],
  'invalid' => [row('NOT_A_REVIEW')],
  'budget_exceeded' => array_fill(0, 100, row('APPROVED')),
];
$reviews = [];
foreach ($cases as $key => $value) {
  try { $reviews[$key] = $review->invoke(null, $value); }
  catch (Throwable) { $reviews[$key] = 'rejected'; }
}
echo json_encode(['mergeability' => $mergeResults, 'reviews' => $reviews],
  JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
"""


def helper(key: str) -> dict:
    path = ROOT / FIXTURES[key]
    if not path.is_file():
        raise AssertionError("UNKNOWN: offline PHP integration fixture missing")
    return runpy.run_path(str(path))


def normalization() -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    run = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-r", PHP_NORMALIZATION],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: normalization probe failed: " + run.stderr[-400:])
    return json.loads(run.stdout)


class CoberturaControlbot27Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.owner = helper("owner")
        cls.intent = helper("intent")
        cls.snapshot = helper("snapshot")
        cls.entry = helper("entry")

    def test_contrato_principal(self) -> None:
        self.assertEqual(20, len(EXECUTABLE | CLASSIFIED_C.keys()))
        self.assertEqual(15, len(EXECUTABLE))
        self.assertTrue(EXECUTABLE.isdisjoint(CLASSIFIED_C))

        # CB-0433: only a server-origin trustedSessionSource reaches fake.
        request, trusted = self.owner["candidate"]()
        success = self.owner["execute"](
            [{"request": request, "now": 1060}],
            {request["session_ref"]: trusted},
        )
        self.assertEqual(1, success["calls"])
        self.assertEqual("simulated", success["receipts"][0]["fixture_status"])
        self.assertEqual("deny", success["receipts"][0]["decision"])
        self.assertFalse(success["receipts"][0]["runtime_authorized"])

        # CB-0434: the trusted server record binds owner, reauth, action and nonce.
        self.assertEqual(1, success["source_calls"])
        self.assertEqual(1, len(success["audit"]))
        self.assertEqual(64, len(self.owner["execute"](
            [{"request": {**request, "nonce": f"{i:032x}"}, "now": 1060}
             for i in range(75)],
            {request["session_ref"]: trusted},
        )["audit"]))

        # CB-0438/0439/0440: real PHP simulate, valid test grant, exact SHA+green.
        valid = self.intent["candidate"]()
        simulated = self.intent["execute"](valid)
        self.assertEqual("simulated", simulated["fake_effect"]["status"])
        self.assertEqual(1, len(simulated["fake_state"]["merges"]))
        self.assertEqual("deny", simulated["decision"])
        self.assertIs(simulated["execution"], False)
        self.assertIs(simulated["runtime_authorized"], False)
        self.assertEqual("a" * 40, simulated["fake_state"]["merges"][0]["head_sha"])

        # CB-0441: approval fixture never publishes a release.
        release = self.intent["execute"](self.intent["candidate"]("release.approve"))
        self.assertEqual("simulated", release["fake_effect"]["status"])
        self.assertEqual(1, len(release["fake_state"]["approvals"]))
        self.assertIs(release["fake_state"]["approvals"][0]["published"], False)
        self.assertIs(release["runtime_authorized"], False)

        # CB-0443: 20 detailed PRs maximum; only GET requests.
        bounded = self.snapshot["scenario"]("bounded")
        details = [call for call in bounded["calls"] if "/pulls/7" in call[1]]
        self.assertEqual(40, len(details))
        self.assertTrue(bounded["bounded"]["pull_requests"]["truncated"])
        for method, url, _, payload in details:
            self.assertEqual("GET", method)
            self.assertIsNone(payload)
            self.assertIn("api.github.com/repos/", url)

        # CB-0444/0445/0446: exact merge and review enums, last reviewer wins.
        normalized = normalization()
        self.assertEqual(
            {"true": "mergeable", "false": "conflicting", "null": "unknown",
             "bad": "rejected"}, normalized["mergeability"],
        )
        for key in ("approved", "changes_requested", "commented", "dismissed", "pending"):
            self.assertEqual(key, normalized["reviews"][key])
        self.assertEqual("approved", normalized["reviews"]["latest_per_reviewer"])
        self.assertEqual("changes_requested",
                         normalized["reviews"]["unresolved_other_reviewer"])
        self.assertNotIn("fixture-reviewer", json.dumps(normalized))

        # CB-0447/0448: invalid/unknown evidence remains truncated and unknown.
        invalid = self.snapshot["scenario"]("review-invalid")
        self.assertEqual("unknown", invalid["bad_review"]["items"][0]["review_state"])
        self.assertEqual("unknown",
                         invalid["null_mergeable"]["items"][0]["mergeability"])
        self.assertTrue(all(x["truncated"] for x in invalid.values()))

        # CB-0449/0450: real GET entrypoint renders existing read-only view.
        entry = self.entry["scenario"]("exact")
        page = entry["alpha"]
        self.assertEqual(200, page["status"])
        self.assertIn("pl0n3r/Alpha", page["body"])
        self.assertNotIn("pl0n3r/Beta", page["body"])
        self.assertNotIn("<form", page["body"].lower())
        self.assertNotIn("<button", page["body"].lower())
        self.assertIn("Freshness <strong>STALE</strong>", entry["stale"]["body"])
        self.assertIn("UNKNOWN / STALE", entry["stale"]["body"])

        # CB-0451: no cross-project evidence on missing/malformed/duplicate.
        closed = self.entry["scenario"]("closed")
        for key in ("unknown", "malformed", "duplicate", "invalid_projection"):
            self.assertEqual(404, closed[key]["status"])
            self.assertEqual("Not found.\n", closed[key]["body"])
            self.assertNotIn("Alpha", closed[key]["body"])
            self.assertNotIn("Beta", closed[key]["body"])

    def test_falla_cerrado(self) -> None:
        # CB-0433/0434: caller cannot upgrade a client request to trusted.
        request, trusted = self.owner["candidate"]()
        for key, bad in (("owner", "other"), ("server_verified", False),
                         ("owner_verified", False), ("source", "request"),
                         ("origin", "client"), ("reauthenticated_at", 900),
                         ("bound_nonce", "ff" * 16), ("revoked", True)):
            with self.subTest(field=key):
                record = copy.deepcopy(trusted)
                record[key] = bad
                denied = self.owner["execute"](
                    [{"request": request, "now": 1060}],
                    {request["session_ref"]: record},
                )
                self.assertEqual(0, denied["calls"])
                self.assertEqual("deny", denied["receipts"][0]["decision"])
                self.assertIs(denied["receipts"][0]["execution"], False)
        injected = self.owner["execute"](
            [{"request": {**request, "server_verified": True}, "now": 1060}],
            {request["session_ref"]: trusted},
        )
        self.assertEqual(0, injected["calls"])

        # CB-0439/0440: stale SHA, missing/duplicate checks and forged owner deny.
        mutations = (
            lambda p: p["intent"]["params"].update(expected_head_sha="b" * 40),
            lambda p: p["fixture"]["pr"]["checks"].pop(),
            lambda p: p["fixture"]["pr"]["checks"][0].update(
                status="in_progress", conclusion=None),
            lambda p: p["fixture"]["pr"]["checks"].append(
                copy.deepcopy(p["fixture"]["pr"]["checks"][0])),
            lambda p: p["fixture"]["approval"].update(approved=False),
            lambda p: p["fixture"]["trusted"].update(origin="caller"),
            lambda p: p["fixture"]["pr"].update(mergeable=False),
        )
        for index, mutation in enumerate(mutations):
            with self.subTest(denial=index):
                candidate = self.intent["candidate"]()
                mutation(candidate)
                denied = self.intent["execute"](candidate)
                self.assertEqual("denied", denied["fake_effect"]["status"])
                self.assertEqual([], denied["fake_state"]["merges"])
                self.assertIs(denied["runtime_authorized"], False)
        # CB-0447/0448: invalid normalization cannot be misread as ready.
        normalized = normalization()
        self.assertEqual("rejected", normalized["reviews"]["invalid"])
        self.assertEqual("unknown", normalized["reviews"]["budget_exceeded"])
        self.assertEqual("unknown", normalized["mergeability"]["null"])
        self.assertEqual("rejected", normalized["mergeability"]["bad"])

        # CB-0451 and access control for CB-0450.
        denied = self.entry["scenario"]("closed")
        self.assertEqual(403, denied["wrong_owner"]["status"])
        self.assertEqual(405, denied["wrong_method"]["status"])
        self.assertEqual("GET", denied["wrong_method"]["headers"]["Allow"])

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(5, len(CLASSIFIED_C))
        self.assertTrue(all(CLASSIFIED_C.values()))
        self.assertTrue(all((ROOT / p).is_file() for p in FIXTURES.values()))
        result = self.intent["execute"](self.intent["candidate"]("pr.review"))
        self.assertEqual("deny", result["decision"])
        self.assertIs(result["execution"], False)
        self.assertIs(result["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", result)
        self.assertEqual([], result["fake_state"]["merges"])
        owner_request, owner_trusted = self.owner["candidate"]()
        owner = self.owner["execute"](
            [{"request": owner_request, "now": 1060}],
            {owner_request["session_ref"]: owner_trusted},
            sender_mode="throw",
        )
        self.assertEqual("ambiguous", owner["receipts"][0]["fixture_status"])
        self.assertIs(owner["receipts"][0]["execution"], False)
        self.assertNotIn(owner_request["session_ref"], json.dumps(owner))
        self.assertNotIn("PRIVATE_TOKEN_DO_NOT_PRINT", json.dumps(owner))
        source = (ROOT / "src/GitHubProjectEntrypoint.php").read_text().lower()
        for forbidden in ("api.github.com", "curl_", "workflow_dispatch",
                          "'post'", "'put'", "'patch'", "'delete'"):
            self.assertNotIn(forbidden, source)

    def test_estados_unknown_explicitos(self) -> None:
        # AC-04 independent regression: incomplete/missing review or runtime
        # evidence must not create ready, authorized or owner-GitHub access.
        norm = normalization()
        self.assertEqual("unknown", norm["mergeability"]["null"])
        self.assertEqual("unknown", norm["reviews"]["budget_exceeded"])
        raw = self.snapshot["scenario"]("review-invalid")
        for key in ("bad_review", "null_mergeable", "bad_mergeability"):
            self.assertTrue(raw[key]["truncated"])
            row = raw[key]["items"][0]
            self.assertTrue(
                row["review_state"] == "unknown"
                or row["mergeability"] == "unknown"
            )
        stale = self.entry["scenario"]("exact")["stale"]["body"]
        self.assertIn("UNKNOWN / STALE", stale)
        closed = self.entry["scenario"]("closed")
        self.assertTrue(all(closed[key]["status"] == 404 for key in
                            ("unknown", "malformed", "duplicate", "invalid_projection")))
        candidate = self.intent["candidate"]()
        candidate["fixture"]["pr"]["checks"][0]["status"] = "in_progress"
        candidate["fixture"]["pr"]["checks"][0]["conclusion"] = None
        pending = self.intent["execute"](candidate)
        self.assertEqual("denied", pending["fake_effect"]["status"])
        self.assertIs(pending["runtime_authorized"], False)


if __name__ == "__main__":
    unittest.main()
