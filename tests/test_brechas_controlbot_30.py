"""ControlBot #1037: fifteen offline GitHub clauses, CB-0440..CB-0454.

The existing PHP fixture executors are exercised, not a GitHub connection.
A passing LOCAL_CONTRACT is never owner authority, live, or production evidence.
"""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_offline_coverage_row

import copy
import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from tests import test_github_pr_intent_executor as intent
from tests import test_github_project_snapshot as snapshot
from tests import test_github_pr_evidence as pr_evidence
from tests import test_github_project_entrypoint as project
from tests import test_github_public_entrypoint as public
from tests import test_github_readonly_https_transport as https

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(440, 455))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
MATRICES = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-02.md")
DOCS = {
    "intent": "docs/github-pr-intent-executor.md",
    "reviews": "docs/github-pr-review-visibility-v1.md",
    "project": "docs/github-project-entrypoint.md",
    "public": "docs/github-public-entrypoint.md",
    "https": "docs/github-readonly-https-transport.md",
}
SOURCE = {
    **{f"CB-{number:04d}": ("intent", anchor)
       for number, anchor in ((440, 7), (441, 8), (442, 13))},
    **{f"CB-{number:04d}": ("reviews", anchor)
       for number, anchor in ((443, 10), (444, 12), (445, 13),
                              (446, 14), (447, 15), (448, 16), (449, 22))},
    "CB-0450": ("project", 3), "CB-0451": ("project", 5),
    "CB-0452": ("public", 3), "CB-0453": ("public", 5),
    "CB-0454": ("https", 3),
}
TOKENS = {
    "CB-0440": "GitHubIntentTestGrantAuthority",
    "CB-0441": "release.approve",
    "CB-0442": "AC-01 mismatch SHA",
    "CB-0443": "primeros 20 PRs",
    "CB-0444": "mergeability",
    "CB-0445": "review_state",
    "CB-0446": "última señal",
    "CB-0447": "budget",
    "CB-0448": "degrada el estado",
    "CB-0449": "No se añaden credenciales",
    "CB-0450": "GitHubProjectEntrypoint",
    "CB-0451": "Ruta malformada",
    "CB-0452": "GitHubPublicEntrypoint",
    "CB-0453": "Contrato local",
    "CB-0454": "Autorización: puerta",
}


def coverage_rows(*documents: str) -> dict[str, tuple[str, ...]]:
    """Require exactly fifteen original rows across both disjoint matrices."""
    found = {}
    for document in documents:
        for line in document.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            if key in found or len(cells) != 6 or not all(cells):
                raise ValueError("UNKNOWN: duplicate or ambiguous matrix row " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing matrix row")
    return found


def local_evidence(identifier: str, rows: dict, docs: dict,
                   observed: object) -> dict[str, str]:
    """Do not elevate a PHP fixture or documentation to production attestation."""
    status = {"code": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN}
    if identifier not in SOURCE or not isinstance(observed, dict):
        return status
    row = rows.get(identifier)
    kind, anchor = SOURCE[identifier]
    document = docs.get(kind)
    if (not isinstance(row, tuple) or len(row) != 6
            or row[0] != identifier or not credible_offline_coverage_row(row)
            or not isinstance(document, str) or not document
            or "/" + DOCS[kind] + "#L" + str(anchor) not in row[1]
            or TOKENS[identifier] not in document
            or "UNKNOWN" not in row[4]
            or observed.get(identifier) is not True):
        return status
    status["code"] = LOCAL
    return status


def safely_denied(result: object) -> bool:
    return (isinstance(result, dict) and result.get("decision") == "deny"
            and result.get("execution") is False
            and result.get("runtime_authorized") is False)


def no_live_attestation(identifier: str, evidence: object = None) -> str:
    """Fake HTTP, CI strings, signed test grants and version are not live proof."""
    return UNKNOWN


def repeated_reviewer_signals() -> dict:
    """Invoke the actual private PHP normalizer on fake repeat-review sequences."""
    php = r"""
    require 'src/GitHubProjectSnapshot.php';
    $method = new ReflectionMethod(\ControlBot\GitHub\GitHubProjectSnapshot::class, 'reviewState');
    $row = static fn($state) => ['id'=>1, 'state'=>$state,
                                'user'=>['login'=>'synthetic-reviewer']];
    $out = [
      'approve_last' => $method->invoke(null, [$row('CHANGES_REQUESTED'), $row('APPROVED')]),
      'change_last' => $method->invoke(null, [$row('APPROVED'), $row('CHANGES_REQUESTED')]),
      'missing' => $method->invoke(null, []),
    ];
    echo json_encode($out, JSON_THROW_ON_ERROR);
    """
    result = subprocess.run(['php', '-r', php], cwd=ROOT, text=True,
                            capture_output=True, timeout=20, check=False)
    if result.returncode:
        raise AssertionError(result.stderr or result.stdout)
    return json.loads(result.stdout)


@lru_cache(maxsize=1)
def synthetic() -> dict[str, bool]:
    """Execute repository-maintained PHP scenarios with fully fake local state."""
    merged = intent.execute(intent.candidate())
    wrong = intent.candidate()
    wrong["intent"]["params"]["expected_head_sha"] = "b" * 40
    mismatch = intent.execute(wrong)
    pending = intent.candidate()
    pending["fixture"]["pr"]["checks"][0].update(status="in_progress", conclusion=None)
    no_checks = intent.execute(pending)
    release = intent.execute(intent.candidate("release.approve"))
    original = intent.candidate()
    retry_payload = copy.deepcopy(original)
    for key, output in (("state", "fake_state"), ("audit", "audit"), ("ledger", "ledger")):
        retry_payload[key] = merged[output]
    replay = intent.execute(retry_payload)

    routes = snapshot.scenario("routes")
    normalized = snapshot.scenario("normalize")["snapshot"]["repositories"][0]
    bounded = snapshot.scenario("bounded")
    invalid = snapshot.scenario("review-invalid")
    reviews = pr_evidence.scenario("normal")
    ambiguous = pr_evidence.scenario("ambiguous")
    exact = project.scenario("exact")
    closed = project.scenario("closed")
    owner = public.scenario("owner")
    missing = public.scenario("unknown")
    transport = https.run(enabled=True)
    disabled = https.run()
    rejected = https.run(enabled=True, req={**https.request(), "method": "POST"})

    repeated = repeated_reviewer_signals()
    pr_items = reviews["evidence"]["items"]
    candidate = normalized["pull_requests"]["items"][0]
    bad_review = invalid["bad_review"]
    bad_merge = invalid["bad_mergeability"]
    null_merge = invalid["null_mergeable"]
    detail = [call for call in bounded["calls"] if "/pulls/7" in call[1]]
    all_calls = routes["calls"]
    global_owner = owner["owner"]
    local_view = exact["alpha"]
    output = transport["out"]

    return {
        # CB-0440: trusted test authority is still never a runtime grant.
        "CB-0440": (safely_denied(merged) and merged["fake_effect"]["status"] == "simulated"
                    and len(merged["fake_state"]["merges"]) == 1),
        # CB-0441: explicit candidate+release, approved only on fake state.
        "CB-0441": (safely_denied(release) and release["fake_effect"]["status"] == "simulated"
                    and release["fake_state"]["approvals"][0]["published"] is False
                    and not release["fake_state"]["merges"]),
        # CB-0442: SHA, checks, replay and denial exercised on real PHP.
        "CB-0442": (mismatch["fake_effect"]["status"] == "denied"
                    and no_checks["fake_effect"]["status"] == "denied"
                    and not mismatch["fake_state"]["merges"]
                    and not no_checks["fake_state"]["merges"]
                    and replay["replayed"] is True
                    and replay["fake_state"] == merged["fake_state"]),
        # CB-0443: only the first 20 PRs get two detail GET calls each.
        "CB-0443": (len(detail) == 40 and bounded["bounded"]["pull_requests"]["truncated"] is True
                    and all(method == "GET" and body is None for method, _, _, body in detail)),
        # CB-0444: normalized mergeability, unknown on ambiguity.
        "CB-0444": (candidate["mergeability"] == "mergeable"
                    and bad_merge["items"][0]["mergeability"] == "unknown"
                    and null_merge["items"][0]["mergeability"] == "unknown"),
        # CB-0445: normalized reviews, unknown on invalid testimony.
        "CB-0445": (candidate["review_state"] == "approved"
                    and bad_review["items"][0]["review_state"] == "unknown"),
        # CB-0446: latest reviewer signal wins; no identity/body in projection.
        "CB-0446": (repeated == {"approve_last": "approved",
                                 "change_last": "changes_requested", "missing": "pending"}
                    and bool(pr_items)
                    and all("body" not in x and "email" not in x for x in pr_items)),
        # CB-0447: missing/ambiguous review state marks truncated.
        "CB-0447": (bad_review["truncated"] is True
                    and ambiguous["evidence"]["items"][0]["reviews"]["evidence_state"] == "UNKNOWN"
                    and ambiguous["evidence"]["items"][0]["mergeability"]["state"] == "UNKNOWN"),
        # CB-0448: unknown review/mergeability never implies ready.
        "CB-0448": (bad_merge["truncated"] is True and null_merge["truncated"] is True
                    and bad_review["truncated"] is True),
        # CB-0449: actual snapshot GETs have no mutate verb/body.
        "CB-0449": (bool(all_calls) and all(method == "GET" and body is None
                                           for method, _, _, body in all_calls)),
        # CB-0450: exact owner-only project projection has one project.
        "CB-0450": (local_view["status"] == 200 and "alpha-project" in local_view["body"]
                    and "beta-project" not in local_view["body"]),
        # CB-0451: malformed/duplicate/foreign projection never leaks.
        "CB-0451": (all(closed[key]["status"] == 404
                            and closed[key]["body"] == "Not found.\n"
                            for key in ("unknown", "malformed", "duplicate", "invalid_projection"))
                    and closed["wrong_owner"]["status"] == 403),
        # CB-0452: owner-only GET from local JSON; no mutation.
        "CB-0452": (global_owner["status"] == 200
                    and global_owner["headers"]["Cache-Control"] == "no-store"
                    and owner["wrong_owner"]["status"] == 403
                    and owner["wrong_method"]["status"] == 405),
        # CB-0453: missing/stale/invalid projections stay unknown.
        "CB-0453": (all("state=unknown" in missing[key]["body"]
                            for key in ("missing", "invalid", "stale"))
                    and "freshness=stale" in missing["stale"]["body"]),
        # CB-0454: TLS envelope is fake-only; runtime remains denied.
        "CB-0454": (output["status"] == "CURRENT" and output["runtime_authorized"] is False
                    and output["production_verified"] is False
                    and output["execute_actions"] is False
                    and output["authority"] == "unchanged"
                    and disabled["out"]["status"] == UNKNOWN
                    and rejected["out"]["status"] == UNKNOWN
                    and rejected["calls"] == []),
    }


class BrechasControlbot30Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = coverage_rows(*((ROOT / path).read_text(encoding="utf-8")
                                   for path in MATRICES))
        cls.docs = {name: (ROOT / path).read_text(encoding="utf-8")
                    for name, path in DOCS.items()}

    def test_contrato_principal(self) -> None:
        """AC-01: fifteen clauses have PHP-backed assertions."""
        evidence = synthetic()
        self.assertEqual(set(IDS), set(SOURCE))
        self.assertEqual(set(IDS), set(TOKENS))
        self.assertEqual(set(IDS), set(evidence))
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertIs(evidence[identifier], True, identifier)
                self.assertEqual(local_evidence(identifier, self.rows, self.docs, evidence),
                                 {"code": LOCAL, "ci": UNKNOWN, "live": UNKNOWN})

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, ambiguous, misattributed and expired fixtures deny."""
        observed = synthetic()
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(local_evidence(identifier, {}, self.docs, observed)["code"], UNKNOWN)
                self.assertEqual(local_evidence(identifier, self.rows, {}, observed)["code"], UNKNOWN)
                self.assertEqual(local_evidence(identifier, self.rows, self.docs, None)["code"], UNKNOWN)
                changed = dict(self.rows)
                cells = list(changed[identifier])
                if cells[3] == "CUBIERTO":
                    cells[4] = cells[4].replace("MERGED", "PENDING", 1)
                else:
                    cells[3] = "CUBIERTO"
                changed[identifier] = tuple(cells)
                self.assertEqual(local_evidence(identifier, changed, self.docs, observed)["code"], UNKNOWN)
                changed = dict(self.docs)
                changed[SOURCE[identifier][0]] = ""
                self.assertEqual(local_evidence(identifier, self.rows, changed, observed)["code"], UNKNOWN)
                invalid = dict(observed)
                invalid[identifier] = None
                self.assertEqual(local_evidence(identifier, self.rows, self.docs, invalid)["code"], UNKNOWN)
        data = (ROOT / MATRICES[1]).read_text(encoding="utf-8")
        row = next(line for line in data.splitlines() if line.startswith("| CB-0440 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            coverage_rows((ROOT / MATRICES[0]).read_text(encoding="utf-8"), data + "\n" + row)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            coverage_rows((ROOT / MATRICES[0]).read_text(encoding="utf-8"), data.replace(row + "\n", "", 1))
        mismatch = intent.candidate()
        mismatch["intent"]["params"]["expected_head_sha"] = "b" * 40
        self.assertEqual(intent.execute(mismatch)["fake_effect"]["status"], "denied")
        self.assertEqual(https.run(enabled=True, req={**https.request(), "method": "PATCH"})["calls"], [])

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic and locally scoped, no credentials or network calls."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)\b[A-Za-z0-9_.+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")
        self.assertEqual(len(DOCS), 5)
        for path in (*MATRICES, *DOCS.values()):
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())
        simulated = intent.execute(intent.candidate())
        self.assertTrue(safely_denied(simulated))
        self.assertNotIn("fixture_proof", json.dumps(simulated).lower())
        self.assertFalse(https.run()["out"]["execute_actions"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local scenario never proves CI, deploy or live."""
        observed = synthetic()
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertTrue(credible_offline_coverage_row(self.rows[identifier]))
                self.assertEqual(local_evidence(identifier, self.rows, self.docs, observed),
                                 {"code": LOCAL, "ci": UNKNOWN, "live": UNKNOWN})
                for fake in (None, {}, {"ci": "success"},
                             {"github_sha": "a" * 40, "owner_verified": True},
                             {"factory_release": "v1", "approved": True}):
                    self.assertEqual(no_live_attestation(identifier, fake), UNKNOWN)
        self.assertEqual(local_evidence("CB-9999", self.rows, self.docs, observed)["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
