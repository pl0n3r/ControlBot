#!/usr/bin/env python3
"""ControlBot #1084: contratos sintéticos CB-1177..CB-1191.

La matriz sigue NO_VERIFICABLE. Tres escenarios de collector/snapshot/UI
tienen prueba LOCAL_PARCIAL; toda aceptación integral, HEALTH, despliegue,
decisión del dueño y observación productiva permanecen UNKNOWN.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{i:04d}" for i in range(1177, 1192))
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
LOCAL = "LOCAL_PARCIAL"
CLAUSES = {
    "CB-1177": (567, "Cada nueva señal declara fuente, timestamp, freshness y semántica."),
    "CB-1178": (567, "Departamentos sin fuente siguen UNKNOWN con razón visible."),
    "CB-1179": (567, "Ninguna métrica proxy se presenta como salud departamental sin contrato explícito."),
    "CB-1180": (567, "Tests cubren fuente presente, ausente y stale."),
    "CB-1181": (625, "Poner ControlBot en línea en control.condorapp.com.co y empezar la migración de chats (solo lectura)"),
    "CB-1182": (750, "Todos los criterios macro de #1 están cubiertos por Issues completados o bloqueos explícitos."),
    "CB-1183": (750, "#625 entrega una instancia observable/autenticada y reproducible."),
    "CB-1184": (750, "FactoryRunner/AutoFactory dependencies tienen estado conocido y no se simulan."),
    "CB-1185": (750, "Recovery, observabilidad, seguridad y rollback tienen evidencia fresca."),
    "CB-1186": (750, "No existe P0/P1 conocido sin Issue."),
    "CB-1187": (750, "Go-live requiere decisión explícita del owner y se verifica separadamente del deploy."),
    "CB-1188": (751, "roadmap: Hostinger HTTPS Transport V1 — bloqueado hasta evidencia técnica habilitante"),
    "CB-1189": (760, "tests/factory_orchestrator_evidence_collector_scenarios.php::más de 50 señales no abortan la colecta y marcan truncated con conteo omitido"),
    "CB-1190": (760, "tests/factory_orchestrator_snapshot_refresh_scenarios.php::una colecta fallida escribe snapshot stale con motivo y último éxito"),
    "CB-1191": (760, "tests/factory_orchestrator_live_ui_scenarios.php::la UI muestra stale/truncated con motivo en lugar de UNKNOWN genérico"),
}
PART4_IDS = {
    "CB-1178", "CB-1179", "CB-1180", "CB-1183", "CB-1184",
    "CB-1185", "CB-1186", "CB-1187", "CB-1190", "CB-1191",
}
LOCAL_IDS = {"CB-1189", "CB-1190", "CB-1191"}
FIXTURES = {
    "collector": "tests/factory_orchestrator_evidence_collector_scenarios.php",
    "refresh": "tests/factory_orchestrator_snapshot_refresh_scenarios.php",
    "ui": "tests/factory_orchestrator_live_ui_scenarios.php",
}
GAPS = {
    "CB-1177": "UNKNOWN: ninguna fuente departamental productiva contrastada",
    "CB-1178": "UNKNOWN: falta vista con razón de ausencia por departamento",
    "CB-1179": "UNKNOWN: no se certifica un proxy como salud departamental",
    "CB-1180": "UNKNOWN: falta el fixture departamental presente/ausente/stale",
    "CB-1181": "UNKNOWN: falta despliegue owner-only y migración autorizada",
    "CB-1182": "UNKNOWN: macro-DoD #1 no acreditada exhaustivamente",
    "CB-1183": "UNKNOWN: #625 no tiene recibo productivo exact-SHA",
    "CB-1184": "UNKNOWN: dependencias externas no observadas realmente",
    "CB-1185": "UNKNOWN: restore, rollback, observabilidad y seguridad operativas sin recibo",
    "CB-1186": "UNKNOWN: inventario vivo P0/P1 no comprobado offline",
    "CB-1187": "UNKNOWN: aprobación humana de go-live separada de deploy",
    "CB-1188": "UNKNOWN: transporte Hostinger sin habilitación técnica/live",
    "CB-1189": "UNKNOWN: volumen real >50 en producción no observado",
    "CB-1190": "UNKNOWN: fallo de colecta y cron reales no observados",
    "CB-1191": "UNKNOWN: panel desplegado y autenticado no observado",
}


def load_matrices() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in (MAIN, PART4)}


def matrix_rows(documents: object) -> dict[str, tuple[str, ...]]:
    """Cada ID existe exactamente una vez, en su partición y fuente reales."""
    if (type(documents) is not dict or set(documents) != {MAIN, PART4}
            or any(type(documents[p]) is not str or not documents[p]
                   for p in (MAIN, PART4))):
        raise ValueError(UNKNOWN + ": matriz no disponible")
    found: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART4):
        for line in documents[path].splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if not match or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(v.strip() for v in line.strip("|").split("|"))
            number, clause = CLAUSES[key]
            expected_path = PART4 if key in PART4_IDS else MAIN
            if (key in found or path != expected_path or len(cells) != 6
                    or cells[0] != key or f"/issues/{number})" not in cells[1]
                    or cells[2] != clause
                    or cells[3] != "NO_VERIFICABLE"
                    or "Aceptación integral o evidencia terminal no comprobada" not in cells[4]
                    or any(x not in cells[4].lower() for x in
                           ("offline=unknown", "live=unknown", "operando=unknown"))
                    or f"/issues/{number})" not in cells[5]):
                raise ValueError(UNKNOWN + ": fila inválida/duplicada " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": faltan filas " + str(set(IDS) - set(found)))
    return found


def safe_env(home: str) -> dict[str, str]:
    """El subprocess no hereda tokens, proxies, PII ni configuración productiva."""
    return {"PATH": os.defpath, "HOME": home, "LANG": "C",
            "APP_ENV": "testing"}


@lru_cache(maxsize=8)
def php_fixture(group: str, scenario: str) -> object:
    allow = {
        "collector": {"over-400"},
        "refresh": {"stale_fallback"},
        "ui": {"truncated", "stale_unknown", "invalid_summary"},
    }
    if group not in allow or scenario not in allow[group]:
        raise ValueError(UNKNOWN + ": fixture no autorizado")
    php = shutil.which("php")
    if php is None:
        raise AssertionError(UNKNOWN + ": PHP CLI ausente")
    with tempfile.TemporaryDirectory(prefix="controlbot-1084-") as home:
        result = subprocess.run(
            [php, str(ROOT / FIXTURES[group]), scenario],
            cwd=ROOT, env=safe_env(home), capture_output=True,
            text=True, check=False, timeout=40,
        )
    if result.returncode != 0:
        raise AssertionError(UNKNOWN + ": fixture falló " + group + "/" + scenario
                             + " (exit=" + str(result.returncode) + ")")
    if group == "ui":
        return result.stdout
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError(UNKNOWN + ": respuesta inválida") from exc
    if type(data) is not dict:
        raise AssertionError(UNKNOWN + ": evidencia no estructurada")
    return data


def probe_local() -> dict[str, bool]:
    """Tres aserciones independientes del comportamiento PHP ya versionado."""
    collector = php_fixture("collector", "over-400")
    summary = collector["summary"]
    cb1189 = (
        collector["run"]["executed"] is True
        and collector["run"]["state"] == "written"
        and summary == collector["view_summary"]
        and summary["truncated"] is True
        and summary["reason"] == "bounded_signal_budget"
        and summary["omitted"] == {"blockers": 230, "owner_decisions": 0, "work": 256}
        and collector["blockers"] == 50 and collector["work"] == 24
        and collector["run"]["requests"] <= 40
    )
    stale = php_fixture("refresh", "stale_fallback")
    cb1190 = (
        stale["status"] == "stale"
        and stale["reason"] == "evidence_invalid"
        and stale["visible_stale"] is True
        and stale["visible_last_success"] is True
        and stale["no_old_work"] is True
        and stale["old_view_state"] == UNKNOWN
        and stale["old_view_fronts"] == 0
    )
    rendered = php_fixture("ui", "truncated")
    unknown = php_fixture("ui", "stale_unknown")
    blocked = php_fixture("ui", "invalid_summary")
    cb1191 = (
        'data-signal-truncated="true"' in rendered
        and "bloqueos=230" in rendered and "trabajo=256" in rendered
        and "cobertura completa no están verificados" in rendered
        and '<article class="central state-unknown"' in unknown
        and '<article class="central state-current"' not in unknown
        and blocked == "blocked"
    )
    return {"CB-1189": cb1189, "CB-1190": cb1190, "CB-1191": cb1191}


def evidence(key: object, documents: object, probes: object) -> str:
    """Prueba local parcial o brecha explícita; nunca health/go-live."""
    if (type(key) is not str or key not in IDS
            or type(probes) is not dict):
        return UNKNOWN
    try:
        rows = matrix_rows(documents)
    except ValueError:
        return UNKNOWN
    if key not in rows or key not in GAPS or UNKNOWN not in GAPS[key]:
        return UNKNOWN
    if key in LOCAL_IDS:
        return LOCAL if probes.get(key) is True else UNKNOWN
    return GAP


def operational(_key: object, _attestation: object = None) -> str:
    """No existe recibo de D-043, D-059, live, owner ni operación real."""
    return UNKNOWN


class BrechasControlbot77Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.documents = load_matrices()
        cls.rows = matrix_rows(cls.documents)

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.rows), set(IDS))
        self.assertEqual(set(GAPS), set(IDS))
        self.assertEqual(len(IDS), 15)
        checks = probe_local()
        self.assertEqual(set(checks), LOCAL_IDS)
        self.assertTrue(all(value is True for value in checks.values()))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(self.rows[key][3], "NO_VERIFICABLE")
                self.assertIn(UNKNOWN, GAPS[key])
                self.assertEqual(evidence(key, self.documents, checks),
                                 LOCAL if key in LOCAL_IDS else GAP)
                self.assertEqual(operational(key), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            original_path = PART4 if key in PART4_IDS else MAIN
            original = next(
                line for line in self.documents[original_path].splitlines()
                if line.startswith("| " + key + " |")
            )
            for malformed in (
                self.documents[original_path].replace(original, "", 1),
                self.documents[original_path] + "\n" + original,
                self.documents[original_path].replace(
                    original, original.replace("NO_VERIFICABLE", "VERIFICADO", 1), 1),
            ):
                docs = {**self.documents, original_path: malformed}
                with self.subTest(row=key):
                    if original in malformed:
                        with self.assertRaisesRegex(ValueError, UNKNOWN):
                            matrix_rows(docs)
                    self.assertEqual(evidence(key, docs, {key: True}), UNKNOWN)
        for path in (MAIN, PART4):
            missing = dict(self.documents)
            missing.pop(path)
            self.assertEqual(evidence("CB-1189", missing, {"CB-1189": True}), UNKNOWN)
        for key in LOCAL_IDS:
            self.assertEqual(evidence(key, self.documents, {key: False}), UNKNOWN)
            self.assertEqual(evidence(key, self.documents, {key: "success"}), UNKNOWN)
        self.assertEqual(evidence("CB-9999", self.documents, {}), UNKNOWN)
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            php_fixture("collector", "unauthorized")

    def test_sin_datos_reales(self) -> None:
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports |= {x.name.split(".")[0] for x in node.names}
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "httpx", "socket"})
        self.assertTrue(all((ROOT / p).is_file() for p in FIXTURES.values()))
        with tempfile.TemporaryDirectory() as home:
            env = safe_env(home)
            for name in ("GITHUB_TOKEN", "GH_TOKEN", "DB_PASSWORD", "MYSQL_PWD",
                         "DATABASE_URL", "APP_KEY", "HTTPS_PROXY", "HTTP_PROXY"):
                self.assertNotIn(name, env)
        phase = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(phase["phase"], "construccion")
        self.assertEqual(phase["d063_attestation"],
                         {"nothing_live": True, "no_real_customer_data": True})
        collector = php_fixture("collector", "over-400")
        self.assertNotIn("sentinel-read-value", json.dumps(collector))
        self.assertNotRegex(json.dumps(collector), r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}")

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                for fake in (None, True, "success", {"ci": "success"},
                             {"production": "GREEN"}, {"approved": True},
                             {"state": "VALIDATED_IN_PRODUCTION"},
                             {"deploy": "success"}):
                    self.assertEqual(operational(key, fake), UNKNOWN)
                self.assertIn("live=unknown", self.rows[key][4].lower())
                self.assertEqual(evidence(key, self.documents, {}), UNKNOWN
                                 if key in LOCAL_IDS else GAP)
        self.assertEqual(operational("CB-9999", {}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
