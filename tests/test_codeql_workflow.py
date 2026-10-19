"""Contrato de seguridad: CodeQL real y publicado por GH Advanced Security."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "codeql.yml"
CHECKOUT = "actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1"
CODEQL_SHA = "2892aa5e19bbd11bc0cff5427e3b750a04d9e3c2"


class CodeQLWorkflowTests(unittest.TestCase):
    def _audit(self, source: str) -> None:
        self.assertEqual(source.count("\non:\n"), 1)
        self.assertIn("  pull_request:\n    branches: [main]\n", source)
        self.assertIn("    types: [opened, synchronize, reopened, ready_for_review]\n", source)
        self.assertIn("  push:\n    branches: [main]\n", source)
        self.assertIn("\njobs:\n  codeql:\n    name: CodeQL\n", source)
        self.assertIn("timeout-minutes: 30", source)
        self.assertIn("    permissions:\n      contents: read\n      security-events: write\n", source)
        self.assertIn("      - name: Checkout sin credenciales persistentes\n", source)
        self.assertIn("        uses: " + CHECKOUT, source)
        self.assertIn("          persist-credentials: false", source)
        self.assertEqual(
            source.count("        uses: github/codeql-action/init@" + CODEQL_SHA), 1
        )
        self.assertEqual(
            source.count("        uses: github/codeql-action/analyze@" + CODEQL_SHA), 1
        )
        self.assertIn("          languages: actions,python\n", source)
        self.assertNotIn("checks: write", source)
        self.assertNotIn("pull-requests: write", source)
        self.assertNotIn("upload: never", source)
        self.assertNotIn("github-script", source)
        self.assertNotIn("gh api", source)

    def test_real_codeql_analyzers_pinned(self):
        self._audit(WORKFLOW.read_text(encoding="utf-8"))

    def test_codeql_workflow_rejects_unsafe_mutations(self):
        original = WORKFLOW.read_text(encoding="utf-8")
        mutations = (
            ("        uses: github/codeql-action/init@" + CODEQL_SHA,
             "        uses: github/codeql-action/init@v4"),
            ("        uses: github/codeql-action/analyze@" + CODEQL_SHA, ""),
            ("      security-events: write", "      checks: write"),
            ("          languages: actions,python", "          languages: php"),
            ("          persist-credentials: false", "          persist-credentials: true"),
            ("    name: CodeQL", "    name: Validar"),
        )
        for old, new in mutations:
            with self.subTest(old=old, new=new):
                self.assertIn(old, original)
                with self.assertRaises(AssertionError):
                    self._audit(original.replace(old, new, 1))
