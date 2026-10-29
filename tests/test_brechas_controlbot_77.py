#!/usr/bin/env python3
"""ControlBot #1084: 15 brechas CB-1177..CB-1191, fail-closed.

Estas pruebas contrastan exactamente las filas y los fixtures versionados.
La prueba adicional no reproduce por sí misma una integración: no transforma
fixtures, tests existentes ni documentación en prueba de producción o live.
El runner PHP aislado falló de forma real en CI (exit=1); para no inventar
éxito, las 15 filas se documentan explícitamente como BRECHA/UNKNOWN hasta
una ejecución contrastada del comportamiento o evidencia terminal.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
IDS = tuple(f"CB-{i:04d}" for i in range(1177, 1192))
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
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
# Tener un fixture no implica que se haya ejecutado o que producción esté verde.
FIXTURES = {
    "CB-1189": (
        "tests/factory_orchestrator_evidence_collector_scenarios.php",
        "tests/test_factory_orchestrator_evidence_collector.py",
        ("over-400", "signal_summary"),
    ),
    "CB-1190": (
        "tests/factory_orchestrator_snapshot_refresh_scenarios.php",
        "tests/test_factory_orchestrator_snapshot_refresh.py",
        ("stale_fallback", "evidence_invalid"),
    ),
    "CB-1191": (
        "tests/factory_orchestrator_live_ui_scenarios.php",
        "tests/test_factory_orchestrator_live_ui.py",
        ("truncated", "stale_unknown"),
    ),
}
GAPS = {
    "CB-1177": "UNKNOWN: no hay fuente productiva departamental contrastada",
    "CB-1178": "UNKNOWN: falta vista con razón de ausencia por departamento",
    "CB-1179": "UNKNOWN: proxies sin contrato departamental no son HEALTH",
    "CB-1180": "UNKNOWN: falta fixture departamental presente/ausente/stale",
    "CB-1181": "UNKNOWN: despliegue owner-only y migración no autorizados",
    "CB-1182": "UNKNOWN: macro-DoD #1 no acreditada exhaustivamente",
    "CB-1183": "UNKNOWN: #625 no tiene recibo productivo exact-SHA",
    "CB-1184": "UNKNOWN: dependencias externas no observadas realmente",
    "CB-1185": "UNKNOWN: restore, rollback, seguridad y observación sin recibos",
    "CB-1186": "UNKNOWN: inventario vivo P0/P1 no comprobado offline",
    "CB-1187": "UNKNOWN: decisión del owner de go-live no acreditada",
    "CB-1188": "UNKNOWN: transporte Hostinger sin evidencia habilitante",
    "CB-1189": "UNKNOWN: colecta real >50 sin recibo; fixture no certifica producción",
    "CB-1190": "UNKNOWN: ejecución real collector/cron/snapshot no observada",
    "CB-1191": "UNKNOWN: UI desplegada y autenticada no observada",
}


def sources() -> dict[str, str]:
    """Lee únicamente los dos documentos canónicos; nunca contacta servicios."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in (MAIN, PART4)}


def rows(documents: object) -> dict[str, tuple[str, ...]]:
    if (type(documents) is not dict or set(documents) != {MAIN, PART4}
            or any(type(documents[p]) is not str or not documents[p]
                   for p in (MAIN, PART4))):
        raise ValueError(UNKNOWN + ": matrices ausentes")
    found: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART4):
        for line in documents[path].splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            number, clause = CLAUSES[key]
            cells = tuple(c.strip() for c in line.strip("|").split("|"))
            if (key in found or path != (PART4 if key in PART4_IDS else MAIN)
                    or len(cells) != 6 or cells[0] != key
                    or f"/issues/{number})" not in cells[1]
                    or cells[2] != clause or cells[3] != "NO_VERIFICABLE"
                    or "Aceptación integral o evidencia terminal no comprobada" not in cells[4]
                    or any(state not in cells[4].lower() for state in
                           ("offline=unknown", "live=unknown", "operando=unknown"))
                    or f"/issues/{number})" not in cells[5]):
                raise ValueError(UNKNOWN + ": fila ambigua " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": faltan filas " + str(set(IDS) - set(found)))
    return found


def fixture_contract(key: object, contents: object) -> bool:
    """Presencia e identidad de fixture; nunca afirmación de ejecución."""
    if (key not in FIXTURES or type(contents) is not dict
            or set(contents) != set(FIXTURES[key][:2])):
        return False
    file_path, test_path, tokens = FIXTURES[key]
    if any(type(contents[p]) is not str or not contents[p] for p in contents):
        return False
    return (all(token in contents[file_path] for token in tokens)
            and tokens[0] in contents[test_path])


def verdict(key: object, documents: object, fixtures: object) -> str:
    if type(key) is not str or key not in IDS or key not in GAPS:
        return UNKNOWN
    try:
        parsed = rows(documents)
    except ValueError:
        return UNKNOWN
    if key not in parsed or not GAPS[key].startswith(UNKNOWN + ":"):
        return UNKNOWN
    if key in FIXTURES and not fixture_contract(
        key, fixtures.get(key) if type(fixtures) is dict else None
    ):
        return UNKNOWN
    return GAP


def operational(_key: object, _claim: object = None) -> str:
    """No certificar CI, producción, identidad, D-043/D-059 ni go-live."""
    return UNKNOWN


class BrechasControlbot77Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.docs = sources()
        cls.parsed = rows(cls.docs)
        cls.fixture_sources = {
            key: {path: (ROOT / path).read_text(encoding="utf-8")
                  for path in value[:2]}
            for key, value in FIXTURES.items()
        }

    def test_contrato_principal(self) -> None:
        """AC-01: cada una de las quince filas tiene brecha visible e ID."""
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(self.parsed), set(IDS))
        self.assertEqual(set(GAPS), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(self.parsed[key][3], "NO_VERIFICABLE")
                self.assertIn(UNKNOWN, GAPS[key])
                self.assertEqual(verdict(key, self.docs, self.fixture_sources), GAP)
                self.assertEqual(operational(key), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: cualquier fuente ausente, duplicada o promovida falla."""
        for key in IDS:
            path = PART4 if key in PART4_IDS else MAIN
            line = next(s for s in self.docs[path].splitlines()
                        if s.startswith("| " + key + " |"))
            for corrupted in (
                self.docs[path].replace(line, "", 1),
                self.docs[path] + "\n" + line,
                self.docs[path].replace(
                    line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
            ):
                altered = {**self.docs, path: corrupted}
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(altered)
                    self.assertEqual(verdict(key, altered, self.fixture_sources), UNKNOWN)
        for key in FIXTURES:
            self.assertEqual(verdict(key, self.docs, {}), UNKNOWN)
            path, other, _ = FIXTURES[key]
            damaged = {**self.fixture_sources,
                       key: {path: "fake", other: "fake"}}
            self.assertEqual(verdict(key, self.docs, damaged), UNKNOWN)
        self.assertEqual(verdict("CB-9999", self.docs, self.fixture_sources), UNKNOWN)
        self.assertEqual(verdict("CB-1181", None, self.fixture_sources), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo lectura de repositorio; sin subprocess, red ni PII."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {
            "socket", "requests", "httpx", "urllib", "subprocess", "os",
        })
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertEqual(data["d063_attestation"],
                         {"nothing_live": True, "no_real_customer_data": True})
        self.assertEqual(len(self.fixture_sources), 3)
        self.assertTrue(all(fixture_contract(k, v)
                            for k, v in self.fixture_sources.items()))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: evidencias ficticias no autorizan operación/live."""
        for key in IDS:
            with self.subTest(row=key):
                self.assertIn("live=unknown", self.parsed[key][4].lower())
                for claim in (None, True, "success", {"ci": "success"},
                              {"production": "GREEN"}, {"approved": True},
                              {"state": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(operational(key, claim), UNKNOWN)
        self.assertEqual(operational("CB-9999", {}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
