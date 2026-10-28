"""CB-0425..0439: evidence from real PHP executors with synthetic fixtures only.

LOCAL_CONTRACT means the offline fake behaved as expected. GitHub network,
production authorization, go-live and CI always remain UNKNOWN here.
"""
from __future__ import annotations

import copy
import json
import re
import shutil
import unittest
from functools import lru_cache
from pathlib import Path
from tests import test_github_issue_intent_executor as iss
from tests import test_github_owner_session_approval_bridge as own
from tests import test_github_pr_intent_executor as prs

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(425, 440))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
DOCS = {
    "issue": "docs/github-issue-intent-executor.md",
    "owner": "docs/github-owner-session-approval-bridge.md",
    "pr": "docs/github-pr-intent-executor.md",
}
SOURCES = {
    "issue": "src/GitHubIssueIntentExecutor.php",
    "owner": "src/GitHubOwnerSessionApprovalBridge.php",
    "pr": "src/GitHubPullRequestIntentExecutor.php",
}
TESTS = {
    "issue": "tests/test_github_issue_intent_executor.py",
    "owner": "tests/test_github_owner_session_approval_bridge.py",
    "pr": "tests/test_github_pr_intent_executor.py",
}
GROUP = {**{f"CB-{i:04d}": "issue" for i in range(425, 432)},
         **{f"CB-{i:04d}": "owner" for i in range(432, 437)},
         **{f"CB-{i:04d}": "pr" for i in range(437, 440)}}


def anchors(text: str) -> dict[str, str]:
    """The matrix contains three source rows, not fifteen independent rows."""
    found = {}
    for line in text.splitlines():
        match = re.match(r"^\| (CB-04(?:27|32|37)) \|", line)
        if match:
            key = match.group(1)
            if key in found:
                raise ValueError("duplicate anchor: " + key)
            if len(line.strip().strip("|").split("|")) < 5:
                raise ValueError("malformed anchor: " + key)
            found[key] = line
    return found


def sources() -> dict[str, str]:
    paths = {**DOCS, **{k + "_php": v for k, v in SOURCES.items()},
             **{k + "_test": v for k, v in TESTS.items()}}
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in paths.items()}


def denied(out: dict) -> bool:
    return (out.get("decision") == "deny"
            and out.get("execution") is False
            and out.get("runtime_authorized") is False)


@lru_cache(maxsize=1)
def synthetic() -> dict[str, object]:
    """Run the repository's existing PHP CLI harnesses; no external transport."""
    original = iss.candidate()
    created = iss.execute(copy.deepcopy(original))
    reserved = iss.execute(iss.candidate("issue.reserve"))
    active_close = iss.candidate("issue.close")
    active_close["state"]["issues"][0]["reservation_ref"] = "controlbot:fixture/reservation"
    blocked_close = iss.execute(active_close)
    uncertain = iss.candidate()
    uncertain["fixture"]["outcome"] = "ambiguous"
    ambiguous = iss.execute(uncertain)
    retry = copy.deepcopy(original)
    for key, key_result in (("state", "fake_state"), ("audit", "audit"),
                            ("ledger", "ledger")):
        retry[key] = created[key_result]
    replay = iss.execute(copy.deepcopy(retry))
    changed = copy.deepcopy(retry)
    changed["fixture"]["issue_number"] = 102
    conflict = iss.execute(changed)

    request, record = own.candidate()
    owner_ok = own.execute([{"request": request, "now": 1060}])
    hostile = copy.deepcopy(record)
    hostile["owner"] = "synthetic-other"
    owner_denied = own.execute([{"request": request, "now": 1060}],
                              {request["session_ref"]: hostile})
    owner_replay = own.execute([
        {"request": request, "now": 1060},
        {"request": request, "now": 1061}])
    sender_error = own.execute([
        {"request": request, "now": 1060},
        {"request": request, "now": 1061}], sender_mode="throw")

    merged = prs.execute(prs.candidate())
    wrong = prs.candidate()
    wrong["intent"]["params"]["expected_head_sha"] = "b" * 40
    wrong_sha = prs.execute(wrong)
    waiting = prs.candidate()
    waiting["fixture"]["pr"]["checks"][0].update(status="in_progress", conclusion=None)
    waiting_checks = prs.execute(waiting)
    release = prs.execute(prs.candidate("release.approve"))
    return locals()


def evidence(row: str, anchors_by_id: dict[str, str],
             src: dict[str, str], observed: dict | None) -> dict[str, str]:
    status = {"code": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN}
    if row not in GROUP or not isinstance(observed, dict):
        return status
    if set(src) != {**DOCS, **{k + "_php": v for k, v in SOURCES.items()},
                   **{k + "_test": v for k, v in TESTS.items()}}.keys():
        return status
    if any(not isinstance(v, str) or not v for v in src.values()):
        return status
    if any(a not in anchors_by_id or "NO_VERIFICABLE" not in anchors_by_id[a]
           for a in ("CB-0427", "CB-0432", "CB-0437")):
        return status
    group = GROUP[row]
    doc, php, test = src[group], src[group + "_php"], src[group + "_test"]
    if not (("offline" in doc.lower() or "solo pruebas" in doc.lower())
            and "function simulate" in php and "unittest" in test):
        return status
    f = observed
    required = ("created", "reserved", "blocked_close", "ambiguous",
                "replay", "conflict", "owner_ok", "owner_denied",
                "owner_replay", "sender_error", "merged", "wrong_sha",
                "waiting_checks", "release")
    if not all(k in f for k in required):
        return status
    checks = {
        "CB-0425": denied(f["created"]) and "execution=false" in doc,
        "CB-0426": bool(shutil.which("php")) and "github_issue_intent_executor_scenarios.php" in test
                   and f["created"]["receipt"]["status"] == "executed",
        "CB-0427": "Solo pruebas offline" in doc and denied(f["created"])
                   and "runtime_authorized=false" in doc,
        "CB-0428": denied(f["created"]) and f["created"]["fake_effect"]["status"] == "simulated"
                   and len(f["created"]["fake_state"]["events"]) == 1,
        "CB-0429": f["reserved"]["fake_state"]["issues"][0]["reservation_ref"]
                   == "controlbot:fixture/reservation"
                   and f["blocked_close"]["fake_effect"]["status"] == "denied",
        "CB-0430": f["replay"]["replayed"] is True
                   and f["replay"]["fake_state"] == f["created"]["fake_state"]
                   and f["conflict"]["fake_effect"]["status"] == "denied",
        "CB-0431": "Revertir el squash" in doc and "Sin PHP runtime nuevo" in doc
                   and ("curl_" + "exec(") not in php,
        "CB-0432": "no autoriza GitHub mutante" in doc and f["owner_ok"]["calls"] == 1
                   and denied(f["owner_ok"]["receipts"][0]),
        "CB-0433": "trustedSessionSource" in doc and f["owner_denied"]["calls"] == 0
                   and f["owner_denied"]["receipts"][0]["fixture_status"] == "denied",
        "CB-0434": f["owner_ok"]["receipts"][0]["fixture_status"] == "simulated"
                   and f["owner_replay"]["calls"] == 1
                   and f["owner_replay"]["receipts"][1]["reason"] == "replay"
                   and f["sender_error"]["receipts"][1]["reason"] == "replay",
        "CB-0435": "python -m unittest tests.test_github_owner_session_approval_bridge" in doc
                   and "test_reauth_age_action_scope_and_nonce_are_server_bound" in test
                   and "test_revocation_or_replay_never_reaches_fake_sender" in test,
        "CB-0436": "Revertir el squash" in doc and "Ningún" in doc
                   and denied(f["owner_ok"]["receipts"][0]),
        "CB-0437": "simulaciones offline" in doc and denied(f["merged"])
                   and f["merged"]["fake_effect"]["status"] == "simulated",
        "CB-0438": "simulate()" in doc and "function simulate" in php
                   and ("curl_" + "exec(") not in php and denied(f["merged"]),
        "CB-0439": len(f["merged"]["fake_state"]["merges"]) == 1
                   and f["wrong_sha"]["fake_effect"]["status"] == "denied"
                   and f["waiting_checks"]["fake_effect"]["status"] == "denied"
                   and f["waiting_checks"]["fake_state"]["merges"] == [],
    }
    if checks[row] is True:
        status["code"] = LOCAL
    return status


class BrechasControlbot29Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = anchors((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        src, fixture = sources(), synthetic()
        self.assertEqual(set(GROUP), set(IDS))
        for ident in IDS:
            with self.subTest(row=ident):
                self.assertEqual(evidence(ident, rows, src, fixture)["code"], LOCAL)

    def test_falla_cerrado(self) -> None:
        rows = anchors((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        src, fixture = sources(), synthetic()
        for ident in IDS:
            with self.subTest(row=ident):
                self.assertEqual(evidence(ident, {}, src, fixture)["code"], UNKNOWN)
                self.assertEqual(evidence(ident, rows, {}, fixture)["code"], UNKNOWN)
                self.assertEqual(evidence(ident, rows, src, None)["code"], UNKNOWN)
                missing = dict(fixture)
                missing.pop("created")
                self.assertEqual(evidence(ident, rows, src, missing)["code"], UNKNOWN)
                invalid = dict(rows)
                invalid["CB-0427"] = invalid["CB-0427"].replace(
                    "NO_VERIFICABLE", "CUBIERTO")
                self.assertEqual(evidence(ident, invalid, src, fixture)["code"], UNKNOWN)
        self.assertEqual(evidence("CB-9999", rows, src, fixture)["code"], UNKNOWN)
        one = rows["CB-0427"]
        with self.assertRaisesRegex(ValueError, "duplicate"):
            anchors(one + "\n" + one)

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(shutil.which("php"))
        own_text = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own_text)
        self.assertNotRegex(own_text, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket)\b")
        for forbidden in ("curl_" + "exec(", "github.Github(", "http.request("):
            self.assertNotIn(forbidden, own_text)
        for name in ("created", "reserved", "merged"):
            self.assertTrue(denied(synthetic()[name]))

    def test_estados_unknown_explicitos(self) -> None:
        rows = anchors((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        src, fixture = sources(), synthetic()
        for ident in IDS:
            with self.subTest(row=ident):
                self.assertEqual(evidence(ident, rows, src, fixture),
                                 {"code": LOCAL, "ci": UNKNOWN, "live": UNKNOWN})


if __name__ == "__main__":
    unittest.main()
