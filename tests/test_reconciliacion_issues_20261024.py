"""AC-01 ControlBot #861: reconciliación documental fail-closed, sin red."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs" / "reconciliacion-issues-20261024.md"
EXPECTED_OPEN_20261024 = {
    1, 45, 55, 182, 561, 567, 625, 750, 751, 760,
    761, 762, 763, 817, 861,
}
ISSUE_LINK = re.compile(
    r"\[#(\d+)\]\(https://github\.com/pl0n3r/ControlBot/issues/(\d+)\)"
)
EVIDENCE_LINK = re.compile(
    r"https://github\.com/pl0n3r/(?:ControlBot|Factory)/(?:issues|pull)/\d+"
    r"(?:#issuecomment-\d+)?"
)


def parse_report(report: str) -> list[tuple[int, str, str, str]]:
    """Extrae filas de la tabla; errores de origen generan evidencia inválida."""
    results = []
    for line in report.splitlines():
        if not line.startswith("| [#"):
            continue
        columns = [column.strip() for column in line.strip().strip("|").split("|")]
        if len(columns) != 4:
            raise ValueError("invalid_row_shape")
        match = ISSUE_LINK.fullmatch(columns[0])
        if match is None or match.group(1) != match.group(2):
            raise ValueError("issue_identity_mismatch")
        if columns[1] not in {"MANTENER", "ACTUALIZAR", "CERRAR"}:
            raise ValueError("invalid_decision")
        if not EVIDENCE_LINK.search(columns[2]):
            raise ValueError("missing_evidence_url")
        if not columns[3] or len(columns[3]) < 25:
            raise ValueError("missing_unblock_condition")
        results.append((int(match.group(1)), columns[1], columns[2], columns[3]))
    return results


class ReconciliacionIssuesTests(unittest.TestCase):
    def test_acceptance(self):
        source = REPORT.read_text(encoding="utf-8")
        self.assertIn("ControlBot/main@1cab116cd8a4c63014eee19d6dbb5049667b9b14", source)
        rows = parse_report(source)
        ids = [row[0] for row in rows]
        self.assertEqual(len(EXPECTED_OPEN_20261024), len(rows))
        self.assertEqual(EXPECTED_OPEN_20261024, set(ids))
        self.assertEqual(len(ids), len(set(ids)))
        self.assertFalse(any(decision == "CERRAR" for _, decision, _, _ in rows))
        for issue, decision, evidence, unblock in rows:
            with self.subTest(issue=issue):
                self.assertIn("https://github.com/pl0n3r/ControlBot/", evidence)
                self.assertGreaterEqual(len(unblock), 25)
                self.assertIn(decision, {"MANTENER", "ACTUALIZAR"})

    def test_reject_missing_or_duplicated_issue(self):
        source = REPORT.read_text(encoding="utf-8")
        rows = parse_report(source)
        self.assertEqual(len(rows), len(EXPECTED_OPEN_20261024))
        first = next(line for line in source.splitlines() if line.startswith("| [#"))
        self.assertNotEqual(
            EXPECTED_OPEN_20261024,
            {row[0] for row in parse_report(source.replace(first + "\n", "", 1))},
        )
        duplicated = parse_report(source + "\n" + first + "\n")
        ids = [row[0] for row in duplicated]
        self.assertNotEqual(len(ids), len(set(ids)))

    def test_reject_rows_without_auditable_evidence(self):
        source = REPORT.read_text(encoding="utf-8")
        first = next(line for line in source.splitlines() if line.startswith("| [#"))
        broken = first.replace(
            "[Épica raíz](https://github.com/pl0n3r/ControlBot/issues/1)",
            "sin-fuente", 1
        )
        # Si falla identidad o fuente, no puede pasar por una fila aceptable.
        with self.assertRaises(ValueError):
            parse_report(source.replace(first, broken, 1))


if __name__ == "__main__":
    unittest.main()
