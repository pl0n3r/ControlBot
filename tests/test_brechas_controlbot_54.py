#!/usr/bin/env python3
"""ControlBot #1061: quince brechas CB-0820..CB-0834, offline y fail-closed.

Contrasta contratos de deploy y observación con datos sintéticos. Los documentos
y un smoke local no acreditan Hostinger, backups, go-live ni producción real.
"""
from __future__ import annotations

import ast
import json
import re
import subprocess
import sys
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRICES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-03.md",
)
DOC = "docs/orquestador-en-vivo-deploy.md"
DEPLOY = ".github/workflows/deploy.yml"
OBSERVER = ".github/workflows/observar.yml"
SMOKE = "scripts/deploy-smoke.php"
IDS = tuple(f"CB-{n:04d}" for n in range(820, 835))
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL_SINTETICO"

# Cada ID tiene un anclaje propio en su fuente de verdad, no una mera
# comprobación de que el fichero o título del requisito existe.
DOC_MARKERS = {
    "CB-0820": "DOMAIN",  # El runtime concreto se valida abajo.
    "CB-0821": "expected_sha:",
    "CB-0822": "Ningún secreto se documenta con valor real.",
    "CB-0823": "no autoriza a crear el entrypoint público",
    "CB-0824": "debe ser exactamente",
    "CB-0825": "Secretos referenciados, nunca documentados con valores",
    "CB-0826": "Antes de llamar a Factory v1 exige",
    "CB-0827": "El preflight exige",
    "CB-0828": "una identidad distinta debe fallar cerrado",
    "CB-0829": "backup previo",
    "CB-0830": "mantener o devolver",
    "CB-0831": "no borrar bases de datos",
    "CB-0832": "usar el backup registrado",
    "CB-0833": "después de restaurar identidad exacta",
    "CB-0834": "Un deploy exitoso no equivale a producción validada",
}

# Para requisitos que dependen de evidencia externa, documentar la brecha;
# no convertir una frase contractual en un falso resultado verde.
DOCUMENT_ONLY = {"CB-0823", "CB-0829", "CB-0831", "CB-0832"}

SOURCE_MARKERS = {
    "CB-0820": {OBSERVER: ("if: vars.DOMAIN != '' && vars.DEPLOY_ENABLED == 'true'",)},
    "CB-0821": {OBSERVER: ("expected_sha:", "github.sha", "observar.yml@v1")},
    "CB-0822": {DEPLOY: ("secrets.DEPLOY_TOKEN", "secrets.DATABASE_URL",
                         "secrets.DEPLOY_SSH_KEY")},
    "CB-0823": {DEPLOY: ("workflow_dispatch:", "PUBLIC_ENTRYPOINT: public/index.php")},
    "CB-0824": {
        DEPLOY: ("if: vars.DEPLOY_ENABLED == 'true'",),
        OBSERVER: ("vars.DEPLOY_ENABLED == 'true'",),
    },
    "CB-0825": {DEPLOY: ("DEPLOY_TOKEN: $", "DATABASE_URL: $",
                         "DEPLOY_SSH_KEY: $")},
    "CB-0826": {DEPLOY: ('[[ -n "$DOMAIN" ]]', '[[ -f "$PUBLIC_ENTRYPOINT" ]]',
                         "uses: pl0n3r/factory/.github/workflows/deploy.yml@v1")},
    "CB-0827": {OBSERVER: ('[[ -n "$GITHUB_SHA" ]]',
                           '[[ -f "$PUBLIC_ENTRYPOINT" ]]')},
    "CB-0828": {
        OBSERVER: ("expected_sha:", "github.sha"),
        SMOKE: ("hash_equals($expectedSha", "hash_equals($expectedVersion"),
    },
    "CB-0829": {DOC: ("backup previo", "antes de la mutación")},
    "CB-0830": {DEPLOY: ("if: vars.DEPLOY_ENABLED == 'true'",)},
    "CB-0831": {DOC: ("no borrar bases de datos", "no destructivo")},
    "CB-0832": {DOC: ("backup registrado", "cambio productivo")},
    "CB-0833": {SMOKE: ("hash_equals($expectedSha",)},
    "CB-0834": {SMOKE: ("hash_equals($expectedVersion", "hash_equals($expectedSha"),
                           "status", "release_sha")},
}

FILES = set(MATRICES) | {DOC, DEPLOY, OBSERVER, SMOKE}


def read_sources() -> dict[str, str]:
    """No hay llamadas HTTP, secretos, shell remoto ni operaciones de deploy."""
    return {name: (ROOT / name).read_text(encoding="utf-8")
            for name in FILES}


def matrix_rows(sources: dict[str, str]) -> dict[str, tuple[str, ...]]:
    """Rechazar filas ausentes, ambiguas, duplicadas o declaradas verificadas."""
    found: dict[str, tuple[str, ...]] = {}
    for name in MATRICES:
        source = sources.get(name)
        if not isinstance(source, str):
            raise ValueError("UNKNOWN: falta la fuente de matriz")
        for line in source.splitlines():
            if not re.match(r"^\| CB-08(?:2[0-9]|3[0-4]) \|", line):
                continue
            cells = tuple(p.strip() for p in line.strip().strip("|").split("|"))
            identifier = cells[0]
            if identifier in found or len(cells) != 6 or not all(cells):
                raise ValueError("UNKNOWN: fila duplicada o incompleta " + identifier)
            if cells[3] != "NO_VERIFICABLE" or not all(
                f"{plane}=unknown" in cells[4].casefold()
                for plane in ("offline", "live", "operando")
            ):
                raise ValueError("UNKNOWN: estado no acreditado " + identifier)
            found[identifier] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: faltan cláusulas CB-0820..CB-0834")
    return found


def enabled(value: object) -> bool:
    """La cadena exacta 'true' es la única señal permitida por los workflows."""
    return isinstance(value, str) and value == "true"


def local_verdict(identifier: str, rows: dict[str, tuple[str, ...]],
                  sources: dict[str, str]) -> dict[str, str]:
    outcome = {"offline": UNKNOWN, "live": UNKNOWN, "operando": UNKNOWN,
               "production": UNKNOWN}
    row = rows.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 6
            or row[3] != "NO_VERIFICABLE"
            or "orquestador-en-vivo-deploy.md" not in row[1]
            or "UNKNOWN" not in row[4]):
        return outcome
    doc = sources.get(DOC)
    if not isinstance(doc, str) or DOC_MARKERS[identifier] not in doc:
        return outcome
    if not all(isinstance(sources.get(path), str)
               and all(anchor in sources[path] for anchor in anchors)
               for path, anchors in SOURCE_MARKERS[identifier].items()):
        return outcome
    # SOURCE_MARKERS prueba contrato versionado, no la configuración real.
    if identifier not in DOCUMENT_ONLY:
        outcome["offline"] = LOCAL
    return outcome


def smoke_fixture(version: object, release_sha: object, *, status: object = "ok",
                  extras: dict[str, object] | None = None,
                  expected_sha: str | None = None) -> subprocess.CompletedProcess[str]:
    """Ejecutar el comprobador PHP real sobre dos JSON temporales, sin red."""
    with TemporaryDirectory(prefix="cb-1061-offline-") as temp:
        directory = Path(temp)
        version_file = directory / "version.json"
        health_file = directory / "health.json"
        version_file.write_text(json.dumps({"version": "0.1.24"}), encoding="utf-8")
        payload: dict[str, object] = {
            "status": status, "version": version, "release_sha": release_sha,
        }
        if extras:
            payload.update(extras)
        health_file.write_text(json.dumps(payload), encoding="utf-8")
        return subprocess.run(
            ["php", str(ROOT / SMOKE), str(health_file), str(version_file),
             expected_sha if expected_sha is not None else "a" * 40],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=12,
        )


class BrechasControlbot54Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = read_sources()
        cls.rows = matrix_rows(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: cada una de 15 obligaciones tiene aserción o UNKNOWN."""
        self.assertEqual(set(IDS), set(DOC_MARKERS))
        self.assertEqual(set(IDS), set(SOURCE_MARKERS))
        self.assertEqual(set(IDS), set(self.rows))
        for identifier in IDS:
            with self.subTest(clause=identifier):
                outcome = local_verdict(identifier, self.rows, self.sources)
                expected = UNKNOWN if identifier in DOCUMENT_ONLY else LOCAL
                self.assertEqual(outcome["offline"], expected, identifier)
                self.assertTrue(all(
                    anchor in self.sources[path]
                    for path, anchors in SOURCE_MARKERS[identifier].items()
                    for anchor in anchors
                ), identifier)

        # CB-0820, CB-0824, CB-0830: exclusivamente la cadena 'true'.
        self.assertFalse(any(enabled(value) for value in
                             (None, "", "false", "TRUE", "1", True, 1, " true")))
        self.assertTrue(enabled("true"))
        self.assertIn("vars.DOMAIN != ''", self.sources[OBSERVER])
        self.assertIn("DEPLOY_ENABLED", self.sources[DEPLOY])
        # CB-0826/0827: ambas puertas reales tienen preflight local.
        self.assertIn("needs: readiness", self.sources[OBSERVER])
        self.assertIn("needs: readiness", self.sources[DEPLOY])
        # CB-0821/0828: identidad pinneada a github.sha, no a cualquier release.
        self.assertIn("expected_sha: " + chr(36) + "{{ github.sha }}",
                      self.sources[OBSERVER])
        # CB-0822/0825: workflows referencian nombres de secretos, no valores.
        self.assertIn("secrets.DEPLOY_TOKEN", self.sources[DEPLOY])
        self.assertIn("secrets.DATABASE_URL", self.sources[DEPLOY])
        self.assertIn("secrets.DEPLOY_SSH_KEY", self.sources[DEPLOY])
        # CB-0833/0834: verificador real contra JSON exclusivamente sintético.
        ok = smoke_fixture("0.1.24", "a" * 40)
        self.assertEqual(0, ok.returncode, ok.stderr)
        self.assertIn("release identity verified", ok.stdout)
        self.assertEqual("", ok.stderr)

    def test_falla_cerrado(self) -> None:
        """AC-02: código/evidencia ausente o identidad incorrecta = UNKNOWN."""
        for identifier in IDS:
            with self.subTest(clause=identifier):
                missing = dict(self.rows)
                del missing[identifier]
                self.assertEqual(UNKNOWN, local_verdict(
                    identifier, missing, self.sources)["offline"])
                no_doc = dict(self.sources)
                no_doc[DOC] = ""
                self.assertEqual(UNKNOWN, local_verdict(
                    identifier, self.rows, no_doc)["offline"])
                modified = dict(self.rows)
                row = list(modified[identifier])
                row[3] = "CUBIERTO"
                modified[identifier] = tuple(row)
                self.assertEqual(UNKNOWN, local_verdict(
                    identifier, modified, self.sources)["offline"])
        duplicate = dict(self.sources)
        duplicate[MATRICES[1]] += "\n" + next(
            line for line in self.sources[MATRICES[1]].splitlines()
            if line.startswith("| CB-0821 |")
        )
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(duplicate)
        for change in (
            ("0.1.24", "b" * 40, "ok", None),
            ("0.1.25", "a" * 40, "ok", None),
            ("0.1.24", "a" * 40, "degraded", None),
            ("0.1.24", "a" * 40, "ok", {"token": "synthetic"}),
        ):
            with self.subTest(bad_fixture=change[:3]):
                rejected = smoke_fixture(*change[:2], status=change[2],
                                         extras=change[3])
                self.assertNotEqual(0, rejected.returncode)
                self.assertNotIn("synthetic", rejected.stdout)
                self.assertNotIn("a" * 40, rejected.stderr)
        bad_expected = smoke_fixture("0.1.24", "a" * 40, expected_sha="b" * 40)
        self.assertNotEqual(0, bad_expected.returncode)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no secretos, credenciales, red o operaciones productivas."""
        own = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(own)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {str(node.module).split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom)}
        self.assertFalse(imports & {
            "requests", "httpx", "urllib", "socket", "ftplib", "paramiko",
        })
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertIn("str_contains($path, '://')", self.sources[SMOKE])
        self.assertIn("if (PHP_SAPI !== 'cli')", self.sources[SMOKE])
        self.assertFalse(any(Path(name).is_absolute() for name in FILES))
        # No hacer llamadas a los workflows de deploy ni observación.
        self.assertNotIn("workflow_" + "dispatch(", own)
        self.assertNotIn("git " + "push", own)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI local jamás demuestra D-043/D-059 ni datos live."""
        for identifier, row in self.rows.items():
            with self.subTest(clause=identifier):
                self.assertEqual(row[3], "NO_VERIFICABLE")
                self.assertIn("UNKNOWN", row[4])
                evidence = local_verdict(identifier, self.rows, self.sources)
                for plane in ("live", "operando", "production"):
                    self.assertEqual(UNKNOWN, evidence[plane])
                if identifier in DOCUMENT_ONLY:
                    self.assertEqual(UNKNOWN, evidence["offline"])
        self.assertEqual(UNKNOWN, local_verdict(
            "CB-0999", self.rows, self.sources)["offline"])
        absent = dict(self.sources)
        absent[OBSERVER] = ""
        self.assertEqual(UNKNOWN, local_verdict(
            "CB-0828", self.rows, absent)["offline"])
        # Incluso un smoke positivo no certifica rol owner ni Hostinger real.
        self.assertEqual(0, smoke_fixture("0.1.24", "a" * 40).returncode)
        self.assertEqual(UNKNOWN, local_verdict(
            "CB-0834", self.rows, self.sources)["production"])


if __name__ == "__main__":
    unittest.main()
