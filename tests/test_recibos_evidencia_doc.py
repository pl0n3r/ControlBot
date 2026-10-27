"""AC-01..04: contrato documental de recibos de evidencia sintética (#963).

No realiza operaciones de hosting, red, cuentas, tokens ni despliegue.
La atestación D-043 permanece DEL_DUEÑO hasta verificación externa auténtica.
"""
from __future__ import annotations

from datetime import datetime, timedelta
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "evidence-receipts.md"
SOURCE = ROOT / "docs" / "lista-salida-produccion.md"
TARGET = "Atestación D-043 VALIDATED_IN_PRODUCTION sin pendientes"
IMMUTABLE_SOURCE_URL = (
    "https://github.com/pl0n3r/ControlBot/blob/"
    "40cb9c72018e740053e0097a0f69613817d4181e/"
    "docs/lista-salida-produccion.md#L44"
)
REQUIRED_SECTIONS = (
    "## Propósito y límites",
    "## Campos mínimos del recibo",
    "## Plantilla para el operador autorizado",
    "## Ejemplo ficticio (NO ENSAYADO)",
    "## Validación y trazabilidad",
)
FIELDS = (
    "requisito", "sha", "hora_bogota", "operador",
    "resultado", "enlace", "clasificacion",
)
SENSITIVE = (
    re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{10,}|github_pat_[A-Za-z0-9_]{10,}|AKIA[A-Z0-9]{16})\b"),
    re.compile(r"\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b"),
    re.compile(r"(?im)^\s*(?:password|token|api_key|secret)\s*:\s*\S+"),
)


def sample_fields(document: str) -> dict[str, str]:
    marker = "\n## Ejemplo ficticio (NO ENSAYADO)\n"
    if document.count(marker) != 1:
        raise AssertionError("Debe existir exactamente un ejemplo ficticio")
    block = document.split(marker, 1)[1].split("\n## ", 1)[0]
    pairs = re.findall(r"^    ([a-z_]+): (.+)$", block, flags=re.M)
    if len(pairs) != len(FIELDS) or len({key for key, _ in pairs}) != len(FIELDS):
        raise AssertionError("Recibo sintético incompleto o duplicado")
    fields = dict(pairs)
    if set(fields) != set(FIELDS):
        raise AssertionError("Campos del recibo inesperados")
    return fields


def validate_synthetic_receipt(document: str) -> None:
    fields = sample_fields(document)
    if fields["requisito"] != "D-043-ATESTACION":
        raise AssertionError("Fila de evidencia incorrecta")
    if not re.fullmatch(r"[a-f0-9]{40}", fields["sha"]):
        raise AssertionError("SHA con formato incorrecto")
    if fields["sha"] != "0" * 40:
        raise AssertionError("El ejemplo no puede aparentar un commit auténtico")
    try:
        observed = datetime.fromisoformat(fields["hora_bogota"])
    except ValueError as exc:
        raise AssertionError("La hora debe ser ISO 8601") from exc
    if observed.utcoffset() != timedelta(hours=-5):
        raise AssertionError("Debe indicar offset de Bogotá (-05:00)")
    if fields["operador"] != "OPERADOR_FICTICIO":
        raise AssertionError("No identificar un operador real")
    if fields["resultado"] != "NO ENSAYADO":
        raise AssertionError("Se simula una atestación productiva")
    if fields["clasificacion"] != "PROPUESTA":
        raise AssertionError("Un ejemplo no puede ser evidencia verificada")
    if fields["enlace"] != "https://github.com/pl0n3r/ControlBot/issues/963":
        raise AssertionError("El ejemplo debe enlazar solo su issue, no una operación real")


def require_source_link(document: str, source: str) -> None:
    if IMMUTABLE_SOURCE_URL not in document:
        raise AssertionError("Falta enlace inmutable a la fila D-043")
    rows = [line for line in source.splitlines()
            if ("| " + TARGET + " |") in line and line.startswith("|")]
    if len(rows) != 1:
        raise AssertionError("La lista de salida debe contener la atestación única")
    cells = [part.strip() for part in rows[0].strip().strip("|").split("|")]
    if len(cells) != 5 or cells[2] != "DEL_DUEÑO":
        raise AssertionError("La atestación real no puede declararse completada")
    if "DEL_DUEÑO" not in document or TARGET not in document:
        raise AssertionError("No se reconoce el bloqueo de la fuente")


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = DOC.read_text(encoding="utf-8")
        cls.source = SOURCE.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        self.assertTrue(DOC.is_file())
        for section in REQUIRED_SECTIONS:
            with self.subTest(section=section):
                self.assertEqual(self.document.count(section), 1)
        for field in FIELDS:
            with self.subTest(field=field):
                self.assertIn("| " + field + " |", self.document)
        self.assertIn("America/Bogota", self.document)
        self.assertIn("SHA", self.document)
        self.assertIn("hora_bogota", self.document)
        self.assertIn("operador", self.document)
        self.assertIn("resultado", self.document)
        self.assertIn("enlace", self.document)

    def test_sin_datos_sensibles(self) -> None:
        for index, pattern in enumerate(SENSITIVE):
            with self.subTest(pattern=index):
                self.assertIsNone(pattern.search(self.document))
        self.assertIn("fuera del repositorio público", self.document)
        self.assertIn("Sin nombre, correo, teléfono", self.document)
        self.assertIn("acceso controlado", self.document)
        for example in (
            "correo@example.com", "ghp_" + "x" * 22,
            "-----BEGIN PRIVATE KEY-----", "192.168.1.3", "token: valor",
        ):
            with self.subTest(negative=example):
                altered = self.document + "\n" + example
                self.assertTrue(any(p.search(altered) for p in SENSITIVE))

    def test_estado_honesto(self) -> None:
        self.assertIn("Estado de este documento: PROPUESTA", self.document)
        self.assertIn("Ejecución real: NO ENSAYADO", self.document)
        for status in ("UNKNOWN", "PROPUESTA", "NO ENSAYADO", "DEL_DUEÑO"):
            self.assertIn(status, self.document)
        validate_synthetic_receipt(self.document)
        for old, replacement in (
            ("resultado: NO ENSAYADO", "resultado: VALIDATED_IN_PRODUCTION"),
            ("clasificacion: PROPUESTA", "clasificacion: VERIFICADA"),
            ("sha: " + "0" * 40, "sha: " + "a" * 40),
            ("-05:00\n    operador", "+00:00\n    operador"),
        ):
            with self.subTest(mutation=old[:35]):
                self.assertIn(old, self.document)
                with self.assertRaises(AssertionError):
                    validate_synthetic_receipt(self.document.replace(old, replacement, 1))

    def test_enlaza_fila_de_salida(self) -> None:
        require_source_link(self.document, self.source)
        with self.assertRaises(AssertionError):
            require_source_link(
                self.document.replace(IMMUTABLE_SOURCE_URL, "UNKNOWN"), self.source
            )
        with self.assertRaises(AssertionError):
            require_source_link(
                self.document, self.source.replace(
                    "| " + TARGET + " | DEL_DUEÑO |",
                    "| " + TARGET + " | LISTO |",
                )
            )


if __name__ == "__main__":
    unittest.main()
