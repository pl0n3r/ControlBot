"""ControlBot #1454: evidence-gated reconciliation of 47 normative clauses.

Offline only. Nominal files do not certify an authorized bridge or deployment.
"""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

from tests import test_contraste_cb_0001_0300 as prior
from tests import test_matriz_cobertura as matrix

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/contraste-cb_0001_0300.md"
INDEX = ROOT / "docs/matriz-cobertura.md"
CLAIM = frozenset(prior.EXPECTED)
T = chr(96)


def _local_path(candidate: str) -> Path:
    """Only files inside checkout can substantiate a local candidate."""
    if not re.fullmatch(r"\x60(?:src|tests)/[A-Za-z0-9_./-]+\x60", candidate):
        raise ValueError("Candidato no es ruta canónica local")
    rel = Path(candidate.strip(T))
    if ".." in rel.parts or "." in rel.parts:
        raise ValueError("Candidato con traversal")
    path = (ROOT / rel).resolve()
    try:
        path.relative_to(ROOT.resolve())
    except ValueError as ex:
        raise ValueError("Candidato fuera del checkout") from ex
    if not path.is_file() or path.stat().st_size == 0:
        raise ValueError("Candidato inexistente/vacío")
    return path


def validate(report_text: str, index_text: str) -> dict[str, tuple[str, ...]]:
    """Require local candidate identity, original state and causal gap per ID."""
    reported = prior.rows(report_text, "\n## Veredictos\n", 8)
    normative = prior.rows(index_text, "\n## Matriz\n", 6)
    if len(reported) != 47 or set(reported) != CLAIM or len(normative) != 215:
        raise ValueError("Inventario alterado o incompleto")
    if "## Cierre conservador del lote #1454" not in report_text:
        raise ValueError("Falta corte de reconciliación")
    if "## Lote dirigido #1454" not in index_text:
        raise ValueError("Falta rastro del lote en el índice")
    for rid, row in reported.items():
        original = normative.get(rid)
        if original is None or row[1:3] != original[1:3]:
            raise ValueError("Fuente o cláusula no coinciden: " + rid)
        expected = "CUBIERTO" if rid == "CB-0058" else "NO_VERIFICABLE"
        if row[3] != original[3] or row[3] != expected:
            raise ValueError("Promoción sin evidencia: " + rid)
        if "live=UNKNOWN; operando=UNKNOWN" not in row[7]:
            raise ValueError("Se atribuye evidencia operativa inexistente: " + rid)
        if not row[1].startswith("[docs/") or len(row[2]) < 20:
            raise ValueError("Fuente o criterio sin ancla: " + rid)
        for candidate in (row[4], row[5]):
            if candidate != "—":
                _local_path(candidate)
        if rid == "CB-0058":
            if (row[4], row[5], row[6]) != (
                T+"src/BackupReceipt.php"+T,
                T+"tests/test_backup_integrity.py"+T,
                "[PR #899](https://github.com/pl0n3r/ControlBot/pull/899)",
            ):
                raise ValueError("No existe la única evidencia exacta previa")
            if "PR #899 MERGED" not in original[4]:
                raise ValueError("Estado histórico sin PR fusionado")
            if "offline=VERIFICADO" not in row[7]:
                raise ValueError("Contrato de digest sin testigo")
        else:
            if row[6] != "—" or "offline=UNKNOWN" not in row[7]:
                raise ValueError("PR o veredicto sin acreditar: " + rid)
            if ("Brecha #1454:" not in row[7] or
                len(row[7]) < 95):
                raise ValueError("Sin razón causal explícita: " + rid)
            if ("UNKNOWN" not in original[4] or
                "Contraste: [docs/contraste-cb_0001_0300.md]" not in original[4]):
                raise ValueError("Índice sin límite: " + rid)
    return reported


class VerifyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.index = INDEX.read_text(encoding="utf-8")

    def test_criterio_principal(self):
        """47 IDs exactos, 1194 inmutables, evidencia fusionada ejecutable."""
        detail = validate(self.report, self.index)
        all_rows = matrix.full_matrix()
        self.assertEqual(1194, len(all_rows))
        self.assertEqual({f"CB-{n:04d}" for n in range(1, 1195)},
                         {r[0] for r in all_rows})
        self.assertEqual({"CUBIERTO": 569, "NO_VERIFICABLE": 625},
                         dict(Counter(r[3] for r in all_rows)))
        self.assertEqual(47, len(detail))
        self.assertEqual(46, sum(r[3] == "NO_VERIFICABLE" for r in detail.values()))
        self.assertEqual(1, sum(r[3] == "CUBIERTO" for r in detail.values()))
        cases = (
            "tests.test_backup_integrity.BackupIntegrityTests."
            "test_sha256_digest_shape_fail_closed",
            "tests.test_backup_integrity.BackupIntegrityTests."
            "test_no_payload_leak_or_provider_io",
        )
        suite = unittest.defaultTestLoader.loadTestsFromNames(cases)
        result = unittest.TestResult()
        suite.run(result)
        self.assertEqual(2, result.testsRun)
        self.assertFalse(result.errors, str(result.errors))
        self.assertFalse(result.failures, str(result.failures))

    def test_criterio_seguridad_y_bordes(self):
        """Reject missing, duplicated, promoted or forged PR evidence."""
        original = self.report
        row = next(line for line in original.splitlines()
                   if line.startswith("| CB-0001 |"))
        self.assertIn("| NO_VERIFICABLE |", row)
        illegal = (
            original.replace(row, "", 1),
            original.replace(row, row + "\n" + row, 1),
            original.replace(row, row.replace(
                "| NO_VERIFICABLE |", "| CUBIERTO |", 1), 1),
            original.replace(row, row.replace(
                "| — | Candidatos", "| [PR #899] | Candidatos", 1), 1),
            original.replace("Brecha #1454:", "Brecha no documentada:", 1),
            original.replace(T+"src/AegisEvidence.php"+T,
                             T+"src/../../etc/passwd"+T, 1),
            original.replace(row, row.replace("live=UNKNOWN; operando=UNKNOWN",
                             "live=VERDE; operando=VERDE", 1), 1),
        )
        for i, changed in enumerate(illegal):
            with self.subTest(case=i):
                self.assertNotEqual(original, changed)
                with self.assertRaises((ValueError, AssertionError)):
                    validate(changed, self.index)
        index_row = next(line for line in self.index.splitlines()
                         if line.startswith("| CB-0001 |"))
        changed = index_row.replace(
            "| NO_VERIFICABLE |", "| CUBIERTO |", 1)
        self.assertNotEqual(index_row, changed)
        with self.assertRaises((ValueError, AssertionError)):
            validate(self.report, self.index.replace(index_row, changed, 1))
        self.assertIn("sin go-live", self.index)
        self.assertIn("sin credenciales ni llamadas remotas", self.report)
        self.assertNotIn("live=VERDE", self.report)


if __name__ == "__main__":
    unittest.main()
