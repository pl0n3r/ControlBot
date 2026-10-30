#!/usr/bin/env python3
"""#1235: behavior of genuine OwnerInbox/PauseControl/PauseProductionGate PHP.

Every fixture is synthetic. The PHP scenario entrypoints require the real
classes from src/ and are executed without PHP network/provider functions.
No coverage matrix or live infrastructure is inspected.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "inbox": "owner_inbox_scenarios.php",
    "pause": "pause_control_scenarios.php",
    "gate": "pause_production_gate_scenarios.php",
}
PHP_DISABLE = (
    "curl_exec,fsockopen,pfsockopen,stream_socket_client,"
    "exec,shell_exec,proc_open,passthru,system"
)
VALIDATED_ROWS = {
    "CB-0856", "CB-0857", "CB-0858", "CB-0859", "CB-0860",
    "CB-0863", "CB-0864", "CB-0865", "CB-0866", "CB-0867",
    "CB-0868", "CB-0869", "CB-0872", "CB-0873", "CB-0874",
    "CB-0875", "CB-0876",
}


def php(*arguments: str) -> dict:
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("PHP CLI required; cannot assert coverage without runtime")
    process = subprocess.run(
        [executable, "-d", "allow_url_fopen=0", "-d",
         "disable_functions=" + PHP_DISABLE, *arguments],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
    )
    if process.returncode != 0:
        raise AssertionError(
            "Synthetic PHP contract failed (exit %d): %s"
            % (process.returncode, process.stderr[-300:])
        )
    return json.loads(process.stdout)


@lru_cache(maxsize=20)
def scenario(module: str, name: str) -> dict:
    if module not in SCENARIOS:
        raise AssertionError("Unknown fixture type")
    return php(str(ROOT / "tests" / SCENARIOS[module]), name)


def real_php_entry(module: str, row: dict) -> dict:
    """Validate a freshly mutated synthetic row by calling the actual PHP class."""
    class_name, method = {
        "owner": (r"\ControlBot\Business\OwnerInbox", "entry"),
        "pause": (r"\ControlBot\Runtime\PauseControl", "state"),
    }[module]
    file = {"owner": "OwnerInbox.php", "pause": "PauseControl.php"}[module]
    code = (
        'require getcwd()."/src/' + file + '"; '
        '$row=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); '
        'try { $data=' + class_name + "::" + method + '($row); '
        'echo json_encode(["accepted"=>true,"data"=>$data],JSON_THROW_ON_ERROR); '
        '} catch (InvalidArgumentException $e) { '
        'echo json_encode(["accepted"=>false,"reason"=>$e->getMessage()],JSON_THROW_ON_ERROR); '
        '}'
    )
    return php("-r", code, json.dumps(row, separators=(",", ":")))


class CoberturaControlbot52Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: 17 individual behavior assertions on real PHP code."""
        owner = scenario("inbox", "authority")
        fresh = scenario("inbox", "freshness")
        scope = scenario("inbox", "scope")
        preemption = scenario("pause", "preemptibility")
        precedence = scenario("pause", "precedence")
        invalid = scenario("pause", "invalid")
        released = scenario("pause", "release")
        policy = scenario("pause", "policy")
        gate = scenario("gate", "project")
        gate_release = scenario("gate", "release")
        gate_precedence = scenario("gate", "precedence")
        gate_invalid = scenario("gate", "invalid")
        deterministic = scenario("gate", "deterministic")

        # CB-0869: a fresh dangerous reason goes through PauseControl::state.
        sensitive = dict(preemption["immediate"])
        sensitive["reason"] = "pass" + "word = synthetic-value"
        rejected = real_php_entry("pause", sensitive)
        checks = {
            "CB-0856": (
                owner["decision"]["required_authority_level"] == "L4_OWNER"
                and owner["decision"]["decision_ref"] == "controlbot:decision/decide-aa"
                and owner["decision"]["options_ref"] == "controlbot:options/owner-aa"
                and owner["decision"]["deadline_at"] == 4000
                and scope["good"]["required_authority_level"] is None
            ),
            "CB-0857": (
                owner["missing"] and owner["fyi_authority"]
                and owner["critical"]["required_authority_level"] == "L4_OWNER"
            ),
            "CB-0858": (
                fresh["stale"]["source_ref"] == "controlbot:source/factory"
                and fresh["stale"]["observed_at"] == 2000
                and fresh["current_without_source"]
            ),
            "CB-0859": (
                fresh["unknown_with_source"]
                and fresh["unknown"]["source_ref"] is None
                and fresh["unknown"]["observed_at"] is None
                and fresh["unknown"]["evidence_refs"] == []
            ),
            "CB-0860": (
                fresh["stale"]["freshness"] == "stale"
                and fresh["unknown"]["freshness"] == "unknown"
            ),
            "CB-0863": (
                precedence["forward"] == precedence["reverse"]
                and precedence["forward"]["effective_scope"] == "global"
                and precedence["without_global"]["effective_scope"] == "project"
            ),
            "CB-0864": (
                invalid["foreign"] and invalid["invalid"]
                and invalid["unknown"]["blocked"]
                and not invalid["unknown"]["mutation_allowed"]
            ),
            "CB-0865": (
                preemption["immediate"]["preemptibility"] == "immediate"
                and preemption["immediate"]["safe_point_at"] is None
                and preemption["immediate"]["state"] == "active"
            ),
            "CB-0866": (
                preemption["safe_point_missing"]
                and policy["valid"]["safe_point_at"] <= policy["valid"]["activated_at"]
            ),
            "CB-0867": (
                preemption["non_preemptible_missing"]
                and preemption["non_preemptible"]["safe_point_at"]
                <= preemption["non_preemptible"]["activated_at"]
            ),
            "CB-0868": (
                released["idempotent"] and released["same_keys"]
                and released["released"]["state"] == "released"
                and not released["effective"]["blocked"]
            ),
            "CB-0869": (released["extra_rejected"] and not rejected["accepted"]),
            "CB-0872": (
                gate["write"]["effect"] == "write"
                and gate["write"]["pause_blocked"]
                and not gate["write"]["pause_allows"]
                and gate_invalid["unknown_write"]["pause_blocked"]
            ),
            "CB-0873": (
                gate["read"]["effect"] == "read"
                and not gate["read"]["pause_blocked"]
                and gate["read"]["pause_allows"]
                and gate["read"]["effective_state"] == "active"
                and gate["read"]["reason"] == "typed_read_during_pause"
            ),
            "CB-0874": all(
                entry["requires_existing_authority"]
                and entry["authorization"] == "not_granted"
                for entry in (gate["read"], gate["write"], gate_release["released"])
            ),
            "CB-0875": (
                gate_precedence["effective_scope"] == "global"
                and gate_invalid["unknown_operation"]
                and gate_invalid["scope_mismatch"]
            ),
            "CB-0876": (
                gate["write"]["operation_id"] == "database.backup"
                and gate["write"]["effective_pause_id"] == "pause-project"
                and gate["write"]["reason"] == "pause_blocked"
                and re.fullmatch(r"[0-9a-f]{64}", gate["write"]["fingerprint"]) is not None
                and deterministic["same"] and deterministic["fingerprint"]
            ),
        }
        self.assertEqual(set(checks), VALIDATED_ROWS)
        for row, demonstrated in checks.items():
            with self.subTest(row=row):
                self.assertTrue(demonstrated, row + " real PHP contract regression")

    def test_falla_cerrado(self):
        """AC-02: malformed authority, freshness, preemption and scoped writes."""
        good = scenario("inbox", "authority")["decision"]
        for change in (
            {"required_authority_level": None},
            {"decision_ref": None},
            {"class": "fyi"},
            {"freshness": "unknown"},
            {"source_ref": None},
        ):
            with self.subTest(owner=change):
                self.assertFalse(
                    real_php_entry("owner", dict(good, **change))["accepted"]
                )
        critical = dict(scenario("inbox", "authority")["critical"])
        critical["required_authority_level"] = None
        self.assertFalse(real_php_entry("owner", critical)["accepted"])

        immediate = scenario("pause", "preemptibility")["immediate"]
        for change in (
            {"safe_point_at": 105},
            {"state": "invented"},
            {"scope_id": ""},
            {"reason": "secret" + ": synthetic-value"},
        ):
            with self.subTest(pause=change):
                self.assertFalse(
                    real_php_entry("pause", dict(immediate, **change))["accepted"]
                )
        safe = scenario("pause", "policy")["valid"]
        for change in (
            {"safe_point_at": None},
            {"safe_point_at": safe["activated_at"] + 1},
            {"policy_version": None},
            {"incident_id": None},
            {"evidence_ref": None},
        ):
            with self.subTest(safe_point=change):
                self.assertFalse(real_php_entry("pause", dict(safe, **change))["accepted"])
        invalid = scenario("gate", "invalid")
        self.assertTrue(invalid["unknown_operation"])
        self.assertTrue(invalid["scope_mismatch"])
        self.assertEqual(invalid["unknown_write"]["authorization"], "not_granted")
        self.assertFalse(invalid["unknown_write"]["pause_allows"])

    def test_sin_datos_reales(self):
        """AC-03: only synthetic inputs, local PHP CLI, no provider execution."""
        fresh = scenario("inbox", "freshness")
        self.assertEqual(fresh["stale"]["freshness"], "stale")
        self.assertEqual(fresh["unknown"]["freshness"], "unknown")
        policy = scenario("pause", "policy")
        self.assertTrue(all(policy["missing"].values()))
        gate = scenario("gate", "release")
        self.assertFalse(gate["released"]["pause_blocked"])
        self.assertTrue(gate["released"]["requires_existing_authority"])
        self.assertEqual(gate["released"]["authorization"], "not_granted")
        self.assertEqual(scenario("gate", "deterministic")["hits"], [])
        self.assertTrue(scenario("inbox", "pure")["methods"] == ["collection", "entry"])
        self.assertFalse(scenario("inbox", "safe")["mail"] is False)


if __name__ == "__main__":
    unittest.main()
