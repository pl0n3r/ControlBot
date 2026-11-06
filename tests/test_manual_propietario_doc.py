#!/usr/bin/env python3
"""#1481: regresión del manual sin credenciales, UI inventada ni go-live."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUIDE = ROOT / "docs/manual/guia-del-propietario.md"
UI = (
    "src/OwnerLoginGate.php",
    "src/FactoryOrchestratorLiveUi.php",
    "src/GitHubGlobalUi.php",
    "src/GitHubProjectUi.php",
    "src/Routing/Routes/InboxRoutes.php",
    "src/ControlBotWebEntrypoint.php",
    "src/RecoveryCenterWebEntrypoint.php",
    "src/LegalStatusPanel.php",
    "src/ControlCenterShell.php",
)
SECTIONS = (
    "### Para qué sirve",
    "### Antes de empezar",
    "### Cómo se usa (paso a paso)",
    "### Avisos y mensajes",
    "### Limitaciones",
)


class ManualPropietarioTests(unittest.TestCase):
    def setUp(self):
        # El PR preparatorio del índice se fusiona antes que el PR #1480.
        # No fingir que el manual está presente; la regresión se activa al llegar.
        if not GUIDE.is_file():
            self.skipTest("Guía #1479 aún no fusionada; índice registrado preventivamente")
        self.guide = GUIDE.read_text(encoding="utf-8")

    def test_modulos_y_lenguaje(self):
        modules = re.split(r"(?m)^## [1-9]\\. ", self.guide)[1:]
        self.assertEqual(9, len(modules))
        for module in modules:
            for title in SECTIONS:
                self.assertIn(title, module)
            self.assertLessEqual(len(module.split()), 450)
        self.assertNotRegex(self.guide, r"\\b(?:POST|API|JSON|TOTP|OAuth)\\b")

    def test_etiquetas_que_existen_en_interfaz(self):
        labels = re.findall(r"«([^»]+)»", self.guide)
        self.assertGreaterEqual(len(set(labels)), 40)
        source = "\\n".join((ROOT / path).read_text(encoding="utf-8") for path in UI)
        self.assertEqual([], sorted(set(label for label in labels if label not in source)))

    def test_sin_secretos_y_sin_promover_live(self):
        self.assertNotRegex(self.guide, r"(?i)(?:github_pat_|ghp_[A-Za-z0-9]{20}|sk-[A-Za-z0-9]{20}|-----BEGIN PRIVATE KEY-----)")
        self.assertIn("no una instalación en producción comprobada", self.guide)
        self.assertIn("no crea copias", self.guide)
        self.assertIn("no están fusionados", self.guide)


if __name__ == "__main__":
    unittest.main()
