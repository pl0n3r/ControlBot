#!/usr/bin/env python3
"""ControlBot #1048: brechas CB-0613..CB-0628 (15 filas), pruebas offline.

El contrato valida PHP con fixtures sintéticos; no prueba ejecución real,
go-live, backup productivo, costes reales ni publicación de Factory@v1.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura-parte-03.md"
IDS = (
    "CB-0613", "CB-0614", "CB-0615", "CB-0616", "CB-0617",
    "CB-0618", "CB-0619", "CB-0620", "CB-0621", "CB-0622",
    "CB-0623", "CB-0625", "CB-0626", "CB-0627", "CB-0628",
)
SCRIPTS = {
    "intent": "infrastructure_intent_scenarios.php",
    "observation": "infrastructure_observation_scenarios.php",
}
UNKNOWN = "UNKNOWN"


@lru_cache(maxsize=None)
def scenario(group: str, case: str) -> dict:
    """Run only the explicitly allowlisted PHP fixtures, never provider I/O."""
    if group not in SCRIPTS or case not in {
        "safe", "gates", "runner", "provenance", "mutation",
        "freshness", "recovery-signals", "evidence-signals", "invalid",
    }:
        raise ValueError("Fixture no reconocido")
    allowed = {
        "intent": {"safe", "gates", "runner", "provenance", "mutation"},
        "observation": {"freshness", "recovery-signals", "evidence-signals", "invalid"},
    }
    if case not in allowed[group]:
        raise ValueError("Fixture fuera del dominio")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCRIPTS[group]), case],
        cwd=ROOT, text=True, capture_output=True, timeout=35, check=False,
    )
    if result.returncode:
        raise AssertionError(
            f"Fixture PHP {group}/{case} no verificable: {result.stderr[:500]}"
        )
    parsed = json.loads(result.stdout)
    if not isinstance(parsed, dict):
        raise AssertionError("Fixture PHP sin objeto JSON")
    return parsed


def matrix_rows(source: str) -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in source.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if cells[0] not in IDS:
            continue
        if cells[0] in rows or len(cells) < 6:
            raise ValueError("Fila repetida/malformada: " + cells[0])
        rows[cells[0]] = cells
    return rows


def compliance(rows: dict[str, list[str]], assertions: dict[str, bool],
               *, source_ready: bool = True) -> dict[str, str]:
    """NO_VERIFICABLE stays UNKNOWN if any local contract proof is missing."""
    result = {}
    for key in IDS:
        row = rows.get(key)
        if (not source_ready or row is None or len(row) < 6
                or not e3_coverage(row)
                or assertions.get(key) is not True):
            result[key] = UNKNOWN
        else:
            result[key] = "LOCAL_CONTRACT"
    return result


class BrechasControlbot41Tests(unittest.TestCase):
    """Four issue acceptance targets; no network or production evidence."""

    def evidence(self) -> tuple[dict[str, list[str]], dict[str, bool]]:
        safe = scenario("intent", "safe")
        gates = scenario("intent", "gates")
        mutation = scenario("intent", "mutation")
        runner = scenario("intent", "runner")
        provenance = scenario("intent", "provenance")
        freshness = scenario("observation", "freshness")
        recovery = scenario("observation", "recovery-signals")
        signals = scenario("observation", "evidence-signals")
        invalid = scenario("observation", "invalid")
        work = safe["work_item"]
        pre_order = safe["runner_request"]
        order = runner["order"]
        high = gates["high"]
        owner = gates["authority"]
        marker = high["owner_decision_gate"]
        approval = "controlbot:approval/infra-intent-high"
        observation_source = (
            ROOT / "src/InfrastructureObservation.php"
        ).read_text(encoding="utf-8")
        governance = (
            ROOT / "docs/infrastructure-governance.md"
        ).read_text(encoding="utf-8")
        all_keys = {
            "work_id", "origin_mode", "origin_system", "group_id", "work_type",
            "requested_capabilities", "required_roles", "authority_level",
            "producer_ref", "priority_class", "depends_on", "claims",
            "policy_ref", "evidence_refs", "idempotency_key",
            "venture_id", "project_id", "repository_ref",
        }
        checks = {
            "CB-0613": (
                mutation["missing_backup"]["status"] == "denied"
                and mutation["missing_backup"]["runner_request"] is None
                and "backup_required_without_safe_point"
                    in mutation["missing_backup"]["reasons"]
            ),
            "CB-0614": (
                gates["unknown"]["status"] == "denied"
                and gates["scope_mismatch"]["status"] == "denied"
                and gates["over_budget"]["status"] in ("denied", "owner_decision_required")
                and mutation["cost_unknown"]["status"] == "denied"
                and all(gates[name]["runner_request"] is None for name in
                        ("unknown", "scope_mismatch", "over_budget"))
            ),
            "CB-0615": (
                mutation["cost_unknown"]["status"] == "denied"
                and mutation["cost_unknown"]["runner_request"] is None
                and gates["over_budget"]["runner_request"] is None
                and "Una recomendación técnica nunca autoriza dinero."
                    in governance
            ),
            "CB-0616": (
                high["status"] == "owner_decision_required"
                and owner["status"] == "owner_decision_required"
                and high["runner_request"] is None
                and gates["unknown"]["status"] == "denied"
                and mutation["read_mutation_rejected"] is True
            ),
            "CB-0617": (
                safe["status"] == "planned"
                and all_keys <= set(work)
                and work["work_id"] == "infra-intent-189"
                and work["origin_system"] == "controlbot"
                and "runner_id" not in work
                and "provider_id" not in work
                and "state" not in work
            ),
            "CB-0618": (
                safe["status"] == "planned"
                and safe["execution"] is False
                and pre_order["work_item_id"] == work["work_id"]
                and pre_order["capability"] == safe["intent"]["capability"]
                and pre_order["scope"] == safe["intent"]["scope"]
                and "verify_after_write_ref" in pre_order
                and "runner_id" not in pre_order
                and "order_id" not in pre_order
            ),
            "CB-0619": (
                runner["safe"]["runner_request"] == pre_order
                and order["work_item_id"] == pre_order["work_item_id"]
                and order["runner_id"] == "33333333-3333-7333-8333-333333333333"
                and order["order_id"] == "11111111-1111-7111-8111-111111111111"
                and order["attempt"] == 1
                and order["generation"] == 1
                and order["expires_at"] == 2300
                and "runner_id" not in pre_order
            ),
            "CB-0620": (
                high["runner_request"] is None
                and high["work_item"]["approval_ref"] == approval
                and isinstance(marker, str)
                and '"safe_default":"B"' in marker
                and '"recommendation":"B"' in marker
                and '"approval_ref":"' + approval + '"' in marker
                and "factory-human-gate" in marker
                and owner["runner_request"] is None
                and "approval_ref" not in work
            ),
            "CB-0621": (
                runner["instruction_secret_rejected"] is True
                and runner["authority_secret_rejected"] is True
                and provenance["fabricated_array_rejected"] is True
                and provenance["copied_object_rejected"] is True
                and "secret" not in json.dumps(pre_order).lower()
                and "token" not in json.dumps(pre_order).lower()
            ),
            "CB-0622": (
                provenance["trusted_allow"]["status"] == "planned"
                and provenance["trusted_deny"]["status"] == "denied"
                and provenance["scope_mismatch"]["status"] == "denied"
                and provenance["context_copy_rejected"] is True
                and provenance["context_replay_rejected"] is True
                and provenance["projection_replay_rejected"] is True
                and "Factory Queue" in governance
                and "RunnerGateway" in governance
            ),
            "CB-0623": (
                safe["execution"] is False
                and safe["runner_request"] is not None
                and mutation["valid"]["execution"] is False
                and mutation["valid"]["evidence_contract"]["verify_ref"] is not None
                and mutation["valid"]["evidence_contract"]["safe_point_ref"] is not None
                and mutation["missing_verify_rejected"] is True
                and mutation["missing_recovery_rejected"] is True
                and "No existe deploy productivo" in governance
            ),
            "CB-0625": (
                "private const STATES = "
                "['online', 'degraded', 'offline', 'maintenance', 'unknown'];"
                    in observation_source
                and freshness["fresh"]["state"] == "online"
                and freshness["fresh_effective"] == "online"
            ),
            "CB-0626": (
                "private const FRESHNESS = ['fresh', 'stale', 'unknown'];"
                    in observation_source
                and freshness["fresh"]["freshness"] == "fresh"
                and freshness["stale_effective"] == "unknown"
                and freshness["unknown_effective"] == "unknown"
            ),
            "CB-0627": (
                freshness["fresh_effective"] == "online"
                and freshness["stale_effective"] == "unknown"
                and freshness["unknown_effective"] == "unknown"
                and freshness["absent_effective"] == "unknown"
            ),
            "CB-0628": (
                recovery["backup_freshness"]["state"] == "stale"
                and recovery["restore_verification"]["state"] == "verified"
                and signals["evidenced"]["cost_attribution"]["state"] == "attributed"
                and signals["evidenced"]["release_drift"]["state"] == "drift"
                and signals["unknown"]["cost_attribution"]["state"] == "unknown"
                and signals["unknown"]["release_drift"]["state"] == "unknown"
                and signals["cost_missing_evidence_blocked"] is True
                and signals["release_state_mismatch_blocked"] is True
                and invalid["sensitive_source"] is True
            ),
        }
        return matrix_rows(MATRIX.read_text(encoding="utf-8")), checks

    def test_contrato_principal(self) -> None:
        """AC-01: all 15 IDs exercise individual behaviors or fail closed."""
        rows, checks = self.evidence()
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual(set(checks), set(IDS))
        for key in IDS:
            with self.subTest(clause=key):
                self.assertTrue(e3_coverage(rows[key]))
                self.assertTrue(checks[key], "Contrato local no demostrado: " + key)
                self.assertEqual(compliance(rows, checks)[key], "LOCAL_CONTRACT")

    def test_falla_cerrado(self) -> None:
        """AC-02: no matrix row, failed proof, or lost source counts as PASS."""
        rows, checks = self.evidence()
        for key in IDS:
            with self.subTest(clause=key):
                missing = dict(rows)
                del missing[key]
                self.assertEqual(compliance(missing, checks)[key], UNKNOWN)
                failed = dict(checks)
                failed[key] = False
                self.assertEqual(compliance(rows, failed)[key], UNKNOWN)
                changed = dict(rows)
                changed[key] = [*rows[key]]
                changed[key][4] = "proof_invalid" if rows[key][3] == "CUBIERTO" else rows[key][4]
                if rows[key][3] != "CUBIERTO":
                    changed[key][3] = "CUBIERTO"
                self.assertEqual(compliance(changed, checks)[key], UNKNOWN)
        self.assertTrue(all(v == UNKNOWN for v in
                            compliance(rows, checks, source_ready=False).values()))
        gates = scenario("intent", "gates")
        mutation = scenario("intent", "mutation")
        self.assertEqual(gates["deny_owner"]["status"], "denied")
        self.assertIsNone(gates["deny_owner"]["runner_request"])
        for name in ("missing_plan_rejected", "missing_verify_rejected",
                     "missing_recovery_rejected", "read_mutation_rejected"):
            with self.subTest(case=name):
                self.assertTrue(mutation[name])
        with self.assertRaises(ValueError):
            scenario("intent", "provider-live")

    def test_sin_datos_reales(self) -> None:
        """AC-03: only local PHP, synthetic refs, zero external connectors."""
        for group, path in SCRIPTS.items():
            with self.subTest(group=group):
                self.assertTrue((ROOT / "tests" / path).is_file())
                code = (ROOT / "tests" / path).read_text(encoding="utf-8")
                self.assertNotRegex(code, r"(?i)\bcurl_exec\s*\(")
                self.assertNotRegex(code, r"(?i)\b(?:fsockopen|shell_exec|proc_open)\s*\(")
        self.assertFalse(scenario("intent", "safe")["execution"])
        self.assertNotIn(
            "runner_id", scenario("intent", "safe")["runner_request"]
        )
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertIn("controlbot:approval/infra-intent-high",
                      scenario("intent", "gates")["high"]["owner_decision_gate"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: LOCAL_CONTRACT never attests live/production or legal gates."""
        rows, checks = self.evidence()
        for key in IDS:
            with self.subTest(clause=key):
                self.assertTrue(e3_coverage(rows[key]))
                for label in ("Offline=UNKNOWN", "live=UNKNOWN", "operando=UNKNOWN"):
                    if label == "Offline=UNKNOWN" and rows[key][3] == "CUBIERTO":
                        continue
                    self.assertIn(label, rows[key][4])
                self.assertNotEqual(compliance(rows, checks)[key], "LIVE_PASS")
        self.assertEqual(
            scenario("observation", "freshness")["absent_effective"],
            "unknown",
        )
        self.assertEqual(
            scenario("observation", "evidence-signals")["unknown"]
            ["release_drift"]["state"], "unknown",
        )
        self.assertIsNone(scenario("intent", "gates")["high"]["runner_request"])


if __name__ == "__main__":
    unittest.main()
