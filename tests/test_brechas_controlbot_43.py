#!/usr/bin/env python3
"""ControlBot #1050: contraste offline CB-0645..CB-0659 (LEX).

Una regresión técnica no es una decisión jurídica, evidencia de producción ni
permiso para operar. Los fixtures PHP se ejecutan localmente sin proveedores.
"""
from __future__ import annotations

import copy
import hashlib
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "runtime": "lex_runtime_scenarios.php",
    "gate": "lex_gate_scenarios.php",
    "core": "lex_core_scenarios.php",
    "watch": "lex_watch_scenarios.php",
    "jurisdiction": "lex_jurisdiction_scenarios.php",
}


def scenario(domain: str, name: str) -> dict:
    """Ejercita el PHP real con entradas sintéticas y sin I/O de red."""
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCENARIOS[domain]), name],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
    )
    if result.returncode:
        raise AssertionError(
            f"fixture {domain}/{name} falló: {result.stderr[:400]}"
        )
    return json.loads(result.stdout)


def gate_input() -> dict:
    """Objeto mínimo válido; sin decisiones ni identidad reales."""
    return {
        "version": 1,
        "kind": "material_uncertainty",
        "gap_id": "privacy-transfer-basis",
        "scope": "venture:condor",
        "question": "¿Existe una base jurídica verificada para este scope?",
        "freshness": "fresh",
        "severity": "high",
        "policy_ref": "controlbot:policy/lex-fixture",
        "evidence_refs": ["controlbot:lex/evidence-fixture"],
        "observed_at": 1200,
        "work_type": "compliance_review",
        "requested_capabilities": ["legal_review"],
        "required_roles": ["legal_privacidad"],
        "group_id": "pl0n3r",
        "venture_id": "condor",
        "project_id": "condor_colombia",
        "repository_ref": "pl0n3r/Condor",
        "authority_level": "l4_owner",
        "producer_ref": "controlbot:lex/engine",
    }


def gate_accepted(payload: dict) -> bool:
    """Valida límites con el LexGate PHP, no con una regex duplicada."""
    code = r"""
require 'src/LexGate.php';
$input=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    \ControlBot\Legal\LexGate::evaluate($input, 1500);
    echo 'ACCEPT';
} catch (Throwable $e) {
    echo 'REJECT';
}
"""
    result = subprocess.run(
        ["php", "-r", code], input=json.dumps(payload),
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stdout not in ("ACCEPT", "REJECT"):
        raise AssertionError(f"LexGate no verificable: {result.stderr[:300]}")
    return result.stdout == "ACCEPT"


class BrechasControlbot43Tests(unittest.TestCase):
    """Quince IDs, quince predicados observables sobre el runtime real."""

    def test_contrato_principal(self) -> None:
        flow = scenario("runtime", "flow")
        global_scope = scenario("runtime", "global")
        authority = scenario("runtime", "authority")
        insufficient = scenario("runtime", "insufficient")
        states = scenario("core", "states")
        watch = scenario("watch", "fail_closed")
        gate = scenario("gate", "gate")
        work = scenario("gate", "work")
        stale = scenario("gate", "stale")
        idem = scenario("gate", "idempotency")
        pack = scenario("jurisdiction", "co")
        item = work["work_item"]
        expected_key = "lex-gap:" + hashlib.sha256(
            "\n".join((
                "venture:condor", "privacy-notice-update",
                "compliance_review", "controlbot:policy/lex-colombia-v1",
            )).encode("utf-8")
        ).hexdigest()

        invalid_schema = copy.deepcopy(gate_input())
        invalid_schema["unrecognized"] = True
        long_gap = copy.deepcopy(gate_input())
        long_gap["gap_id"] = "x" * 141
        long_scope = copy.deepcopy(gate_input())
        long_scope["scope"] = "venture:" + "a" * 90
        long_ref = copy.deepcopy(gate_input())
        long_ref["policy_ref"] = "controlbot:" + "x" * 250

        verdicts = {
            # Esquema cerrado + IDs, scopes y refs acotados.
            "CB-0645": gate_accepted(gate_input())
                and all(not gate_accepted(v) for v in (
                    invalid_schema, long_gap, long_scope, long_ref,
                )),
            # Market -> pack -> core -> WorkItem -> evidencia -> core.
            "CB-0646": flow["market"]["jurisdictions"] == ["country:CO"]
                and [p["pack_id"] for p in flow["selected_packs"]] == ["pack-co-v1"]
                and flow["before"]["summary"]["counts"]["gap"] == 1
                and flow["gate"]["work_item"] is not None
                and flow["after"]["summary"]["counts"]["compliant"] == 1
                and flow["reevaluation_state"] == "compliant",
            # Sin proveedor, red o ejecución; mismo input -> mismo resultado.
            "CB-0647": not authority["provider_executed"]
                and authority["factory_authority"] == "unchanged"
                and authority["authority_effect"] == "none"
                and scenario("core", "deterministic")["same"],
            # Global no es jurisdicción implícita o lista vacía.
            "CB-0648": global_scope["runtime"]["market"]["mode"] == "global"
                and global_scope["runtime"]["market"]["jurisdictions"]
                    == ["country:CO", "country:MX"]
                and len(global_scope["runtime"]["selected_packs"]) == 2
                and global_scope["empty_blocked"],
            # Estados legales exactos, no colapsar unknown/NA en compliant.
            "CB-0649": {
                row["status"] for row in states["obligations"]
            } == {"compliant", "gap", "unknown", "not_applicable"},
            # ControlBot proyecta evidencia y decisiones, no la ejecuta.
            "CB-0650": bool(flow["gate"]["work_item"])
                and flow["gate"]["human_gate"] is None
                and flow["after"]["obligations"][0]["evidence_refs"]
                    == ["controlbot:lex/evidence/privacy"]
                and authority["gate"]["auto_execute"] is False,
            # Rumor, source unknown y stale no son revisión aprobada.
            "CB-0651": len(watch["signals"]) == 3
                and not watch["review_candidates"]
                and all(row["publication_state"] == "unknown"
                    and row["effective_state"] == "unknown"
                    and not row["review_candidate"]
                    for row in watch["signals"]),
            # UNKNOWN, sin evidencia y stale jamás son PASS/compliant.
            "CB-0652": insufficient["missing_evidence_blocked"]
                and insufficient["stale"]["reevaluation_state"] == "unknown"
                and insufficient["empty"]["reevaluation_state"] == "unknown",
            # Pregunta jurídica específica, limitada al scope bloqueado.
            "CB-0653": gate["human_gate"]["category"] == "legal"
                and gate["human_gate"]["blocks"] == "venture:condor"
                and "transferencia internacional" in
                    gate["human_gate"]["title_simple"].lower()
                and "venture:condor" in gate["human_gate"]["context"],
            # B es default, no es una aprobación por omisión.
            "CB-0654": gate["human_gate"]["safe_default"] == "B"
                and gate["human_gate"]["recommendation"] == "B"
                and "bloqueado" in
                    gate["human_gate"]["options"][1]["effect"],
            # Clave estable derivada de scope+gap+tipo+policy.
            "CB-0655": item["idempotency_key"] == expected_key
                and idem["first"]["work_item"] == idem["second"]["work_item"]
                and len(idem["batch"]) == 1,
            # Fresh autoriza una pista local, nunca ejecución.
            "CB-0656": work["freshness"] == "fresh"
                and work["ready_hint"] is True
                and work["auto_execute"] is False,
            # Stale/unknown no son pistas de readiness.
            "CB-0657": all(
                stale[k]["ready_hint"] is False
                and stale[k]["auto_execute"] is False
                for k in ("stale", "unknown")
            ),
            # Puerta humana bloquea incluso con evidencia fresh.
            "CB-0658": gate["freshness"] == "fresh"
                and gate["ready_hint"] is False
                and gate["human_gate"] is not None
                and gate["auto_execute"] is False,
            # Paquete versionado: solo metadata, sin autorización legal.
            "CB-0659": pack["schema_version"] == 1
                and pack["pack_version"] == "1.0.0"
                and pack["jurisdiction"] == "country:CO"
                and pack["evidence_state"] == "current"
                and bool(pack["sources"]) and bool(pack["controls"])
                and all(c["human_review_required"] for c in pack["controls"])
                and not any(k in pack for k in
                    ("legal_approval", "compliant", "permission_to_operate")),
        }
        self.assertEqual(set(verdicts), {
            f"CB-{index:04d}" for index in range(645, 660)
        })
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(passed), clause)

    def test_falla_cerrado(self) -> None:
        invalid = scenario("core", "invalid")
        self.assertIn("fields invalid", invalid["extra"])
        self.assertIn("requires evidence", invalid["compliant_without_evidence"])
        self.assertIn("requires justification",
                      invalid["not_applicable_without_justification"])
        self.assertIn("invalid", invalid["sensitive"])
        jurisdiction = scenario("jurisdiction", "invalid")
        for key in ("missing_field", "unknown_source", "future_review",
                    "non_https_source", "kind_mismatch"):
            with self.subTest(guard=key):
                self.assertNotEqual(jurisdiction[key], "accepted")
        secret_ref = gate_input()
        secret_ref["policy_ref"] = "controlbot:policy/api-token-value"
        self.assertFalse(gate_accepted(secret_ref))
        no_policy = gate_input()
        no_policy.pop("policy_ref")
        self.assertFalse(gate_accepted(no_policy))

    def test_sin_datos_reales(self) -> None:
        """No usar red, provider, credenciales, decisiones ni datos de clientes."""
        for domain, name in (
            ("runtime", "authority"), ("runtime", "flow"),
            ("gate", "gate"), ("gate", "work"),
            ("jurisdiction", "generic"), ("watch", "fail_closed"),
        ):
            with self.subTest(domain=domain, name=name):
                payload = json.dumps(scenario(domain, name)).lower()
                for forbidden in (
                    '"password"', '"api_key"', '"access_token"',
                    '"credentials"', '"raw_payload"', '"legal_approval"',
                ):
                    self.assertNotIn(forbidden, payload)
        self.assertEqual(
            scenario("jurisdiction", "generic")["sources"][0]["uri"],
            "https://example.invalid/legal-source",
        )
        self.assertFalse(scenario("runtime", "authority")["provider_executed"])

    def test_estados_unknown_explicitos(self) -> None:
        """NO_VERIFICABLE de cláusulas pendientes no se transforma en GREEN."""
        unknown = scenario("runtime", "insufficient")
        self.assertEqual(unknown["stale"]["reevaluation_state"], "unknown")
        self.assertEqual(unknown["empty"]["reevaluation_state"], "unknown")
        self.assertNotEqual(unknown["stale"]["reevaluation_state"], "compliant")
        for row in scenario("core", "freshness")["obligations"]:
            self.assertEqual(row["status"], "unknown")
        for pack in scenario("jurisdiction", "freshness").values():
            self.assertEqual(pack["evidence_state"], "not_current")
            self.assertTrue(pack["reasons"])
        for source in ("stale", "unknown"):
            self.assertFalse(scenario("gate", "stale")[source]["ready_hint"])
        # La matriz pertenece a otra hoja; estos docs siguen delimitando
        # toda inferencia de autorización o cumplimiento productivo.
        e2e = (ROOT / "docs" / "lex-e2e.md").read_text(encoding="utf-8")
        gates = (ROOT / "docs" / "lex-gates.md").read_text(encoding="utf-8")
        jurisdiction = (ROOT / "docs" / "lex-jurisdictions.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("UNKNOWN", e2e)
        self.assertIn("Pending nunca equivale a aprobación", gates)
        self.assertIn("No interpreta leyes", jurisdiction)


if __name__ == "__main__":
    unittest.main()
