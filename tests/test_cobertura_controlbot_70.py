#!/usr/bin/env python3
"""ControlBot #1253: pruebas conductuales PHP con datos sintéticos.

CB-1189..1191: contratos offline del colector, snapshot y UI auténticos.
CB-1192: subcontrato sintético >400 señales; observación live es B/UNKNOWN.
Jamás leer estados de docs/matriz-cobertura*.md en ejecución.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "collector": ROOT / "tests/factory_orchestrator_evidence_collector_scenarios.php",
    "snapshot": ROOT / "tests/factory_orchestrator_snapshot_refresh_scenarios.php",
    "ui": ROOT / "tests/factory_orchestrator_live_ui_scenarios.php",
}
REAL_PHP = (
    ROOT / "src/FactoryOrchestratorEvidenceCollector.php",
    ROOT / "src/FactoryOrchestratorSnapshotRefresh.php",
    ROOT / "src/FactoryOrchestratorLiveUi.php",
)


@lru_cache(maxsize=12)
def scenario(kind: str, name: str) -> str:
    """Invoca clases PHP reales por escenarios inyectados, sin transporte de red."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    script = SCRIPTS[kind]
    for path in (script, *REAL_PHP):
        if not path.is_file():
            raise AssertionError(f"UNKNOWN: PHP source missing: {path.name}")
    with tempfile.TemporaryDirectory(prefix="cb1253-offline-") as home:
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C", "HOME": home}
        for key in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                    "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE"):
            if key in os.environ:
                env[key] = os.environ[key]
        result = subprocess.run(
            [php, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", "-d",
             "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
             str(script), name],
            cwd=ROOT, env=env, capture_output=True, text=True,
            timeout=50, check=False,
        )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: real PHP scenario failed: " + result.stderr[-500:])
    return result.stdout.strip()


def data(kind: str, name: str) -> dict:
    try:
        obj = json.loads(scenario(kind, name))
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid JSON from real PHP") from exc
    if not isinstance(obj, dict):
        raise AssertionError("UNKNOWN: PHP result is not an object")
    return obj


class CoberturaControlbot70Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01 CB-1189/1190/1191 + porción offline de CB-1192."""
        collected = data("collector", "over-400")
        self.assertEqual((collected["run"]["executed"], collected["run"]["state"]),
                         (True, "written"))
        summary = collected["summary"]
        self.assertIs(summary["truncated"], True)
        self.assertEqual(summary["reason"], "bounded_signal_budget")
        self.assertEqual(summary["omitted"],
                         {"blockers": 230, "owner_decisions": 0, "work": 256})
        self.assertEqual(collected["blockers"], 50)
        self.assertEqual(collected["work"], collected["fronts"])
        self.assertEqual(collected["fronts"], 24)
        self.assertEqual(collected["view_summary"], summary)
        total = collected["blockers"] + collected["work"] + sum(summary["omitted"].values())
        self.assertEqual(total, 560)
        self.assertGreater(total, 400)
        self.assertLessEqual(collected["run"]["requests"], 40)

        stale = data("snapshot", "stale_fallback")
        self.assertEqual(stale["status"], "stale")
        self.assertEqual(stale["reason"], "evidence_invalid")
        self.assertEqual(stale["last_success_at"], 220)
        self.assertEqual(stale["detected_at"], 1000)
        self.assertTrue(stale["visible_stale"])
        self.assertTrue(stale["visible_last_success"])
        self.assertTrue(stale["no_old_work"])
        self.assertEqual(stale["old_view_state"], "UNKNOWN")
        self.assertEqual(stale["old_view_fronts"], 0)
        self.assertEqual(stale["mode"], "0640")
        self.assertTrue(stale["bad_write_preserved"])

        truncated_html = scenario("ui", "truncated")
        self.assertIn('data-signal-truncated="true"', truncated_html)
        self.assertIn("bloqueos=230", truncated_html)
        self.assertIn("trabajo=256", truncated_html)
        self.assertIn("cobertura completa no están verificados", truncated_html)

    def test_falla_cerrado(self) -> None:
        """AC-02 no perder snapshot, elevar permisos ni falsear verde."""
        failed = data("collector", "failure")
        self.assertIs(failed["failed"], True)
        self.assertEqual(failed["previous"], '{"old":true}')
        negative = data("collector", "transport-negative-branches")
        self.assertEqual(len(negative["failed"]), 7)
        self.assertTrue(all(flag is True for flag in negative["failed"]))
        refresh = data("snapshot", "fail_closed")
        for key in ("collection_preserved", "sensitive_preserved",
                    "size_preserved", "invalid_type_preserved"):
            with self.subTest(key=key):
                self.assertIs(refresh[key], True)
        self.assertEqual(refresh["temporary_count"], 0)
        self.assertEqual(refresh["live_status"], "reserved")
        self.assertFalse(refresh["secret_echo"])
        self.assertEqual(refresh["messages"], ["Snapshot refresh failed."] * 4)
        self.assertEqual(scenario("ui", "invalid_summary"), "blocked")
        self.assertEqual(scenario("ui", "invalid"), "blocked")
        unknown = scenario("ui", "stale_unknown")
        self.assertIn('class="central state-unknown"', unknown)
        self.assertNotIn('class="central state-current"', unknown)

    def test_sin_datos_reales(self) -> None:
        """AC-03 fixtures artificiales y ausencia de secretos en salidas."""
        self.assertFalse(data("snapshot", "fail_closed")["secret_echo"])
        for output in (scenario("collector", "over-400"),
                       scenario("snapshot", "stale_fallback"),
                       scenario("ui", "truncated")):
            for secret in ("sentinel-read-value", "Bearer never print this secret",
                           "do-not-echo-secret", "do-not-echo-token"):
                with self.subTest(secret=secret):
                    self.assertNotIn(secret, output)
        self.assertEqual(data("snapshot", "stale_fallback")["bad_write_error"],
                         "Snapshot refresh failed.")
        # La observación de volumen real en producción NO se verifica offline.
        self.assertGreater(sum(data("collector", "over-400")["summary"]["omitted"].values()), 400)


if __name__ == "__main__":
    unittest.main()
