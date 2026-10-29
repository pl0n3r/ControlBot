#!/usr/bin/env python3
"""ControlBot #1242: contratos ejecutables del Recovery y Replay UI.

Usa PHP real en scripts de escenarios con entradas sintéticas, sin red ni
secretos reales. No observa ni condiciona estados de matrices de cobertura.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
RECOVERY = "recovery_center_web_scenarios.php"
PORTFOLIO = "recovery_portfolio_scenarios.php"
REPLAY = "replay_ui_scenarios.php"
PROJECTS = (
    "autofactory", "brvtal", "condor", "controlbot",
    "factory", "factoryrunner", "grindflow",
)


def execute(script: str, *args: str) -> str:
    """Ejecuta la implementación PHP, no una copia de su comportamiento."""
    result = subprocess.run(
        ["php", str(ROOT / "tests" / script), *args],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
        timeout=30,
    )
    if result.returncode:
        raise AssertionError(
            f"Escenario sintético {script}/{args} falló: "
            f"{result.stderr[:250]}"
        )
    return result.stdout.strip()


def object_result(script: str, *args: str) -> dict:
    value = json.loads(execute(script, *args))
    if not isinstance(value, dict):
        raise AssertionError("Escenario sin objeto JSON")
    return value


class CoberturaControlbot59Tests(unittest.TestCase):
    """AC-01..04: ejecutar rutas PHP offline y sus fronteras de confianza."""

    def test_contrato_principal(self) -> None:
        # CB-0979/0980/0981: la ruta solo se abre tras autenticación
        # verdadera y usa el JSON local como objetivos, no medidas.
        owner = object_result(RECOVERY, "owner")
        self.assertEqual(owner["status"], 200)
        self.assertIn("text/html", owner["headers"]["Content-Type"])
        self.assertEqual(owner["headers"]["Cache-Control"], "no-store")
        self.assertIn("default-src 'none'",
                      owner["headers"]["Content-Security-Policy"])
        self.assertEqual(owner["headers"]["X-Frame-Options"], "DENY")
        page = owner["body"]
        for phrase in (
            "Security Center / Resilience", "Estado de Recovery",
            "UNKNOWN", "RPO objetivo", "RTO objetivo",
            "15 min", "60 min", "Estado por proyecto",
            "Sin evidencia Factory Recovery Health",
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, page)
        self.assertNotIn("HEALTHY", page)

        # CB-0987..0993: RecoveryPortfolio real acepta solo los
        # proyectos declarativos, mantiene UNKNOWN sin evidencia live
        # y no inventa targets para FactoryRunner.
        portfolio = object_result(PORTFOLIO, "baseline")
        self.assertIs(portfolio["execution"], False)
        self.assertEqual(portfolio["actions"], [])
        rows = portfolio["projects"]
        self.assertEqual(tuple(item["project"] for item in rows), PROJECTS)
        for item in rows:
            with self.subTest(project=item["project"]):
                self.assertEqual(item["dr_status"], "UNKNOWN")
                self.assertNotIn("observed_rpo", item)
                self.assertNotIn("backup_receipt", item)
                self.assertIn(item["profile_status"], ("configured", "unknown"))
        runner = rows[5]
        self.assertEqual(runner["reason"], "PROFILE_MISSING")
        self.assertEqual(runner["profile_status"], "unknown")
        self.assertIsNone(runner["rpo_target_minutes"])
        self.assertIsNone(runner["rto_target_minutes"])
        self.assertIn("PROFILE_MISSING", page)
        for name in PROJECTS:
            self.assertIn(f">{name}</td>", page)

        # CB-0994..0996: ReplayUi + Lifecycle reales generan HTML
        # read-only filtrado por categoría explícita, sin inferencias.
        replay = execute(REPLAY, "filters", "ci")
        self.assertIn("CI check evidence", replay)
        self.assertNotIn("Code commit evidence", replay)
        self.assertNotIn("Production deploy evidence", replay)
        self.assertIn("href=\"https://github.com/pl0n3r/ControlBot/actions/runs/123\"",
                      replay)
        for category in ("all", "code", "ci", "coordination",
                         "decisions", "production", "security"):
            self.assertIn(f"?category={category}", replay)
        selected = execute(REPLAY, "explicit", "security")
        excluded = execute(REPLAY, "explicit", "ci")
        self.assertIn("Explicit security category", selected)
        self.assertNotIn("Explicit security category", excluded)
        states = execute(REPLAY, "states")
        for name in ("skipped", "startup_failure", "failure", "success",
                     "unknown", "conflicting_evidence"):
            self.assertIn(name, states)
        self.assertNotIn("step_failure", states)

    def test_falla_cerrado(self) -> None:
        # CB-0979/0982: sin dueño verificado, POST o REMOTE_USER
        # falsificado nunca confieren autoridad.
        guards = object_result(RECOVERY, "guards")
        self.assertEqual(guards["anonymous"]["status"], 403)
        self.assertEqual(guards["post"]["status"], 405)
        self.assertNotEqual(guards["forged_user"]["status"], 200)
        for result in guards.values():
            self.assertNotIn("RPO objetivo", result["body"])

        invalid = object_result(RECOVERY, "invalid-profile")
        for result in invalid.values():
            self.assertEqual(result["status"], 503)
            self.assertEqual(result["body"], "Service unavailable.\n")
            self.assertNotIn("<script>", result["body"])

        # CB-0988/0989/0993: el proyector rechaza desajustes de
        # proyecto, campos sensibles y un perfil ficticio del runner.
        for name in ("cross-project", "sensitive", "extra-project",
                     "bad-shape", "unexpected-runner"):
            with self.subTest(portfolio=name):
                self.assertIs(object_result(PORTFOLIO, name)["rejected"], True)
        stale = object_result(PORTFOLIO, "stale")
        controlbot = next(row for row in stale["projects"]
                          if row["project"] == "controlbot")
        self.assertEqual(controlbot["profile_status"], "unknown")
        self.assertEqual(controlbot["dr_status"], "UNKNOWN")
        self.assertIsNone(controlbot["rpo_target_minutes"])

        # CB-0995/0996: contradicciones de categoría y material
        # sensible no pasan por la proyección HTML.
        self.assertIs(object_result(REPLAY, "contradictory")["rejected"], True)
        self.assertIs(object_result(REPLAY, "secret")["rejected"], True)
        invalid_filter = subprocess.run(
            ["php", str(ROOT / "tests" / REPLAY), "filters", "admin"],
            cwd=ROOT, capture_output=True, text=True, timeout=30,
            check=False,
        )
        self.assertNotEqual(invalid_filter.returncode, 0)

    def test_sin_datos_reales(self) -> None:
        # Comprobar la salida REAL de la ruta con fixtures offline.
        # No buscar ni imprimir credenciales del operador.
        owner = object_result(RECOVERY, "owner")
        html = owner["body"].lower()
        for forbidden in ("<script", "<form", "<button", "<iframe",
                          "api_key", "gho_offline_recovery_fixture"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, html)
        self.assertIn("no-store", owner["headers"]["Cache-Control"])
        self.assertIn("nosniff", owner["headers"]["X-Content-Type-Options"])
        self.assertIn("DENY", owner["headers"]["X-Frame-Options"])

        # El escape se hace en ReplayUi: no se interpola HTML externo.
        escaped = execute(REPLAY, "escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", escaped)
        self.assertNotIn("<script>alert(1)</script>", escaped)
        self.assertIs(object_result(REPLAY, "secret")["rejected"], True)
        # Category + source no implican acceso a una cuenta del mundo real.
        self.assertNotIn("<form", execute(REPLAY, "filters").lower())
        self.assertNotIn("<button", execute(REPLAY, "filters").lower())

    def test_gate_validar_presente(self) -> None:
        # Este test no suplanta a Actions. El resultado de "Validar"
        # debe acreditarse como check terminal del HEAD exacto del PR.
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("    name: Validar\n", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("phase: construccion", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)


if __name__ == "__main__":
    unittest.main()
