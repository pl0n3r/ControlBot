#!/usr/bin/env python3
"""ControlBot #1010: offline, fail-closed tests for CB-0031 through CB-0045.

Existing PHP scenario programs exercise production classes with synthetic data.
LOCAL_CONTRACT is never a claim of real ingestion, execution, CI or deployment.
The two coverage-matrix partitions remain unchanged and NO_VERIFICABLE.
"""
from __future__ import annotations

import copy
import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(31, 46))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
PARTS = ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-01.md")
DOCS = {
    "aegis": "docs/aegis-remediation.md",
    "core": "docs/agent-replay-core-v1.md",
    "lifecycle": "docs/agent-replay-lifecycle-v1.md",
}
SCRIPTS = {
    "aegis": "aegis_remediation_scenarios.php",
    "core": "agent_replay_core_scenarios.php",
    "lifecycle": "agent_replay_lifecycle_scenarios.php",
}
CASES = {
    "aegis": ("auto", "authority", "high_risk", "idempotency",
              "verification", "no_provider", "deterministic"),
    "core": ("stable", "states", "handoff", "conflict", "sensitive", "purity"),
    "lifecycle": ("complete", "missing", "incident78", "pr86",
                  "manual", "determinism"),
}
STAGES = ("issue", "reservation", "plan", "commit", "review",
          "check", "merge", "deploy")


def group_for(identifier: str) -> str:
    number = int(identifier.removeprefix("CB-"))
    if number <= 38:
        return "aegis"
    if number <= 41:
        return "core"
    return "lifecycle"


@lru_cache(maxsize=32)
def scenario(group: str, name: str) -> dict:
    if group not in CASES or name not in CASES[group]:
        raise ValueError("UNKNOWN: scenario not allowlisted")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    result = subprocess.run(
        [php, str(ROOT / "tests" / SCRIPTS[group]), name],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(
            "UNKNOWN: synthetic contract failed: " + result.stderr[-300:]
        )
    try:
        value = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: scenario returned invalid JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: synthetic scenario is not an object")
    return value


def matrix_rows(fragments: dict[str, str]) -> dict[str, tuple[str, ...]]:
    """Require exact source partitions and unique six-column matrix rows."""
    if set(fragments) != set(PARTS):
        raise ValueError("UNKNOWN: incomplete matrix partitions")
    found: dict[str, tuple[str, ...]] = {}
    for path in PARTS:
        content = fragments[path]
        if not isinstance(content, str) or not content:
            raise ValueError("UNKNOWN: missing matrix text")
        for line in content.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if not match or match.group(1) not in IDS:
                continue
            identifier = match.group(1)
            if identifier in found:
                raise ValueError("UNKNOWN: duplicated row " + identifier)
            cells = tuple(c.strip() for c in line.strip().strip("|").split("|"))
            if len(cells) != 6 or cells[0] != identifier:
                raise ValueError("UNKNOWN: malformed row " + identifier)
            found[identifier] = cells
    return found


def read_sources() -> tuple[dict[str, tuple[str, ...]], dict[str, str]]:
    fragments = {p: (ROOT / p).read_text(encoding="utf-8") for p in PARTS}
    docs = {g: (ROOT / p).read_text(encoding="utf-8") for g, p in DOCS.items()}
    return matrix_rows(fragments), docs


def evidence() -> dict[str, dict[str, dict]]:
    return {group: {case: scenario(group, case) for case in cases}
            for group, cases in CASES.items()}


def supported(identifier: str, data: dict) -> bool:
    """Each of the fifteen IDs has one substantive, synthetic witness."""
    a, c, l = data.get("aegis", {}), data.get("core", {}), data.get("lifecycle", {})
    try:
        auto, auth, risks = a["auto"], a["authority"], a["high_risk"]
        idem, verification = a["idempotency"], a["verification"]
        stable, states = c["stable"], c["states"]
        conflict, purity = c["conflict"], c["purity"]
        complete, missing = l["complete"], l["missing"]
        incident, draft, manual = l["incident78"], l["pr86"], l["manual"]
        output = {
            "CB-0031": (
                auto["decision"] == "auto_eligible"
                and a["no_provider"]["decision"] == "auto_eligible"
                and a["deterministic"]["same"] is True
            ),
            "CB-0032": (
                auto["authority"]["decision"] == "allow"
                and auth["unknown_policy"]["decision"] == "deny"
                and auth["insufficient"]["decision"] == "owner_decision_required"
            ),
            "CB-0033": (
                auth["policy_mismatch"]["decision"] == "deny"
                and auth["unknown_policy"]["auto_eligible"] is False
            ),
            "CB-0034": (
                auth["group_scope"]["scope"] == "group:pl0n3r"
                and auth["group_scope"]["decision"] == "auto_eligible"
                and auth["short_scope_rejected"] is True
            ),
            "CB-0035": (
                auto["auto_eligible"] is True
                and auto["proposal"]["preauthorized"] is True
                and auto["proposal"]["reversible"] is True
                and auto["proposal"]["blast_radius"] == "low"
                and auto["proposal"]["risk_categories"] == []
                and all(not item["auto_eligible"] for item in risks.values())
            ),
            "CB-0036": (
                auto["proposal"]["required_authority_level"]
                == "L0_AI_AUTONOMOUS"
                and auth["insufficient"]["auto_eligible"] is False
                and all(item["decision"] == "owner_decision_required"
                        for item in risks.values())
            ),
            "CB-0037": (
                idem["first"]["idempotency_key"] ==
                idem["second"]["idempotency_key"]
                and idem["different"]["idempotency_key"] !=
                idem["first"]["idempotency_key"]
                and re.fullmatch(
                    r"aegis-remediation:[0-9a-f]{64}",
                    idem["first"]["idempotency_key"],
                ) is not None
                and len(idem["batch"]) == 2
            ),
            "CB-0038": (
                verification["pending"]["remediation_state"] == "not_verified"
                and verification["verified"]["remediation_state"] == "success"
                and verification["verified"]["verification"]["status"] == "verified"
                and bool(verification["verified"]["verification"]["evidence_refs"])
                and verification["verified_without_evidence_rejected"] is True
            ),
            "CB-0039": (
                [item["kind"] for item in states["events"]] ==
                ["success", "failure", "skipped", "startup_failure"]
                and [item["kind"] for item in
                     next(x for x in incident["stages"]
                          if x["name"] == "check")["events"]] ==
                ["success", "startup_failure"]
                and [item["kind"] for item in
                     next(x for x in draft["stages"]
                          if x["name"] == "check")["events"]] == ["skipped"]
            ),
            "CB-0040": (
                stable["first"] == stable["second"]
                and len(stable["first"]["events"]) == 2
                and len(conflict["events"]) == 2
                and conflict["conflicts"][0]["state"] == "unknown"
                and conflict["conflicts"][0]["reason"] == "conflicting_evidence"
                and conflict["conflicts"][0]["variant_count"] == 2
            ),
            "CB-0041": (
                purity["first"] == purity["second"]
                and re.fullmatch(
                    r"[0-9a-f]{64}", purity["first"]["fingerprint"]
                ) is not None
                and all(c["sensitive"]["rejected"])
                and all(x["work_item_id"] == "controlbot-365"
                        for x in c["handoff"]["events"])
            ),
            "CB-0042": (
                {s["name"]: {e["source"] for e in s["events"]}
                 for s in complete["stages"]} == {
                    "issue": {"github_issue"},
                    "reservation": {"factory"}, "plan": {"controlbot"},
                    "commit": {"github_commit"},
                    "review": {"github_pr"}, "check": {"github_actions"},
                    "merge": {"github_pr"}, "deploy": {"production"},
                }
                and not any(
                    x["event"]["source"] == "github_actions"
                    for x in manual["auxiliary_evidence"]
                )
            ),
            "CB-0043": (
                [s["name"] for s in complete["stages"]] == list(STAGES)
                and all(s["state"] == "observed" for s in complete["stages"])
                and missing["missing"] == ["reservation", "review", "check",
                                            "merge", "deploy"]
            ),
            "CB-0044": (
                len({event["work_item_id"]
                     for s in complete["stages"]
                     for event in s["events"]}) == 1
                and len({event["event_id"]
                         for s in complete["stages"]
                         for event in s["events"]}) == 8
                and l["determinism"]["first"] == l["determinism"]["second"]
                and l["determinism"]["first"]["conflicts"][0]["state"] ==
                "unknown"
            ),
            "CB-0045": (
                [e["kind"] for e in next(
                    s for s in incident["stages"] if s["name"] == "check"
                )["events"]] == ["success", "startup_failure"]
                and "application_state" not in incident
                and [e["kind"] for e in next(
                    s for s in draft["stages"] if s["name"] == "check"
                )["events"]] == ["skipped"]
            ),
        }
        return output.get(identifier) is True
    except (KeyError, TypeError, ValueError, IndexError, StopIteration):
        return False


def classify(identifier: str, rows: dict[str, tuple[str, ...]],
             docs: dict[str, str], data: dict) -> dict[str, str]:
    result = {k: UNKNOWN for k in ("local", "ci", "live", "production",
                                   "operating", "end_to_end")}
    row = rows.get(identifier)
    if identifier not in IDS or row is None or len(row) != 6:
        return result
    group = group_for(identifier)
    doc_path = DOCS[group]
    if (row[3] != "NO_VERIFICABLE" or not row[2]
            or doc_path not in row[1]
            or group not in docs or not docs[group]
            or group not in data or not isinstance(data[group], dict)):
        return result
    if supported(identifier, data):
        result["local"] = LOCAL
    return result


class BrechasControlbot03Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows, docs = read_sources()
        data = evidence()
        self.assertEqual(set(rows), set(IDS))
        for identifier in IDS:
            with self.subTest(clause=identifier):
                self.assertTrue(supported(identifier, data), identifier)
                verdict = classify(identifier, rows, docs, data)
                self.assertEqual(verdict["local"], LOCAL, identifier)
                self.assertEqual(rows[identifier][3], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        rows, docs = read_sources()
        data = evidence()
        for identifier in IDS:
            with self.subTest(clause=identifier):
                without = dict(rows)
                without.pop(identifier)
                self.assertEqual(
                    classify(identifier, without, docs, data)["local"], UNKNOWN
                )
                changed = dict(rows)
                c = list(rows[identifier])
                c[3] = "CUBIERTO"
                changed[identifier] = tuple(c)
                self.assertEqual(
                    classify(identifier, changed, docs, data)["local"], UNKNOWN
                )
                self.assertEqual(
                    classify(identifier, rows, {}, data)["local"], UNKNOWN
                )
                missing = copy.deepcopy(data)
                missing.pop(group_for(identifier))
                self.assertEqual(
                    classify(identifier, rows, docs, missing)["local"], UNKNOWN
                )
        fragments = {p: (ROOT / p).read_text(encoding="utf-8") for p in PARTS}
        with self.assertRaisesRegex(ValueError, "incomplete"):
            matrix_rows({PARTS[0]: fragments[PARTS[0]]})
        first = next(line for line in fragments[PARTS[0]].splitlines()
                     if line.startswith("| CB-0031 |"))
        with self.assertRaisesRegex(ValueError, "duplicated"):
            matrix_rows({**fragments, PARTS[0]: first + "\n" + fragments[PARTS[0]]})
        with self.assertRaisesRegex(ValueError, "malformed"):
            matrix_rows({**fragments, PARTS[0]: fragments[PARTS[0]].replace(
                first, "| CB-0031 | malformed |", 1,
            )})
        self.assertEqual(
            classify("CB-9999", rows, docs, data)["local"], UNKNOWN
        )

    def test_sin_datos_reales(self) -> None:
        data = evidence()
        for group in CASES:
            with self.subTest(group=group):
                self.assertTrue(data[group])
                for scenario_data in data[group].values():
                    rendered = json.dumps(scenario_data).lower()
                    for forbidden in ("\"password\"", "\"access_token\"",
                                      "\"api_key\"", "\"raw_payload\"",
                                      "\"credentials\"", "\"transcript\""):
                        self.assertNotIn(forbidden, rendered)
        self.assertTrue(all(evidence["rejected"]
                            for evidence in [data["core"]["sensitive"]]))
        self.assertEqual(data["core"]["purity"]["first"],
                         data["core"]["purity"]["second"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            own, r"(?m)^(?:from|import)\s+(?:requests|httpx|pymysql|socket|urllib)\b"
        )
        self.assertNotIn("-----BEGIN PRIVATE KEY-----", own)

    def test_estados_unknown_explicitos(self) -> None:
        rows, docs = read_sources()
        data = evidence()
        for identifier in IDS:
            with self.subTest(clause=identifier):
                verdict = classify(identifier, rows, docs, data)
                self.assertEqual(verdict["local"], LOCAL)
                for key in ("ci", "live", "production", "operating", "end_to_end"):
                    self.assertEqual(verdict[key], UNKNOWN, f"{identifier}/{key}")
        conflict = data["core"]["conflict"]
        self.assertEqual(conflict["conflicts"][0]["state"], "unknown")
        draft = next(s for s in data["lifecycle"]["pr86"]["stages"]
                     if s["name"] == "check")
        self.assertEqual([e["kind"] for e in draft["events"]], ["skipped"])
        self.assertFalse(data["aegis"]["verification"]
                         ["verified_without_evidence_rejected"] is False)


if __name__ == "__main__":
    unittest.main()
