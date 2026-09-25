import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "release.yml"
VERSION = ROOT / "config" / "version.json"


def _parse_inline_list(value: str) -> list[str]:
    """Parse the inline YAML list shape used by this workflow contract."""
    value = value.strip()
    if not (value.startswith("[") and value.endswith("]")):
        raise AssertionError(f"Expected inline YAML list, got: {value!r}")
    items = value[1:-1].strip()
    if not items:
        return []
    return [item.strip().strip("'\"") for item in items.split(",")]


def _release_contract(workflow: str) -> dict[str, object]:
    """Parse the release workflow fields covered by the regression contract."""
    lines = workflow.splitlines()
    contract: dict[str, object] = {
        "push": {"branches": [], "paths": []},
        "permissions": {},
        "release": {"with": {}},
    }

    section = None
    subsection = None
    for raw_line in lines:
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue

        indent = len(raw_line) - len(raw_line.lstrip())
        text = raw_line.strip()

        if indent == 0:
            section = text.removesuffix(":")
            subsection = None
            continue

        if section == "on":
            if indent == 2 and text == "push:":
                subsection = "push"
                continue
            if subsection == "push" and indent == 4 and text.startswith("branches:"):
                contract["push"]["branches"] = _parse_inline_list(
                    text.split(":", 1)[1]
                )
                continue
            if subsection == "push" and indent == 4 and text == "paths:":
                subsection = "paths"
                continue
            if subsection == "paths" and indent == 6 and text.startswith("- "):
                contract["push"]["paths"].append(text[2:].strip().strip("'\""))
                continue

        if section == "permissions" and indent == 2 and ":" in text:
            key, value = text.split(":", 1)
            contract["permissions"][key.strip()] = value.strip()
            continue

        if section == "jobs":
            if indent == 2 and text == "release:":
                subsection = "release"
                continue
            if subsection == "release" and indent == 4 and text.startswith("uses:"):
                contract["release"]["uses"] = text.split(":", 1)[1].strip()
                continue
            if subsection == "release" and indent == 4 and text == "with:":
                subsection = "with"
                continue
            if subsection == "with" and indent == 6 and ":" in text:
                key, value = text.split(":", 1)
                contract["release"]["with"][key.strip()] = value.strip()

    return contract


class ReleaseWorkflowTests(unittest.TestCase):
    def setUp(self) -> None:
        """Load the workflow text and its parsed contract once per test."""
        self.workflow = WORKFLOW.read_text(encoding="utf-8")
        self.contract = _release_contract(self.workflow)

    def test_release_push_is_scoped_to_version_source(self) -> None:
        """Require the push trigger to target only main and the version source."""
        self.assertEqual(self.contract["push"]["branches"], ["main"])
        self.assertEqual(self.contract["push"]["paths"], ["config/version.json"])

    def test_release_keeps_factory_v1_reusable(self) -> None:
        """Keep Factory v1 as the canonical reusable release implementation."""
        release = self.contract["release"]
        self.assertEqual(
            release["uses"],
            "pl0n3r/factory/.github/workflows/release.yml@v1",
        )
        self.assertEqual(release["with"]["version_source"], "config/version.json")
        self.assertEqual(release["with"]["version_format"], "json")

    def test_release_caller_keeps_minimum_permissions(self) -> None:
        """Require exactly the write permission needed to publish releases."""
        self.assertEqual(self.contract["permissions"], {"contents": "write"})

    def test_caller_does_not_reimplement_release_logic(self) -> None:
        """Reject local release commands while keeping version data valid."""
        version = json.loads(VERSION.read_text(encoding="utf-8"))["version"]
        self.assertRegex(version, r"^\d+\.\d+\.\d+$")
        self.assertNotIn("git tag", self.workflow)
        self.assertNotIn("gh release", self.workflow)
        self.assertNotIn("curl ", self.workflow)


if __name__ == "__main__":
    unittest.main()
