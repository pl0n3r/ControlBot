#!/usr/bin/env python3
"""ControlBot #1021: contraste offline CB-0197..0211, sin red ni datos reales.

Las pruebas ejercitan escenarios PHP sintéticos existentes. Un caso sin fila
canónica o sin evidencia operacional permanece UNKNOWN, incluso si un fixture
local pasa: esto NO prueba HEALTH, ownership vivo, release o go-live.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRICES = (
    ROOT / "docs" / "matriz-cobertura.md",
    ROOT / "docs" / "matriz-cobertura-parte-01.md",
)
IDS = tuple(f"CB-{n:04d}" for n in range(197, 212))
UNKNOWN = "UNKNOWN"
FIXTURES = frozenset({
    "dynamic_capacity_e2e_scenarios.php",
    "executive_cockpit_scenarios.php",
    "executive_cockpit_ui_scenarios.php",
    "runtime_capacity_signal_scenarios.php",
    "owner_inbox_scenarios.php",
    "agent_runtime_scenarios.php",
    "recovery_work_origin_scenarios.php",
})


def run_scenario(file: str, mode: str) -> str:
    """Strict allowlist: no shell, network, secrets or user-provided commands."""
    if file not in FIXTURES or not mode.replace("-", "").replace("_", "").isalnum():
        raise AssertionError("Escenario fuera de la allowlist offline")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / file), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(f"Escenario {file}/{mode} no validado")
    return result.stdout


@lru_cache(maxsize=25)
def scenario(file: str, mode: str) -> dict:
    output = json.loads(run_scenario(file, mode))
    if not isinstance(output, dict):
        raise AssertionError("La evidencia del fixture debe ser un objeto")
    return output


def matrix_states(content: str) -> dict[str, str]:
    """Fila ausente, ambigua, duplicada o promovida => UNKNOWN."""
    states = {}
    for identifier in IDS:
        rows = [line for line in content.splitlines()
                if line.startswith(f"| {identifier} |")]
        if len(rows) != 1:
            states[identifier] = UNKNOWN
            continue
        cells = [cell.strip() for cell in rows[0].strip().strip("|").split("|")]
        states[identifier] = (
            "NO_VERIFICABLE" if len(cells) == 6 and cells[0] == identifier
            and cells[3] == "NO_VERIFICABLE"
            and "Offline=UNKNOWN" in cells[4]
            and "live=UNKNOWN" in cells[4]
            else UNKNOWN
        )
    return states


def operational_state(_id: str, _fixture: object = None) -> str:
    """Un fixture offline nunca certifica ejecución ni seguridad live."""
    return UNKNOWN


class BrechasControlbot14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: predicado de comportamiento o brecha explícita por ID."""
        dyn = scenario("dynamic_capacity_e2e_scenarios.php", "lifecycle")
        cock = "executive_cockpit_scenarios.php"
        base = scenario(cock, "base")
        order = scenario(cock, "order")
        scope = scenario(cock, "scope")
        stale = scenario(cock, "stale")
        dup = scenario(cock, "duplicate")
        ui = "executive_cockpit_ui_scenarios.php"
        html = run_scenario(ui, "base")
        stale_html = run_scenario(ui, "stale")
        unsafe = scenario(ui, "unsafe")
        runtime = "runtime_capacity_signal_scenarios.php"
        sources = scenario(runtime, "sources")
        states = scenario(runtime, "states")
        owner = scenario("owner_inbox_scenarios.php", "authority")
        handoff = dyn["handoff"]
        recovery = "recovery_work_origin_scenarios.php"
        work = scenario(recovery, "valid")
        recovery_unknown = scenario(recovery, "unknown")
        # UNKNOWN es explícito para contratos sin fila ni fixture suficiente;
        # no se registra como aceptación funcional ni como certificación live.
        verdicts = {
            "CB-0197": (
                scenario(recovery, "order-a")["idempotency_key"]
                == scenario(recovery, "order-b")["idempotency_key"]
                and work["idempotency_key"].startswith("recovery:rto_breached:")
            ),
            "CB-0198": (
                all(scenario(recovery, name)["blocked"] for name in (
                    "healthy", "class-absent", "unknown-class",
                    "expanded-health", "incoherent-drill", "sensitive-health",
                ))
                and recovery_unknown["project_id"] == "controlbot"
                and all(k not in recovery_unknown
                        for k in ("ready", "approved", "authorized"))
            ),
            "CB-0199": all(
                dyn[key]["idle_capacity"] == 0
                and dyn[key]["sessions"][0]["assignment_id"] == "assignment-217"
                and dyn[key]["sessions"][0]["work_item"] == "pl0n3r/ControlBot#217"
                and dyn["dispatch"][key]["dispatchable_capacity"] == 0
                for key in ("rate_limited", "stale")
            ),
            "CB-0200": (
                handoff["from_session_id"] == "session-a"
                and handoff["to_session_id"] == "session-b"
                and handoff["assignment_id"] == "assignment-217"
                and handoff["next_action"] == "Continue with session-b generation 2"
            ),
            "CB-0201": (
                all(dyn[k]["capacity_state"] == "degraded"
                    for k in ("rate_limited", "stale"))
                and states["unknown"]["state"] == "unknown"
                and states["unknown"]["total_capacity"] is None
            ),
            "CB-0202": (
                dyn["readiness"]["duplicate_owners_rejected"] is True
                and dyn["readiness"]["current"]["reservation_owner"] == "session-b"
                and dyn["readiness"]["stale_owner"]["ready"] is False
            ),
            "CB-0203": (
                run_scenario(cock, "base") == run_scenario(cock, "base")
                and scenario(recovery, "order-a")["observed_at"]
                == "2026-09-29T20:00:00Z"
                and base["ventures"][0]["business_health"]["observed_at"] == 1000
            ),
            "CB-0204": (
                [v["venture"]["venture_id"] for v in order["ventures"]]
                == ["venture-alpha", "venture-beta"]
                and dup["duplicate"] is True
                and "score" not in json.dumps(order).lower()
                and "traffic_light" not in json.dumps(order).lower()
            ),
            "CB-0205": (
                stale["ventures"][0]["business_health"]["freshness"] == "stale"
                and stale["ventures"][0]["technical_health"]["state"] == "unknown"
                and stale["ventures"][0]["technical_health"]["freshness"] == "unknown"
            ),
            "CB-0206": (
                sources["same_keys"] is True
                and sources["auto"]["source"] == "autofactory"
                and sources["runner"]["source"] == "factoryrunner"
            ),
            "CB-0207": (
                all(scope.values())
                and dup["duplicate"] is True
            ),
            "CB-0208": (
                "Business health" in stale_html
                and "freshness stale" in stale_html
                and "freshness unknown" in stale_html
                and "<form" not in html.lower()
                and "<button" not in html.lower()
            ),
            "CB-0209": (
                all(unsafe.values())
                and "controlbot:decision/decide-aa" in html
                and "<script" not in html.lower()
            ),
            "CB-0210": (
                all(label in html for label in ("FYI", "WATCH", "DECISION", "CRITICAL"))
                and owner["decision"]["required_authority_level"] == "L4_OWNER"
                and owner["critical"]["class"] == "critical"
                and owner["missing"] is True
                and owner["fyi_authority"] is True
            ),
            "CB-0211": (
                "<form" not in html.lower()
                and "<button" not in html.lower()
                and all(unsafe.values())
                and all(token not in (ROOT / "src" / "ExecutiveCockpitUi.php")
                        .read_text(encoding="utf-8").lower()
                        for token in ("scheduler", "factoryrunner::", "enqueue(",
                                      "dispatch(", "decisionrights::", "curl_"))
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                self.assertIn(verdicts[identifier], (True, UNKNOWN))
        self.assertFalse({key for key, value in verdicts.items()
                          if value == UNKNOWN}, "El comportamiento sin evidencia debe documentarse")

    def test_falla_cerrado(self) -> None:
        """AC-02: no promover filas ausentes, duplicadas o imprecisas."""
        blank = matrix_states("")
        self.assertEqual(blank, dict.fromkeys(IDS, UNKNOWN))
        id_ = "CB-0199"
        row = (f"| {id_} | synthetic-ref | synthetic requirement | "
               "NO_VERIFICABLE | Offline=UNKNOWN; live=UNKNOWN | synthetic |")
        self.assertEqual(matrix_states(row)[id_], "NO_VERIFICABLE")
        self.assertEqual(matrix_states(row + "\n" + row)[id_], UNKNOWN)
        self.assertEqual(matrix_states(row.replace("Offline=UNKNOWN", "Offline=PASS"))[id_], UNKNOWN)
        self.assertEqual(matrix_states(row.replace("NO_VERIFICABLE", "VERIFICADO"))[id_], UNKNOWN)
        self.assertEqual(operational_state(id_, {"fixture": "success"}), UNKNOWN)
        self.assertTrue(all(scenario("executive_cockpit_ui_scenarios.php", "unsafe").values()))

    def test_sin_datos_reales(self) -> None:
        """AC-03: sintético y rechazo explícito de secretos/PII."""
        tests = scenario("runtime_capacity_signal_scenarios.php", "secrets")
        self.assertTrue(all(tests.values()))
        handoff = scenario("agent_runtime_scenarios.php", "handoff")
        self.assertTrue(handoff["no_transcript"] and handoff["secret"])
        self.assertTrue(handoff["sensitive_evidence"])
        self.assertTrue(handoff["external_evidence"])
        unsafe = scenario("executive_cockpit_ui_scenarios.php", "unsafe")
        self.assertTrue(unsafe["secret"] and unsafe["pii"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: matriz parcial y offline nunca se elevan a GREEN/live."""
        states = matrix_states("\n".join(
            path.read_text(encoding="utf-8") for path in MATRICES
        ))
        self.assertEqual(set(states), set(IDS))
        self.assertEqual(set(states.values()), {"NO_VERIFICABLE"})
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                self.assertEqual(operational_state(identifier, states[identifier]), UNKNOWN)
        self.assertEqual(matrix_states("")["CB-0208"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
