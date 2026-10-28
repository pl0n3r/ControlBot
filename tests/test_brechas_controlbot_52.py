"""CB-0790..CB-0804: contraste offline de Observability UI, V3 caller y backup.

Los resultados de fixtures no acreditan colectores productivos, backups reales,
24 horas / 100 ciclos, permisos de release, D-059 ni go-live.
"""

from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BACKUP = ROOT / "ops/factory/backup"
CALLER = ROOT / ".github/workflows/observador-reservas-consumidor.yml"
RUNBOOK = ROOT / "docs/observador-reservas-consumidor.md"
PREFLIGHT_DOC = ROOT / "docs/ops/factory-backup-preflight.md"
UI_DOC = ROOT / "docs/observability-ui-v1.md"
MATRICES = (
    ROOT / "docs/matriz-cobertura.md",
    ROOT / "docs/matriz-cobertura-parte-03.md",
)
IDS = tuple(f"CB-{number:04d}" for number in range(790, 805))


def synthetic(**overrides: object) -> dict[str, object]:
    result: dict[str, object] = {
        "kind": "synthetic",
        "source_sha": "a" * 40,
        "artifact_sha256": "b" * 64,
        "baseline_verified": True,
        "artifact_verified": True,
        "rollback_verified": True,
    }
    result.update(overrides)
    return result


def preflight(
    payload: object | None = None, *, arguments: tuple[str, ...] = ("--offline-dry-run",),
    raw: str | None = None, cwd: Path = ROOT,
) -> tuple[subprocess.CompletedProcess[str], dict[str, object]]:
    document = json.dumps(synthetic() if payload is None else payload) if raw is None else raw
    process = subprocess.run(
        [sys.executable, str(BACKUP), *arguments],
        cwd=cwd, input=document, text=True, capture_output=True,
        timeout=15, check=False,
    )
    response = json.loads(process.stdout)
    return process, response


def ui(case: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests/observability_ui_scenarios.php"), case],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=True,
    )
    return result.stdout


class BrechasControlbot52Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """Quince cláusulas, quince predicados con evidencia offline concreta."""
        caller = CALLER.read_text(encoding="utf-8")
        runbook = RUNBOOK.read_text(encoding="utf-8")
        doc = PREFLIGHT_DOC.read_text(encoding="utf-8")
        ui_contract = UI_DOC.read_text(encoding="utf-8")
        source = BACKUP.read_text(encoding="utf-8")
        successful, receipt = preflight()
        pure = json.loads(ui("pure"))
        redaction = json.loads(ui("redaction"))
        missing = ui("missing")
        unknown = ui("unknown_probe")
        invalid_mode, denied = preflight(arguments=("--live",))
        bad_baseline, baseline = preflight(synthetic(baseline_verified=False))
        checks = {
            # Afirmaciones de UI read-only y proyección sin nuevos side-effects.
            "CB-0790": (
                pure == {"hits": [], "form": False, "button": False, "input": False}
                and "Cuatro rutas aditivas" in ui_contract
                and "Monitor: unknown" in missing
            ),
            # Caller GET-only: cron, despacho manual, reusable y permisos mínimos.
            "CB-0791": (
                bool(re.search(r"cron: '\*/10 \* \* \* \*'", caller))
                and "workflow_dispatch:" in caller
                and "cancel-in-progress: false" in caller
                and "observador-reservas-consumidor.yml@v1" in caller
                and len(re.findall(r"(?m)^    uses: ", caller)) == 1
                and len(re.findall(r"(?m)^\s*(contents|issues|pull-requests|checks|actions): read$", caller)) == 10
                and "FACTORY_LEASE_AUTO_RELEASE_ENABLED='false'" in runbook
            ),
            # Una ejecución verde no prueba 24 h + 100 ciclos ni concede release.
            "CB-0792": all(s in runbook for s in (
                "24 horas", "100 ciclos terminales", "cero falsos positivos",
                "CAS/lock", "UNKNOWN", "OFF",
            )),
            # Reversión del caller aislada, sin cambiar Factory@v1 o Hostinger.
            "CB-0793": (
                "Reversión: revertir el PR" in runbook
                and ".github/workflows/observador-reservas-consumidor.yml" in runbook
                and "Factory@v1" in runbook
                and "secrets:" not in caller
                and "run:" not in caller
            ),
            # El contrato solo acredita fixture sintético, jamás respaldo real.
            "CB-0794": (
                successful.returncode == 0
                and receipt["mode"] == "offline_preflight"
                and receipt["status"] == "SYNTHETIC_ONLY"
                and "evaluación de fixtures sintéticos" in doc
            ),
            "CB-0795": (
                receipt["backup_verified"] is False
                and receipt["live_authorized"] is False
                and "recibo de seguridad productivo" in doc
                and "Hostinger" in doc
            ),
            "CB-0796": (
                "AC-04 de #760" in doc
                and "señales productivas reales" in doc
                and receipt["readiness"] == "UNKNOWN"
            ),
            "CB-0797": (
                set(receipt) == {
                    "mode", "status", "reason", "backup_verified",
                    "live_authorized", "readiness",
                }
                and (receipt["backup_verified"], receipt["live_authorized"],
                     receipt["readiness"]) == (False, False, "UNKNOWN")
            ),
            "CB-0798": (
                invalid_mode.returncode == 2
                and denied["status"] == "REJECTED"
                and denied["reason"] == "mode_not_authorized"
                and denied["readiness"] == "UNKNOWN"
            ),
            "CB-0799": (
                successful.returncode == 0
                and receipt["reason"] == "synthetic_checks_passed"
                and successful.stderr == ""
                and successful.stdout.count("\n") == 1
                and receipt["live_authorized"] is False
            ),
            # Código bajo prueba: únicos imports stdlib permitidos, sin acceso externo.
            "CB-0800": (
                {alias.name for node in ast.walk(ast.parse(source))
                 if isinstance(node, ast.Import) for alias in node.names}
                == {"json", "re", "sys"}
                and not any(isinstance(node, ast.ImportFrom) and node.module != "__future__"
                            for node in ast.walk(ast.parse(source)))
            ),
            "CB-0801": (
                "subprocess" not in source
                and "socket" not in source
                and "urllib" not in source
                and "os.system" not in source
                and "Nunca inicia jobs" in doc
                and all(redaction.values())
            ),
            # No convertir un valor reportado por caller en estado de producción.
            "CB-0802": (
                "No habilitar `DOMAIN`, `DEPLOY_ENABLED`" in doc
                and "no genera ni escribe receipts" in doc
                and receipt["backup_verified"] is False
                and receipt["readiness"] == "UNKNOWN"
            ),
            "CB-0803": (
                "identidad de artefacto" in doc
                and "autoridad D-059" in doc
                and bad_baseline.returncode == 2
                and baseline["reason"] == "baseline_unverified"
            ),
            "CB-0804": (
                "backup/rollback verificables" in doc
                and "owner-only autenticada" in doc
                and "consentimiento" in doc
                and "controles de fase" in doc
                and "Sin restore drill verificado." in missing
                and "Restorable</dt><dd>no" in missing
                and "Monitor: unknown" in unknown
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        for requirement, ok in checks.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(ok, "sin evidencia suficiente: " + requirement)

    def test_falla_cerrado(self):
        """Modes, permisos, datos y recibos ambiguos nunca producen éxito."""
        cases = [
            ("not-synthetic", synthetic(kind="production"), None, "invalid_synthetic_fixture"),
            ("missing-baseline", {k: v for k, v in synthetic().items()
                                   if k != "baseline_verified"}, None, "invalid_synthetic_fixture"),
            ("string-bool", synthetic(rollback_verified="true"), None, "invalid_synthetic_fixture"),
            ("extra-path", synthetic(backup_path="/tmp/backup"), None, "invalid_synthetic_fixture"),
            ("bad-source", synthetic(source_sha="main"), None, "invalid_synthetic_fixture"),
            ("bad-artifact", synthetic(artifact_sha256="main"), None, "invalid_synthetic_fixture"),
            ("baseline-false", synthetic(baseline_verified=False), None, "baseline_unverified"),
            ("artifact-false", synthetic(artifact_verified=False), None, "artifact_unverified"),
            ("rollback-false", synthetic(rollback_verified=False), None, "rollback_unverified"),
            ("duplicate", None, '{"kind":"synthetic","kind":"synthetic"}', "invalid_synthetic_fixture"),
            ("oversized", None, "x" * 8193, "invalid_synthetic_fixture"),
            ("malformed", None, "{", "invalid_synthetic_fixture"),
            ("empty", None, "", "invalid_synthetic_fixture"),
        ]
        for name, payload, raw, reason in cases:
            with self.subTest(case=name):
                result, state = preflight(payload, raw=raw)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(state["reason"], reason)
                self.assertEqual(state["status"], "REJECTED")
                self.assertIs(state["backup_verified"], False)
                self.assertIs(state["live_authorized"], False)
                self.assertEqual(state["readiness"], "UNKNOWN")
                self.assertEqual(result.stderr, "")
        for flags in ((), ("--real",), ("--host",), ("--offline-dry-run", "--live")):
            with self.subTest(flags=flags):
                result, state = preflight(arguments=flags)
                self.assertEqual((result.returncode, state["reason"]),
                                 (2, "mode_not_authorized"))
        self.assertIn("Monitor: unknown", ui("missing"))
        self.assertNotIn("Monitor: healthy", ui("missing"))

    def test_sin_datos_reales(self):
        """No PII, red, secretos ni escrituras en el ejecutable probado."""
        with tempfile.TemporaryDirectory(prefix="cb1059-synthetic-") as tmp:
            isolated = Path(tmp)
            result, data = preflight(cwd=isolated)
            self.assertEqual(result.returncode, 0)
            self.assertEqual(data["status"], "SYNTHETIC_ONLY")
            self.assertEqual(list(isolated.iterdir()), [])
            secret_marker = "fake-sentinel-do-not-echo"
            result, denied = preflight(synthetic(api_token=secret_marker), cwd=isolated)
            self.assertEqual((result.returncode, denied["reason"]),
                             (2, "invalid_synthetic_fixture"))
            self.assertNotIn(secret_marker, result.stdout + result.stderr)
            self.assertEqual(list(isolated.iterdir()), [])
        source = BACKUP.read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?i)\b(?:requests|urllib|socket|subprocess|paramiko|boto3|pymysql|mysql)\b")
        self.assertTrue(all(json.loads(ui("redaction")).values()))
        workflow = CALLER.read_text(encoding="utf-8")
        self.assertNotRegex(workflow, r"(?m)^\s*[^#\n]+: write$")
        self.assertNotIn("secrets:", workflow)
        self.assertNotIn("gh api", workflow)

    def test_estados_unknown_explicitos(self):
        """Matriz sigue NO_VERIFICABLE; no se certifica go-live por CI."""
        observed = {name: [] for name in IDS}
        for path in MATRICES:
            for line in path.read_text(encoding="utf-8").splitlines():
                if line.startswith("| CB-"):
                    fields = [x.strip() for x in line.strip().strip("|").split("|")]
                    if fields[0] in observed:
                        observed[fields[0]].append(fields)
        for name, rows in observed.items():
            with self.subTest(requirement=name):
                self.assertEqual(len(rows), 1)
                self.assertEqual(rows[0][3], "NO_VERIFICABLE")
                for fragment in ("offline=unknown", "live=unknown", "operando=unknown"):
                    self.assertIn(fragment, rows[0][4].lower())
        _, output = preflight()
        self.assertEqual(output["readiness"], "UNKNOWN")
        self.assertIs(output["live_authorized"], False)
        self.assertIn("UNKNOWN", RUNBOOK.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
