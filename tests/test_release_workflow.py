import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "release.yml"
VERSION = ROOT / "config" / "version.json"


class ReleaseWorkflowTests(unittest.TestCase):
    def setUp(self) -> None:
        self.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_release_push_is_scoped_to_version_source(self) -> None:
        self.assertIn("push:", self.workflow)
        self.assertIn("branches: [main]", self.workflow)
        self.assertIn("paths:", self.workflow)
        self.assertIn("- 'config/version.json'", self.workflow)

    def test_release_keeps_factory_v1_reusable(self) -> None:
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/release.yml@v1",
            self.workflow,
        )
        self.assertIn("version_source: config/version.json", self.workflow)
        self.assertIn("version_format: json", self.workflow)

    def test_release_caller_keeps_minimum_permissions(self) -> None:
        self.assertIn("permissions:\n  contents: write", self.workflow)
        self.assertNotIn("actions: write", self.workflow)
        self.assertNotIn("pull-requests: write", self.workflow)
        self.assertNotIn("issues: write", self.workflow)

    def test_caller_does_not_reimplement_release_logic(self) -> None:
        version = json.loads(VERSION.read_text(encoding="utf-8"))["version"]
        self.assertEqual(version, "0.1.10")
        self.assertNotIn("git tag", self.workflow)
        self.assertNotIn("gh release", self.workflow)
        self.assertNotIn("curl ", self.workflow)


if __name__ == "__main__":
    unittest.main()
