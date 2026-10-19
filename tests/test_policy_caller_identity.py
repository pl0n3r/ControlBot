"""Evita la espera circular de la Política reusable por nombre de check propio (#804)."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY_CALLER = ROOT / ".github" / "workflows" / "politica.yml"
CANONICAL_REUSABLE = "pl0n3r/factory/.github/workflows/politica.yml@v1"
CANONICAL_CHECK = "Factory policy / Validar decisiones y límite de revisión"


class PolicyCallerIdentityTests(unittest.TestCase):
    def _assert_canonical_caller(self, source: str) -> None:
        self.assertEqual(source.count("\njobs:\n"), 1)
        self.assertIn("\n  pull_request:\n", source)
        self.assertIn("types: [opened, synchronize, reopened, edited, ready_for_review]", source)
        block = source.split("\njobs:\n", 1)[1]
        self.assertTrue(block.startswith("  politica:\n"))
        self.assertEqual(
            re.findall(r"(?m)^    name: ([^\n]+)$", block), ["Factory policy"]
        )
        self.assertEqual(
            re.findall(r"(?m)^    uses: ([^\n]+)$", block), [CANONICAL_REUSABLE]
        )
        self.assertIn("    if: github.event.pull_request.draft == false\n", block)
        self.assertEqual(
            re.findall(r"(?m)^      ([a-z-]+): (read|write)$", block),
            [("contents", "read"), ("pull-requests", "read"),
             ("issues", "write"), ("checks", "read")],
        )
        self.assertNotIn("    secrets:", block)
        self.assertNotIn("      contents: write", block)
        self.assertNotIn("      checks: write", block)
        self.assertNotIn("      id-token: write", block)
        self.assertIn("      pr_number: " + "$" + "{{ github.event.pull_request.number }}", block)

    def test_policy_caller_check_identity(self):
        source = POLICY_CALLER.read_text(encoding="utf-8")
        self._assert_canonical_caller(source)
        name = re.search(r"(?m)^    name: ([^\n]+)$", source).group(1)
        self.assertEqual(
            name + " / Validar decisiones y límite de revisión", CANONICAL_CHECK
        )

    def test_policy_caller_rejects_unsafe_variants(self):
        source = POLICY_CALLER.read_text(encoding="utf-8")
        self._assert_canonical_caller(source)
        substitutions = (
            ("    name: Factory policy\n", ""),
            ("    name: Factory policy\n", "    name: politica\n"),
            ("    name: Factory policy\n",
             "    name: Factory policy\n    name: Factory policy\n"),
            ("    uses: " + CANONICAL_REUSABLE,
             "    uses: pl0n3r/factory/.github/workflows/other.yml@v1"),
            ("      checks: read\n", "      checks: write\n"),
            ("      contents: read\n", "      contents: write\n"),
            ("      pull-requests: read\n", "      pull-requests: write\n"),
        )
        for before, after in substitutions:
            with self.subTest(before=before, after=after):
                self.assertIn(before, source)
                with self.assertRaises(AssertionError):
                    self._assert_canonical_caller(source.replace(before, after, 1))

    def test_existing_contract_suite_supplies_independent_tests_gate(self):
        workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
        jobs = workflow.split("\njobs:\n", 1)[1]
        self.assertEqual(jobs.count("\n  contrato:\n"), 1)
        self.assertIn("\n  validar:\n", jobs)
        suite = jobs.split("\n  contrato:\n", 1)[1].split("\n  validar:\n", 1)[0]
        self.assertIn("    name: Pruebas de contrato e integración\n", suite)
        self.assertIn("python3 scripts/generate_coverage.py", suite)
        self.assertIn("Instalar coverage.py", suite)
        self.assertIn("Ejecutar regresiones ControlBot con cobertura", suite)
        self.assertIn("uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", suite)
        self.assertNotIn("continue-on-error: true", suite)
        self.assertIn("    name: Validar\n", jobs)
        self.assertIn("    needs: [factory, contrato]\n", jobs)
        self.assertNotIn("name: Contrato ControlBot", suite)
