import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

REQUIRED_SECTIONS = (
    "Operational Cockpit",
    "Work Queue",
    "Qué hace el producto",
    "Arquitectura en 60 segundos",
    "Stack e infraestructura",
    "Ciclo de entrega",
    "Calidad y seguridad",
    "Roadmap y fuentes de verdad",
    "Desarrollo local",
    "Mapa de la fábrica",
)

class ReadmeContractAdoptionTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_project_metadata_is_stable_and_complete(self) -> None:
        metadata = json.loads(self.read("readme/project.json"))
        required = {"name", "tagline", "role", "phase", "roadmap", "stack"}
        forbidden = {
            "main_sha", "version", "ci", "release", "health",
            "smoke", "quality", "active_issue", "active_pr", "last_release",
        }
        self.assertEqual(required, set(metadata))
        self.assertFalse(forbidden & set(metadata))
        self.assertEqual("ControlBot", metadata["name"])
        self.assertEqual("construction", metadata["phase"])
        self.assertEqual(
            "https://github.com/pl0n3r/ControlBot/issues/1",
            metadata["roadmap"],
        )
        self.assertTrue(
            all(isinstance(value, str) and value.strip() for value in metadata.values())
        )

    def test_readme_is_contract_v2_without_unknown_blocks(self) -> None:
        readme = self.read("README.md")
        self.assertTrue(readme.startswith("# ControlBot\n"))

        # Hero + diez headings: once superficies canónicas en orden.
        headings = [f"## {section}" for section in REQUIRED_SECTIONS]
        offsets = [readme.index(heading) for heading in headings]
        self.assertEqual(sorted(offsets), offsets)
        for heading in headings:
            self.assertEqual(1, readme.count(heading), heading)

        for forbidden in (
            "UNKNOWN", "<!-- factory:status", "<!-- factory:progress-readiness",
            "Progress + Readiness", "| Señal | Estado |",
            "| Readiness |", "| main SHA |", "| Target |",
        ):
            self.assertNotIn(forbidden, readme, forbidden)

        cockpit = readme.split("## Operational Cockpit", 1)[1].split(
            "## Work Queue", 1
        )[0]
        for signal in (
            "https://github.com/pl0n3r/ControlBot/actions/workflows/ci.yml/badge.svg?branch=main",
            "https://github.com/pl0n3r/ControlBot/actions",
            "https://github.com/pl0n3r/ControlBot/releases",
            "https://img.shields.io/github/v/release/pl0n3r/ControlBot",
            "https://control.condorapp.com.co/",
        ):
            self.assertIn(signal, cockpit, signal)
        self.assertIn("sin commits de refresco", cockpit)
        self.assertIn("no hay un `/health` público productivo acreditado", cockpit)
        self.assertNotIn("| Señal |", cockpit)
        self.assertNotIn("https://example.com", cockpit)

        # Fuentes fiables y límites de autoridad; el health privado no da live.
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/817", readme)
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/625", readme)
        self.assertIn("docs/hostinger-d059-backup.md", readme)
        self.assertIn("docs/observador-reservas-consumidor.md", readme)

    def test_readme_caller_only_on_document_changes(self) -> None:
        """F2B: solo cambios documentales disparan el validador Factory v1."""
        workflow = self.read(".github/workflows/readme-contract.yml")
        expected_paths = [
            "- 'README.md'",
            "- 'readme/**'",
            "- '.github/workflows/readme-contract.yml'",
        ]

        def event_paths(source: str, event: str, next_event: str) -> list[str]:
            section = source.split(f"  {event}:\n", 1)[1].split(
                f"\n  {next_event}:\n", 1
            )[0]
            self.assertIn("    branches: [main]", section)
            self.assertEqual(section.count("    paths:\n"), 1)
            return [line.strip() for line in section.splitlines()
                    if line.strip().startswith("- ")]

        self.assertIn("  workflow_dispatch:\n", workflow)
        self.assertIn(
            "    types: [opened, synchronize, reopened, ready_for_review]",
            workflow,
        )
        self.assertEqual(workflow.count("    paths:\n"), 2)
        for event, following in (
            ("pull_request", "push"),
            ("push", "workflow_dispatch"),
        ):
            with self.subTest(event=event):
                self.assertEqual(event_paths(workflow, event, following),
                                 expected_paths)

        # Negativos: un filtro global, una ruta ausente o extras deben
        # hacer fallar el contrato sin cambiar permisos ni el reusable.
        variants = (
            workflow.replace("- 'readme/**'", "- '**'", 1),
            workflow.replace("- 'README.md'\n", "", 1),
            workflow.replace("- '.github/workflows/readme-contract.yml'",
                             "- 'src/**'", 1),
        )
        for variant in variants:
            with self.subTest(variant=variant):
                self.assertNotEqual(
                    event_paths(variant, "pull_request", "push"),
                    expected_paths,
                )

        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/readme.yml@v1",
            workflow,
        )
        self.assertIn("readme_path: README.md", workflow)
        self.assertIn("metadata_path: readme/project.json", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("readme.yml@main", workflow)

    def test_work_queue_links_canonical_roadmap(self) -> None:
        readme = self.read("README.md")
        queue = readme.split("## Work Queue", 1)[1].split("## Qué hace el producto", 1)[0]
        for label in ("NOW", "NEXT", "LATER", "BLOCKED"):
            self.assertEqual(1, queue.count(f"**{label}:**"))
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/1", queue)
        self.assertIn("Esta vista resume la cola", queue)

    def test_factory_map_preserves_roles(self) -> None:
        readme = self.read("README.md")
        factory_map = (
            readme.split("## Mapa de la fábrica", 1)[1]
            .split("## Inbox de decisiones", 1)[0]
        )
        expected = (
            "**Factory:** governance/kit",
            "**ControlBot:** control plane",
            "**FactoryRunner:** execution plane",
            "**AutoFactory:** herramienta local/manual",
            "**Condor / GrindFlow / BRVTAL:** productos",
        )
        for phrase in expected:
            self.assertIn(phrase, factory_map)


if __name__ == "__main__":
    unittest.main()
