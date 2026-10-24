"""Regresión offline de la autoridad sucesora de Recovery #858.

No usa B2, credenciales, producción ni proveedor externo.
"""
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENTS = (
    ROOT / "docs/disaster-recovery-ui.md",
    ROOT / "docs/disaster-recovery-contract.md",
)
OWNER = "https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387350597"
JOURNAL = "https://github.com/pl0n3r/ControlBot/issues/858#issuecomment-6387355246"


class RecoveryOwnerAuthorizationDocsTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in DOCUMENTS:
            with self.subTest(path=path.name):
                source = path.read_text(encoding="utf-8")
                for required in (OWNER, JOURNAL, "#577", "antecedente", "opción A", "factory-human-decision"):
                    self.assertIn(required, source)
                self.assertNotIn("B en #577** sigue vigente", source)
                self.assertNotIn("#858 todavía exige A", source)

    def test_fail_closed_boundaries(self) -> None:
        for path in DOCUMENTS:
            with self.subTest(path=path.name):
                source = path.read_text(encoding="utf-8")
                for required in (
                    "Backblaze B2", "Object Lock", "credenciales", "términos",
                    "gasto", "datos reales", "restore", "RPO/RTO observados",
                    "FactoryRunner", "no autoriza", "UNKNOWN", "no HEALTHY",
                ):
                    self.assertIn(required, source)
                self.assertNotIn("DR STATUS            HEALTHY", source)

    def test_scope_only_docs(self) -> None:
        profile = json.loads(
            (ROOT / "config/recovery/controlbot.json").read_text(encoding="utf-8")
        )
        self.assertEqual(profile["version"], 1)
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertTrue(profile["strategy"]["offsite_required"])
        self.assertTrue(profile["strategy"]["immutable_required"])
        self.assertTrue(profile["encryption_required"])
        for key in ("storage_ref", "bucket", "credential", "token", "access_key", "secret", "endpoint_url"):
            self.assertNotIn(key, profile)


if __name__ == "__main__":
    unittest.main()
