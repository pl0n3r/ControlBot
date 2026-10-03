import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "docs/hostinger-d059-backup.md"
DECISIONS = ROOT / "decisiones.yml"


class HostingerD059BackupContractTests(unittest.TestCase):
    def text(self) -> str:
        return CONTRACT.read_text(encoding="utf-8")

    def test_contract_defines_backup_scope_evidence_and_verification_without_secrets(self):
        text = self.text()
        lower = text.lower()

        self.assertIn("public_html", text)
        self.assertIn("fecha/hora utc", lower)
        self.assertIn("referencia opaca", lower)
        self.assertIn("mecanismo de creación", lower)
        self.assertIn("verificación", lower)
        self.assertIn("restauración", lower)
        self.assertIn("no aplica en este gate", lower)
        self.assertIn("tokens", lower)
        self.assertIn("credenciales", lower)

        decisions = json.loads(DECISIONS.read_text(encoding="utf-8"))
        d059 = next(row for row in decisions["decisions"] if row["id"] == "D-059")
        self.assertIn("backup previo", d059["text"])
        self.assertIn("registro en el Issue", d059["text"])

    def test_contract_keeps_real_backup_and_pr630_transition_as_separate_gate(self):
        text = self.text()
        lower = text.lower()

        self.assertIn("no crea un backup", lower)
        self.assertIn("no satisface d-059 por sí solo", lower)
        self.assertIn("pr #630 permanece en draft", lower)
        self.assertIn("contrato preparado", lower)
        self.assertIn("backup real existente", lower)
        self.assertIn("d-059 satisfecho", lower)
        self.assertIn("#627", text)
        self.assertIn("seis campos anteriores", lower)

    def test_pr630_rollback_is_non_destructive_and_verifiable(self):
        text = self.text()
        lower = text.lower()

        self.assertIn("revert explícito", lower)
        self.assertIn("sin reescribir", lower)
        self.assertIn("403/404", text)
        self.assertIn("restore point d-059", lower)
        self.assertIn("no se borran sitios", lower)
        self.assertIn("autorización explícita del dueño", lower)


if __name__ == "__main__":
    unittest.main()
