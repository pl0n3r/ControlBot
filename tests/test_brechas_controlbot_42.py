"""CB-0629..CB-0644: regresión sintética de observabilidad y LEX Core.

El contrato verifica comportamiento offline; NO acredita collectors, operación
productiva, aprobación jurídica, go-live ni evidencia D-043/D-059.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
INFRA_MATRIX = ROOT / "docs/matriz-cobertura-parte-03.md"
LEX_MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = (
    "CB-0629", "CB-0630", "CB-0631", "CB-0632", "CB-0633",
    "CB-0634", "CB-0636", "CB-0637", "CB-0638", "CB-0639",
    "CB-0640", "CB-0641", "CB-0642", "CB-0643", "CB-0644",
)


@lru_cache(maxsize=None)
def scenario(fixture: str, case: str) -> object:
    """Solo escenarios PHP locales, con fixtures sintéticos y sin red."""
    if fixture not in {
        "infrastructure_inventory_scenarios.php",
        "infrastructure_observation_scenarios.php",
        "infrastructure_ui_scenarios.php",
        "lex_core_scenarios.php",
    }:
        raise ValueError("fixture fuera del contrato")
    result = subprocess.run(
        ["php", str(TESTS / fixture), case],
        cwd=ROOT, capture_output=True, text=True, check=True, timeout=25,
    )
    return json.loads(result.stdout)


def infra(case: str) -> dict:
    return scenario("infrastructure_observation_scenarios.php", case)


def ui(case: str) -> dict:
    return scenario("infrastructure_ui_scenarios.php", case)


def lex(case: str) -> dict:
    return scenario("lex_core_scenarios.php", case)


class BrechasControlbot42Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """Cada cláusula dispone de una aserción ejecutable independiente."""
        fresh = infra("freshness")
        impact = infra("impact")
        scope_errors = infra("invalid")
        inventory = scenario("infrastructure_inventory_scenarios.php", "typed-resources")
        cockpit = ui("cockpit")
        ui_errors = ui("invalid")
        resources = {row["resource_id"]: row for row in cockpit["resources"]}
        database = resources["database-primary"]
        signals = database["signals"]
        registry = lex("registry")
        obligation = registry["obligations"][0]
        states = lex("states")
        statuses = {row["status"] for row in states["obligations"]}
        stale_legal = lex("freshness")
        legal_errors = lex("invalid")
        scope_keys = (
            "cross_scope_service_project", "cross_scope_service_venture",
            "cross_scope_service_environment", "cross_scope_environment_project",
            "cross_scope_environment_venture", "cross_scope_parent_project",
            "cross_scope_parent_venture", "ambiguous_environment_source",
        )
        checks = {
            # Sin evidencia completa no se certifica un estado saludable.
            "CB-0629": all(
                fresh[key] == "unknown" for key in
                ("stale_effective", "unknown_effective", "absent_effective")
            ),
            # La referencia de incidente se transporta, sin crear autoridad.
            "CB-0630": (
                signals["incident_refs"] ==
                ["https://github.com/pl0n3r/ControlBot/issues/190"]
                and ui_errors["caller_action"]
                and infra("invalid")["duplicate_incident"]
            ),
            # El grafo se recompone desde recursos y bindings con scope explícito.
            "CB-0631": (
                impact["other_resources"] == []
                and impact["database"]["capability_refs"] ==
                ["controlbot:capability/commerce"]
                and "controlbot:capability/other-venture" not in
                impact["graph"]["resource_impact"]["database-primary"]["capability_refs"]
            ),
            "CB-0632": all(scope_errors[key] for key in scope_keys),
            # Identidad y tipos de InfrastructureResource.
            "CB-0633": (
                len(inventory) == 8
                and len({r["resource_id"] for r in inventory}) == 8
                and {r["kind"] for r in inventory} == {
                    "environment", "service", "database", "storage",
                    "dns", "certificate", "backup", "network",
                }
            ),
            # Proyección read-only: ningún collector ni operación implícita.
            "CB-0634": (
                cockpit["execution"] is False
                and all(not r["actions"] for r in cockpit["resources"])
            ),
            "CB-0636": (
                database["state"]["observed_state"] == "degraded"
                and database["state"]["effective_state"] == "unknown"
                and database["state"]["freshness"] == "stale"
                and database["state"]["source_ref"] ==
                "controlbot:observation/database-primary"
                and resources["env-prod"]["state"]["effective_state"] == "unknown"
            ),
            "CB-0637": all(
                ui_errors[key] for key in (
                    "duplicate_observation", "orphan_observation",
                    "scalar_observation", "cross_scope",
                )
            ),
            "CB-0638": (
                signals["backup_freshness"]["state"] == "stale"
                and signals["restore_verification"]["state"] == "verified"
                and signals["cost_attribution"]["state"] == "attributed"
                and signals["release_drift"]["state"] == "drift"
                and isinstance(signals["incident_refs"], list)
            ),
            "CB-0639": (
                cockpit["execution"] is False and ui_errors["caller_action"]
                and all(r["actions"] == [] for r in cockpit["resources"])
            ),
            # LEX conserva trazabilidad y revisión humana, no aprobación global.
            "CB-0640": (
                obligation["source_refs"] ==
                ["controlbot:lex/source/a", "controlbot:lex/source/b"]
                and obligation["evidence_refs"] ==
                ["controlbot:lex/evidence/a", "controlbot:lex/evidence/b"]
                and obligation["human_review_required"] is True
                and "approved" not in registry["summary"]
            ),
            "CB-0641": (
                statuses == {"compliant", "gap", "unknown", "not_applicable"}
                and states["summary"]["counts"] == {
                    "compliant": 1, "gap": 1, "unknown": 1, "not_applicable": 1,
                }
            ),
            "CB-0642": all(
                row["status"] == "unknown" for row in stale_legal["obligations"]
            ),
            "CB-0643": all(
                "timestamps in the future" in legal_errors[key]
                for key in ("future_observed", "future_reviewed")
            ),
            "CB-0644": (
                "requires evidence" in legal_errors["compliant_without_evidence"]
                and "requires justification" in
                legal_errors["not_applicable_without_justification"]
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        for requirement, correct in checks.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(correct, "comportamiento no acreditado: " + requirement)

    def test_falla_cerrado(self):
        """Regresiones negativas: no derivar estado ni permisos de entradas ambiguas."""
        observation = infra("freshness")
        for key in ("stale_effective", "unknown_effective", "absent_effective"):
            with self.subTest(observation=key):
                self.assertEqual(observation[key], "unknown")
        errors = infra("invalid")
        for key in ("duplicate_binding", "dangling_service", "dangling_environment",
                    "ambiguous_environment_source"):
            with self.subTest(impact=key):
                self.assertTrue(errors[key])
        for key, rejected in ui("invalid").items():
            with self.subTest(ui=key):
                self.assertTrue(rejected)
        legal_errors = lex("invalid")
        for key in ("compliant_without_evidence",
                    "not_applicable_without_justification",
                    "future_observed", "future_reviewed", "sensitive"):
            with self.subTest(lex=key):
                self.assertTrue(legal_errors[key])
        self.assertEqual(
            {r["status"] for r in lex("freshness")["obligations"]},
            {"unknown"},
        )

    def test_sin_datos_reales(self):
        """Fixtures inyectados, IDs ficticios y cero llamadas de red."""
        for path in (
            "infrastructure_inventory_scenarios.php",
            "infrastructure_observation_scenarios.php",
            "infrastructure_ui_scenarios.php",
            "lex_core_scenarios.php",
        ):
            with self.subTest(path=path):
                source = (TESTS / path).read_text(encoding="utf-8")
                self.assertNotRegex(
                    source,
                    r"(?i)\bcurl_exec\s*\(|\bfile_get_contents\s*\(\s*['\"]https?://",
                )
        payload = json.dumps(
            [infra("impact"), ui("cockpit"), lex("registry")], ensure_ascii=False
        )
        self.assertNotRegex(
            payload, r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}"
        )
        self.assertNotRegex(
            payload, r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b"
        )
        self.assertNotRegex(
            payload, r"(?i)-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----"
        )
        self.assertFalse(ui("cockpit")["execution"])

    def test_estados_unknown_explicitos(self):
        """Matriz no promocionada: todas las filas carecen de prueba live."""
        raw = (
            INFRA_MATRIX.read_text(encoding="utf-8").splitlines()
            + LEX_MATRIX.read_text(encoding="utf-8").splitlines()
        )
        matches = {name: [] for name in IDS}
        for line in raw:
            if not line.startswith("| CB-"):
                continue
            cells = [part.strip() for part in line.strip().strip("|").split("|")]
            if cells[0] in matches:
                matches[cells[0]].append(cells)
        for name in IDS:
            with self.subTest(requirement=name):
                self.assertEqual(len(matches[name]), 1)
                row = matches[name][0]
                self.assertEqual(row[3], "NO_VERIFICABLE")
                self.assertIn("offline=unknown", row[4].lower())
                self.assertIn("live=unknown", row[4].lower())
                self.assertIn("operando=unknown", row[4].lower())
        self.assertEqual(infra("freshness")["absent_effective"], "unknown")
        self.assertEqual(
            {r["status"] for r in lex("freshness")["obligations"]},
            {"unknown"},
        )


if __name__ == "__main__":
    unittest.main()
