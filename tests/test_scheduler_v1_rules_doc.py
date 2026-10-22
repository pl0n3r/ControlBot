"""Aceptación de la especificación Scheduler V1 sin ejecutar orq.py."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "orquestador" / "scheduler-v1-reglas.md"
ORQ = ROOT / "tools" / "orquestador-local" / "orq.py"
README = ROOT / "tools" / "orquestador-local" / "README.md"

REQUIRED = (
    "per_repo=12", "max_active=99", "per_tick=8", "3 issues por chat",
    "180 s", "120", "150 s", "90 s", "600 s", "2, 4, 8, 15",
    "3600 s", "1,5/3/6/10", "30 min", "3 horas", "6 horas",
    "2 intentos", "depends_on", "paths_collide", "/tomar",
    "10 por ciclo", "900 s", "15 minutos", "25 min",
    "latest_checks", "startedAt/completedAt", "HEAD",
    "65*60", "dependents_count", "FINAL", "--auto --squash",
    "fails=0", "D-069", "V3", "CACHE_SECONDS=300",
    "rate_limit.remaining <1200", "/api/planner", "Factory #767",
    "factory-human-gate", "/decidir A", "/decidir B",
)


def validate_document(source):
    ids = re.findall(r"^## R(\d\d) — [^\n]+$", source, re.M)
    expected = [f"{i:02d}" for i in range(1, 17)]
    if ids != expected:
        raise ValueError(f"Reglas incompletas, duplicadas o desordenadas: {ids}")
    blocks = re.findall(r"(?ms)^## R\d\d — .*?(?=^## |\Z)", source)
    for i, block in enumerate(blocks, 1):
        for marker in ("**Regla:**", "**Por qué:**", "**Fuente:**"):
            if not re.search(re.escape(marker) + r"[ \t]*[^\s\n][^\n]*", block):
                raise ValueError(f"R{i:02d}: falta {marker}")
        if "[orq.py#L" not in block:
            raise ValueError(f"R{i:02d}: fuente no trazable")
    for heading in ("## Qué NO portar", "## Preguntas abiertas"):
        if heading not in source:
            raise ValueError("Falta " + heading)
    for token in REQUIRED:
        if token not in source:
            raise ValueError("No se documentó " + token)
    return True


class SchedulerV1RulesDocTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")

    def test_rules_are_documented_with_reasons(self):
        self.assertTrue(validate_document(self.text))

    def test_omitted_section_fails_closed(self):
        changed = re.sub(r"(?ms)^## R05 — .*?(?=^## )", "", self.text)
        with self.assertRaisesRegex(ValueError, "Reglas incompletas"):
            validate_document(changed)

    def test_missing_reason_fails_closed(self):
        changed = self.text.replace("**Por qué:** Reducir mensajes", "**Por qué:** ", 1)
        with self.assertRaisesRegex(ValueError, "falta .*Por qué"):
            validate_document(changed)

    def test_wrong_limit_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "per_repo=12"):
            validate_document(self.text.replace("per_repo=12", "per_repo=200"))

    def test_missing_discrepancy_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "1,5/3/6/10"):
            validate_document(self.text.replace("1,5/3/6/10", "valores anteriores"))

    def test_missing_source_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "fuente no trazable"):
            validate_document(self.text.replace("[orq.py#L875-L916]", "[orq.py sin línea]", 1))

    def test_reference_source_signatures(self):
        source = ORQ.read_text(encoding="utf-8")
        for signature in ("def candidates(", "def unblock_candidates(",
                          "def plan_tick(", "def planner_set(",
                          "def review_queue_tick(", "def auto_merge_ready(",
                          '"per_repo": 12', '"min_gap_s": 120',
                          "REVIEW_MIN_GAP_S = 65 * 60"):
            with self.subTest(signature=signature):
                self.assertIn(signature, source)
        self.assertIn("scheduler-v1-reglas.md", README.read_text(encoding="utf-8"))

    def test_no_local_scheduler_authorization(self):
        for required in ("no sustituye Factory #767", "owner-only",
                         "No copiar", "sin revisión", "fails=0",
                         "decidir automáticamente"):
            with self.subTest(text=required):
                self.assertIn(required.lower(), self.text.lower())


if __name__ == "__main__":
    unittest.main()
