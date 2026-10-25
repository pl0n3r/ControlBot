"""Contrato offline del binding SSH de ControlBot hacia Factory@v1.

No invoca API, no crea releases, no lee secretos ni ejecuta deploy.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/deploy.yml"
RUNBOOK = ROOT / "docs/hostinger-ssh-deploy-binding.md"


class HostingerSshDeployBindingTests(unittest.TestCase):
    def setUp(self):
        self.source = WORKFLOW.read_text(encoding="utf-8")
        self.assertEqual(self.source.count("\n  deploy:\n"), 1)
        self.assertEqual(self.source.count("\n  smoke:\n"), 1)
        self.deploy = self.source.split("\n  deploy:\n", 1)[1].split("\n  smoke:\n", 1)[0]
        self.with_inputs = self.deploy.split("    with:\n", 1)[1].split("    secrets:\n", 1)[0]
        self.secrets = self.deploy.split("    secrets:\n", 1)[1]

    def test_explicit_ssh_transport_is_bound_but_defaults_to_none(self):
        self.assertIn("uses: pl0n3r/factory/.github/workflows/deploy.yml@v1", self.deploy)
        self.assertIn("transport: ${{ vars.DEPLOY_TRANSPORT || 'none' }}", self.with_inputs)
        self.assertEqual(self.with_inputs.count("\n      transport:"), 1)
        self.assertNotIn("transport: hostinger-ssh", self.source)
        self.assertIn("phase: ${{ vars.PRODUCTION_STAGE || 'construccion' }}", self.with_inputs)
        self.assertIn("migration_mode: ${{ vars.MIGRATION_MODE || 'none' }}", self.with_inputs)

    def test_hostinger_connection_fields_and_known_hosts_are_forwarded(self):
        bindings = {
            "hostinger_ssh_host": "HOSTINGER_SSH_HOST",
            "hostinger_ssh_user": "HOSTINGER_SSH_USER",
            "hostinger_ssh_port": "HOSTINGER_SSH_PORT",
            "hostinger_release_root": "HOSTINGER_RELEASE_ROOT",
        }
        for field, var in bindings.items():
            with self.subTest(field=field):
                expected = f"{field}: " + "${{ vars." + var + " || '' }}"
                self.assertIn(expected, self.with_inputs)
                self.assertEqual(self.with_inputs.count(f"\n      {field}:"), 1)
        self.assertIn("HOSTINGER_KNOWN_HOSTS: ${{ secrets.HOSTINGER_KNOWN_HOSTS }}", self.secrets)
        self.assertIn("DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}", self.secrets)
        self.assertNotIn("vars.HOSTINGER_KNOWN_HOSTS", self.source)
        self.assertNotIn("echo ${{ secrets.", self.source)
        self.assertNotIn("cat ${{ secrets.", self.source)

    def test_deploy_is_manual_gated_and_smoke_remains_exact_sha(self):
        self.assertIn("on:\n  workflow_dispatch:", self.source)
        self.assertNotIn("\n  push:", self.source)
        self.assertIn("if: vars.DEPLOY_ENABLED == 'true'", self.deploy)
        self.assertIn("if: vars.DEPLOY_ENABLED == 'true'", self.source)
        self.assertIn("needs: readiness", self.deploy)
        self.assertIn("needs: [readiness, deploy]", self.source)
        self.assertIn("EXPECTED_SHA: ${{ github.sha }}", self.source)
        self.assertIn("--proto '=https'", self.source)
        self.assertIn("--max-redirs 0", self.source)
        self.assertIn('php scripts/deploy-smoke.php "$fixture" config/version.json "$EXPECTED_SHA"', self.source)

    def test_preflight_preserves_missing_adapter_and_live_authority_gates(self):
        docs = RUNBOOK.read_text(encoding="utf-8")
        self.assertIn("ops/factory/", docs)
        self.assertIn("D-059", docs)
        self.assertIn("DEPLOY_ENABLED", docs)
        self.assertIn("owner-only", docs)
        self.assertIn("autenticado", docs)
        self.assertIn("NO despliega", docs)
        self.assertIn("Factory@v1", docs)
        self.assertIn("no autoriza", docs)
        self.assertIn("hostinger-ssh", docs)
        self.assertIn("HOSTINGER_KNOWN_HOSTS", docs)
        self.assertIn("DEPLOY_TRANSPORT", docs)
        self.assertIn("secrets:", self.deploy)
        self.assertIn("contents: read", self.source)
        self.assertIn("live_migration_approved: ${{ vars.LIVE_MIGRATION_APPROVED == 'true' }}", self.with_inputs)


if __name__ == "__main__":
    unittest.main()
