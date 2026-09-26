import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ControlBotBootstrapTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_factory_v1_callers_present(self) -> None:
        expected = {
            ".github/workflows/ci.yml": "pl0n3r/factory/.github/workflows/ci.yml@v1",
            ".github/workflows/aceptacion.yml": "pl0n3r/factory/.github/workflows/aceptacion.yml@v1",
            ".github/workflows/roles.yml": "pl0n3r/factory/.github/workflows/roles.yml@v1",
            ".github/workflows/etiquetas.yml": "pl0n3r/factory/.github/workflows/etiquetas.yml@v1",
            ".github/workflows/politica.yml": "pl0n3r/factory/.github/workflows/politica.yml@v1",
            ".github/workflows/privacidad.yml": "pl0n3r/factory/.github/workflows/privacidad.yml@v1",
            ".github/workflows/release.yml": "pl0n3r/factory/.github/workflows/release.yml@v1",
        }
        for path, reference in expected.items():
            with self.subTest(path=path):
                self.assertIn(reference, self.read(path))

    def test_version_decisions_and_data_contract(self) -> None:
        version = json.loads(self.read("config/version.json"))
        decisions = json.loads(self.read("decisiones.yml"))
        data = json.loads(self.read("datos.yml"))
        self.assertRegex(version["version"], r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")
        self.assertTrue({"D-054", "D-055", "D-056", "D-057", "D-058", "D-059"}.issubset(
            {row["id"] for row in decisions["decisions"]}
        ))
        self.assertEqual("pl0n3r/ControlBot", data["project"])
        self.assertEqual("construccion", data["phase"])
        self.assertEqual(
            {"account_profiles", "agent_status", "audit_log", "chat_response_opt_in"},
            {row["id"] for row in data["treatments"]},
        )

    def test_coordination_is_installed(self) -> None:
        workflow = self.read(".github/workflows/coordinacion.yml")
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", workflow)
        self.assertIn("operation: validate", workflow)
        self.assertIn("github.event.comment.body == '/tomar'", workflow)
        self.assertIn("require_reservation:", workflow)

    def test_coordination_permissions_cover_reusable_envelope(self) -> None:
        workflow = self.read(".github/workflows/coordinacion.yml")
        for job in ("comentario", "etiqueta", "pr", "validar-pr", "issue", "sweep"):
            with self.subTest(job=job):
                match = re.search(
                    rf"(?ms)^  {re.escape(job)}:\n(.*?)(?=^  [a-zA-Z0-9_-]+:\n|\Z)",
                    workflow,
                )
                self.assertIsNotNone(match)
                block = match.group(1)
                self.assertIn("contents: write", block)
                self.assertIn("issues: write", block)
                self.assertIn("pull-requests: write", block)
                self.assertIn("uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1", block)

    def test_issue_78_bootstrap_is_exact_and_self_expiring(self) -> None:
        acceptance = self.read(".github/workflows/aceptacion.yml")
        coordination = self.read(".github/workflows/coordinacion.yml")
        self.assertIn("github.head_ref == 'trabajo/issue-78'", acceptance)
        self.assertIn(
            "github.event.pull_request.base.sha == '996802183248f84ee8f28c7cb25221247e52dd07'",
            acceptance,
        )
        self.assertIn(
            "github.event.pull_request.head.ref == 'trabajo/issue-78'",
            coordination,
        )
        self.assertIn(
            "github.event.pull_request.base.sha == '996802183248f84ee8f28c7cb25221247e52dd07'",
            coordination,
        )

    def test_deploy_stays_construction_only(self) -> None:
        deploy = self.read(".github/workflows/deploy.yml")
        observe = self.read(".github/workflows/observar.yml")
        self.assertIn("if: vars.DEPLOY_ENABLED == 'true'", deploy)
        self.assertIn("PRODUCTION_STAGE || 'construccion'", deploy)
        self.assertIn("if: vars.DOMAIN != ''", observe)
        self.assertFalse((ROOT / "public").exists(), "El bootstrap no debe introducir runtime público")

    def test_bootstrap_contract(self) -> None:
        required = [
            ".github/ISSUE_TEMPLATE/trabajo.yml",
            ".github/pull_request_template.md",
            ".github/dependabot.yml",
            "docs/privacidad/politica-tratamiento.md",
            "docs/privacidad/aviso-privacidad.md",
            "docs/privacidad/terminos-condiciones.md",
            "docs/privacidad/registro-tratamientos.md",
            "docs/privacidad/canal-derechos.md",
            "docs/privacidad/retencion.md",
        ]
        for path in required:
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file())


if __name__ == "__main__":
    unittest.main()
