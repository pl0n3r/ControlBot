#!/usr/bin/env python3
"""CB-0334..CB-0348: offline contract checks; never a live-readiness claim.

The canonical coverage matrix is sparse. Missing rows stay UNKNOWN rather
than being invented or silently promoted to VERIFIED by a passing unit test.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import runpy
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(334, 349))
PRESENT = frozenset(("CB-0336", "CB-0344"))
UNKNOWN = "UNKNOWN"


@lru_cache(maxsize=24)
def scenario(script: str, name: str = "") -> object:
    """Run an existing PHP-only fixture, with no provider, credentials or I/O."""
    allowed = {
        "factory_recovery_rollout_scenarios.php",
        "finance_cost_center_scenarios.php",
    }
    if script not in allowed:
        raise ValueError("Unexpected offline fixture")
    argv = ["php", str(ROOT / "tests" / script)]
    if name:
        argv.append(name)
    result = subprocess.run(argv, cwd=ROOT, text=True, capture_output=True,
                            timeout=25, check=False)
    if result.returncode:
        raise AssertionError(f"Offline fixture {script}/{name} failed: {result.stderr[:200]}")
    return json.loads(result.stdout)


@lru_cache(maxsize=1)
def https_fixture() -> dict:
    """Reuse existing fake-sender test harness, not a real HTTP adapter."""
    return runpy.run_path(str(ROOT / "tests" / "test_github_readonly_https_transport.py"))


def matrix_states(text: str) -> dict[str, str]:
    rows: dict[str, list[list[str]]] = {clause: [] for clause in IDS}
    for line in text.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if cells and cells[0] in rows:
            rows[cells[0]].append(cells)
    states = {}
    for clause, found in rows.items():
        if len(found) != 1 or len(found[0]) != 6:
            states[clause] = UNKNOWN
        elif found[0][3] == "NO_VERIFICABLE" and "UNKNOWN" in found[0][4]:
            states[clause] = "NO_VERIFICABLE"
        else:
            states[clause] = UNKNOWN
    return states


def operational_state(_clause: str, _claim: object = None) -> str:
    """Synthetic tests, a CI receipt or historical restore cannot attest live DR."""
    return UNKNOWN


def checks() -> dict[str, bool]:
    fixture = https_fixture()
    run, reply, page, request = (fixture[name] for name in ("run", "reply", "page", "request"))
    bad_http = [run(enabled=True, replies=[reply("{}", status=status)])
                for status in (403, 404, 503)]
    redirected = run(enabled=True, replies=[reply("{}", redirected=True)])
    oversized = run(enabled=True, replies=[reply("{" + " " * 40000 + "}")])
    timeout = run(enabled=True, mode="timeout")
    invalid_host = run(enabled=True, req={**request(), "host": "bad-dns.invalid"})
    clean = run(enabled=True, replies=[page(items=[{"id": 7, "state": "closed"}])])
    raw_body = '{"private_body":"should-never-persist"}'
    sanitized = run(enabled=True, replies=[reply(raw_body)])
    blocked = bad_http + [redirected, oversized, timeout, invalid_host]

    recovery = scenario("factory_recovery_rollout_scenarios.php")
    profile, history, state = (recovery[key] for key in
                               ("profile", "legacy_restore", "recovery"))
    distinct = scenario("finance_cost_center_scenarios.php", "distinct")
    registry = scenario("finance_cost_center_scenarios.php", "canonical")
    cross = scenario("finance_cost_center_scenarios.php", "crossovers")
    attribution = scenario("finance_cost_center_scenarios.php", "attribution")
    rejected = scenario("finance_cost_center_scenarios.php", "unknown")
    legacy_contracts = history["canonical_contracts"]
    expected_registry = {"factory", "controlbot", "runner", "aegis", "momentum", "capital"}

    # Every ID evaluates a concrete behavior/property of an existing PHP path.
    return {
        "CB-0334": (len(bad_http) == 3 and all(
            x["out"]["status"] == UNKNOWN and x["out"]["projection"] is None
            for x in blocked) and not invalid_host["calls"]
            and len(timeout["calls"]) == 1),
        "CB-0335": (clean["out"]["status"] == "CURRENT"
            and clean["out"]["projection"]["kind"] == "offline_metadata_only"
            and "private_body" not in json.dumps(sanitized["out"])
            and "private_body" not in json.dumps(sanitized["audit"])
            and not any(field in json.dumps(clean["audit"]).lower()
                        for field in ("token", "cookie", "authorization", "response", "dns", "ip"))),
        "CB-0336": (recovery["profile_status"] == "configured"
            and profile["project_ref"] == "controlbot:project/project-factory"
            and state["dr_status"] == UNKNOWN),
        "CB-0337": (profile["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
            and profile["retention"] == {"hourly": 24, "daily": 7,
                                           "weekly": 8, "monthly": 12}
            and profile["restore_drill_cadence_hours"] == 168),
        "CB-0338": (profile["strategy"] == {
                "copies_required": 3, "media_types_required": 2,
                "offsite_required": True, "immutable_required": True,
                "undetected_restore_failures_target": 0}
            and profile["encryption_required"] is True
            and profile["source_ref"].endswith("/issues/462")
            and state["encryption"] == "unknown"),
        "CB-0339": (history["restored_from_external_copy"] is True
            and history["manifest"] == {"verified": 155, "total": 155}
            and history["source_sha"] == "19580a32fe504e74f4941bd64cefcc5b5a77d07a"
            and all(value is False for value in legacy_contracts.values())),
        "CB-0340": (recovery["profile_status"] == "configured"
            and state["canonical_backup_receipt"] == "unknown"
            and state["canonical_recovery_evidence"] == "unknown"
            and state["dr_status"] == UNKNOWN),
        "CB-0341": (state["canonical_restore_drill"] == "unknown"
            and legacy_contracts["restore_drill"] is False),
        "CB-0342": (state["legacy_external_restore"] == "verified"
            and state["dr_status"] == UNKNOWN and state["restorable"] is False
            and state["demonstrated_rto_minutes"] is None),
        "CB-0343": (profile["sources"] == {"database": "not_applicable",
                "media": "not_applicable", "repository": "required",
                "configuration": "required"}
            and all(state[key] == "unknown" for key in
                    ("canonical_backup_receipt", "canonical_recovery_evidence",
                     "canonical_restore_drill", "immutability"))
            and state["execution"] is False),
        "CB-0344": (distinct["venture"]["kind"] == "venture"
            and distinct["venture"]["scope"] == "venture:condor"
            and "mismatch" in cross["venture_as_institution_scope"]),
        "CB-0345": (distinct["institution"]["scope"] == "institution:factory"
            and {row["id"] for row in registry} == expected_registry
            and all(row["scope"] == "institution:" + row["id"] for row in registry)
            and "mismatch" in cross["institution_as_venture_scope"]),
        "CB-0346": (attribution["target_kind"] == "institution_cost_center"
            and attribution["target_scope"] == "institution:controlbot"
            and attribution["provenance_ref"] == "controlbot:finance/source-shared-ai"
            and distinct["venture"]["scope"].startswith("venture:")),
        "CB-0347": ("mismatch" in rejected["unknown_institution"]
            and "mismatch" in cross["reserved_id_as_venture"]),
        "CB-0348": ("mismatch" in rejected["wrong_title"]
            and "invalid" in rejected["unknown_kind"]
            and "mismatch" in cross["institution_as_venture_scope"]),
    }


class BrechasControlbot23Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: individual behavioral assertion for every one of 15 IDs."""
        evidence = checks()
        self.assertEqual(set(evidence), set(IDS))
        states = matrix_states((ROOT / "docs/matriz-cobertura.md").read_text())
        self.assertEqual(set(states), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(evidence[clause], clause)
                self.assertIn(states[clause], ("NO_VERIFICABLE", UNKNOWN))
                self.assertEqual(operational_state(clause, evidence[clause]), UNKNOWN)
        for clause in PRESENT:
            self.assertEqual(states[clause], "NO_VERIFICABLE")
        for clause in set(IDS) - PRESENT:
            self.assertEqual(states[clause], UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absent, duplicate and falsely green coverage rows fail closed."""
        original = (ROOT / "docs/matriz-cobertura.md").read_text()
        for clause in PRESENT:
            row = next(line for line in original.splitlines()
                       if line.startswith("| " + clause + " |"))
            for corrupted in (original.replace(row + "\n", "", 1),
                              original + "\n" + row,
                              original.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(corrupted)[clause], UNKNOWN)
        http = https_fixture()
        for altered in ({**http["request"](), "method": "POST"},
                        {**http["request"](), "now": 1200},
                        {**http["request"](), "resource": "secrets"}):
            denied = http["run"](enabled=True, req=altered)
            self.assertEqual(denied["out"]["status"], UNKNOWN)
            self.assertIsNone(denied["out"]["projection"])
            self.assertFalse(denied["calls"])
        self.assertEqual(operational_state("CB-0342", {"restore": "verified"}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fake sender, read-only scenarios and scrubbed audit."""
        fixture = https_fixture()
        success = fixture["run"](enabled=True)
        self.assertEqual({call["method"] for call in success["calls"]}, {"GET"})
        self.assertTrue(all(call["mode"] == "offline_test_only" for call in success["calls"]))
        for state in (success["out"], success["audit"]):
            serialized = json.dumps(state).lower()
            for forbidden in ("password", "token", "cookie", "authorization", "private_body",
                              "response_body", "dns_address", "access_token"):
                self.assertNotIn(forbidden, serialized)
        for path in ("factory_recovery_rollout_scenarios.php", "finance_cost_center_scenarios.php"):
            source = (ROOT / "tests" / path).read_text().lower()
            for forbidden in ("curl_exec(", "fsockopen(", "new pdo", "mysqli", "shell_exec("):
                self.assertNotIn(forbidden, source)
        self.assertFalse(scenario("factory_recovery_rollout_scenarios.php")["recovery"]["execution"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: incomplete/offline evidence cannot become GREEN or authorized."""
        matrix = matrix_states((ROOT / "docs/matriz-cobertura.md").read_text())
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertNotEqual(matrix[clause], "CUBIERTO")
                for false_claim in (None, {}, True, "success", "GREEN",
                                    {"ci": "success"}, {"drill": "verified"},
                                    {"merged_pr": True}, {"production": "healthy"}):
                    self.assertEqual(operational_state(clause, false_claim), UNKNOWN)
        recovery = scenario("factory_recovery_rollout_scenarios.php")["recovery"]
        self.assertEqual(recovery["dr_status"], UNKNOWN)
        self.assertEqual(recovery["canonical_restore_drill"], "unknown")
        self.assertIs(recovery["restorable"], False)
        self.assertIs(recovery["execution"], False)


if __name__ == "__main__":
    unittest.main()
