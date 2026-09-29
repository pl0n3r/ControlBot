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

STATUS_MARKERS = (
    "<!-- factory:status:start -->",
    "<!-- factory:status:end -->",
)
PROGRESS_MARKERS = (
    "<!-- factory:progress-readiness:start -->",
    "<!-- factory:progress-readiness:end -->",
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

    def test_readme_has_contract_v1_anatomy(self) -> None:
        readme = self.read("README.md")
        positions = []
        for section in REQUIRED_SECTIONS:
            heading = f"## {section}"
            self.assertEqual(1, readme.count(heading), heading)
            positions.append(readme.index(heading))
        self.assertEqual(sorted(positions), positions)
        self.assertIn("# ControlBot", readme)
        self.assertIn("Centro de control web **privado**", readme)

    def test_derived_blocks_fail_closed_without_evidence(self) -> None:
        readme = self.read("README.md")
        for marker in (*STATUS_MARKERS, *PROGRESS_MARKERS):
            self.assertEqual(1, readme.count(marker), marker)

        status = readme.split(STATUS_MARKERS[0], 1)[1].split(STATUS_MARKERS[1], 1)[0]
        progress = (
            readme.split(PROGRESS_MARKERS[0], 1)[1]
            .split(PROGRESS_MARKERS[1], 1)[0]
        )
        for row in (
            "main SHA", "versión", "CI", "release", "health",
            "smoke/observer", "quality/security", "Issue activo",
            "PR activo", "último release",
        ):
            self.assertIn(f"| {row} | UNKNOWN |", status)
        self.assertIn("| Readiness | UNKNOWN |", progress)
        self.assertNotIn("GREEN", status)
        self.assertNotIn("DEGRADED", status)

    def test_consumer_workflow_uses_factory_v1(self) -> None:
        workflow = self.read(".github/workflows/readme-contract.yml")
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/readme.yml@v1",
            workflow,
        )
        self.assertIn("readme_path: README.md", workflow)
        self.assertIn("metadata_path: readme/project.json", workflow)
        self.assertIn("contents: read", workflow)
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
