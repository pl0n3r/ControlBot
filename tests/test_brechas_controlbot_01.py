#!/usr/bin/env python3
"""ControlBot #1008: contrastes CB-0001..CB-0015 con fixtures offline AEGIS.

Las aserciones cubren comportamiento de las interfaces de AegisEvidence y
AegisRuntime. No afirman que exista backup/restore real, integración GitHub
autorizada, asesoría jurídica ni estado productivo GREEN.
"""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@lru_cache(maxsize=24)
def scenario(domain: str, name: str) -> dict:
    script = {
        "evidence": "aegis_evidence_scenarios.php",
        "runtime": "aegis_runtime_scenarios.php",
    }[domain]
    command = ["php", str(ROOT / "tests" / script), name]
    result = subprocess.run(
        command, cwd=ROOT, text=True, capture_output=True,
        timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(
            f"Escenario offline {domain}/{name} falló: {result.stderr}"
        )
    return json.loads(result.stdout)


def raw_evidence() -> dict:
    """Reconstruye el input público del fixture quitando campos de salida."""
    data = copy.deepcopy(scenario("evidence", "compliance"))
    for row in data["compliance"]:
        row.pop("reported_state", None)
    data["backup_evidence"].pop("reported_state", None)
    data["restore_evidence"].pop("reported_state", None)
    return data


def normalization_rejected(payload: dict) -> bool:
    """Ejercita el validador PHP real con JSON sintético por stdin."""
    program = (
        'require "src/AegisEvidence.php"; '
        '$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); '
        'try { \\ControlBot\\Security\\AegisEvidence::normalize($input); '
        'echo "accepted"; } '
        'catch (Throwable $e) { echo "rejected"; }'
    )
    result = subprocess.run(
        ["php", "-r", program], cwd=ROOT, input=json.dumps(payload),
        text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(f"PHP no ejecutó la guarda: {result.stderr}")
    if result.stdout not in ("accepted", "rejected"):
        raise AssertionError(f"Estado de prueba no reconocido: {result.stdout!r}")
    return result.stdout == "rejected"


class BrechasControlbot01Tests(unittest.TestCase):
    """Los 15 identificadores se evalúan individualmente, nunca en silencio."""

    def test_contrato_principal(self) -> None:
        evidence = scenario("evidence", "compliance")
        stale = scenario("evidence", "stale")
        continuity = scenario("evidence", "continuity")
        obligation = scenario("evidence", "obligation")
        deterministic = scenario("evidence", "deterministic")
        runtime = scenario("runtime", "workitem")
        owner = scenario("runtime", "owner")
        runtime_stale = scenario("runtime", "stale")
        item = runtime["work_item"]
        feedback = runtime["living_feedback"]
        required_obligation = {
            "obligation_id", "state", "reported_state", "scope",
            "evidence_refs", "source_ref", "observed_at", "freshness",
            "version", "owner_ref", "review_at", "expires_at",
        }
        continuity_fields = {
            "scope", "state", "reported_state", "evidence_refs",
            "source_ref", "observed_at", "freshness",
        }
        # Cada cláusula tiene un predicado propio sobre datos reales de los
        # escenarios PHP existentes, nunca solo una búsqueda de texto.
        verdicts = {
            "CB-0001": evidence["version"] == 1
                and bool(evidence["compliance"])
                and evidence["scope"] == "venture:condor",
            "CB-0002": not any(
                key in evidence for key in
                ("provider", "executor", "restore_action", "remediation")
            ),
            "CB-0003": all(
                row["scope"] == evidence["scope"]
                for row in evidence["compliance"]
            ) and all(
                evidence[key]["scope"] == evidence["scope"]
                for key in ("backup_evidence", "restore_evidence")
            ),
            "CB-0004": all(
                set(row) == required_obligation
                for row in evidence["compliance"]
            ),
            "CB-0005": (
                {row["state"] for row in evidence["compliance"]}
                == {"compliant", "gap"}
                and any(
                    row["state"] == "not_applicable"
                    for row in deterministic["first"]["compliance"]
                )
                and stale["compliance"][0]["state"] == "unknown"
            ),
            "CB-0006": all(
                row["expires_at"] >= row["review_at"]
                for row in obligation["compliance"]
            ),
            "CB-0007": (
                continuity["backup_evidence"]["state"] == "healthy"
            ),
            "CB-0008": (
                continuity["restore_evidence"]["reported_state"] == "verified"
                and continuity["restore_evidence"]["state"] == "unknown"
            ),
            "CB-0009": all(
                set(continuity[key]) == continuity_fields
                and continuity[key]["evidence_refs"]
                and isinstance(continuity[key]["observed_at"], int)
                for key in ("backup_evidence", "restore_evidence")
            ),
            "CB-0010": not any(
                key in evidence for key in
                ("legal_advice", "provider", "restore_performed", "postmortem")
            ),
            "CB-0011": all(
                key in runtime for key in
                ("work_item", "verification", "living_feedback", "owner_gate")
            ),
            "CB-0012": (
                item["origin_mode"] == "automatic"
                and item["origin_system"] == "aegis"
                and item["work_type"] == "infrastructure"
                and item["claims"] == ["aegis:venture:condor"]
                and bool(item["policy_ref"])
                and bool(item["evidence_refs"])
                and bool(item["idempotency_key"])
            ),
            "CB-0013": (
                runtime["ready_hint"] is True
                and all(
                    runtime_stale[key]["ready_hint"] is False
                    for key in ("stale", "unknown")
                )
            ),
            "CB-0014": (
                owner["ready_hint"] is False
                and owner["owner_gate"]["auto_execute"] is False
                and owner["work_item"]["approval_ref"]
                == owner["owner_gate"]["gate_ref"]
            ),
            "CB-0015": (
                feedback["kind"] == "lesson_candidate"
                and feedback["scope"] == "venture:condor"
                and feedback["authority_effect"] == "none"
                and feedback["policy_effect"] == "none"
                and feedback["freshness"] == "fresh"
                and feedback["evidence_refs"]
            ),
        }
        self.assertEqual(
            set(verdicts),
            {f"CB-{n:04d}" for n in range(1, 16)},
        )
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(passed), clause)

    def test_falla_cerrado(self) -> None:
        mutations = {
            "CB-0003/scope-ajeno": lambda p: p["compliance"][0].update(
                scope="project:other"
            ),
            "CB-0004/campo-no-permitido": lambda p: p["compliance"][0].update(
                payload={"synthetic": True}
            ),
            "CB-0005/estado-invalido": lambda p: p["compliance"][0].update(
                state="healthy"
            ),
            "CB-0006/expiracion-previa": lambda p: p["compliance"][0].update(
                expires_at=1
            ),
            "CB-0007/backup-invalido": lambda p: p["backup_evidence"].update(
                state="verified"
            ),
            "CB-0008/restore-invalido": lambda p: p["restore_evidence"].update(
                state="healthy"
            ),
            "CB-0009/evidencia-ausente": lambda p: p[
                "backup_evidence"
            ].update(evidence_refs=[]),
            "CB-0010/scope-sensible": lambda p: p.update(
                scope="venture:secret-fixture"
            ),
        }
        for clause, mutate in mutations.items():
            with self.subTest(clause=clause):
                payload = raw_evidence()
                mutate(payload)
                self.assertTrue(normalization_rejected(payload), clause)
        self.assertTrue(scenario("runtime", "timestamp")["rejected"])
        self.assertTrue(scenario("runtime", "secret")["rejected"])

    def test_sin_datos_reales(self) -> None:
        # Solo escenarios sintéticos; los valores PHP proyectados no contienen
        # material sensible o campos crudos ni activan proveedores externos.
        for domain, name in (
            ("evidence", "compliance"), ("evidence", "deterministic"),
            ("runtime", "workitem"), ("runtime", "living"),
        ):
            with self.subTest(domain=domain, name=name):
                rendered = json.dumps(scenario(domain, name)).lower()
                for prohibited in (
                    '"password"', '"api_key"', '"access_token"',
                    '"raw_payload"', '"provider"', '"executor"',
                    '"credentials"',
                ):
                    self.assertNotIn(prohibited, rendered)
        self.assertEqual(
            scenario("runtime", "living")["living_feedback"]["authority_effect"],
            "none",
        )

    def test_estados_unknown_explicitos(self) -> None:
        stale = scenario("evidence", "stale")["compliance"][0]
        self.assertEqual(stale["reported_state"], "compliant")
        self.assertEqual(stale["freshness"], "stale")
        self.assertEqual(stale["state"], "unknown")
        continuity = scenario("evidence", "continuity")
        self.assertEqual(continuity["backup_evidence"]["state"], "healthy")
        self.assertEqual(continuity["restore_evidence"]["state"], "unknown")
        self.assertNotEqual(
            continuity["restore_evidence"]["state"], "verified"
        )
        for kind in ("stale", "unknown"):
            with self.subTest(freshness=kind):
                runtime = scenario("runtime", "stale")[kind]
                self.assertEqual(runtime["source_freshness"], kind)
                self.assertFalse(runtime["ready_hint"])
                self.assertEqual(runtime["living_feedback"]["freshness"], kind)


if __name__ == "__main__":
    unittest.main()
