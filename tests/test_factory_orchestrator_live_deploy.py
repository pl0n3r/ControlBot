#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / ".github/workflows/deploy.yml"
OBSERVE = ROOT / ".github/workflows/observar.yml"
DOC = ROOT / "docs/orquestador-en-vivo-deploy.md"


class FactoryOrchestratorLiveDeployTests(unittest.TestCase):
    def read(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_deploy_remains_disabled_without_explicit_owner_configuration(self) -> None:
        source = self.read(DEPLOY)
        self.assertGreaterEqual(source.count("if: vars.DEPLOY_ENABLED == 'true'"), 2)
        self.assertIn("DEPLOY_ENABLED: ${{ vars.DEPLOY_ENABLED }}", source)
        self.assertIn('[[ "$DEPLOY_ENABLED" == "true" ]]', source)
        self.assertIn("phase: ${{ vars.PRODUCTION_STAGE || 'construccion' }}", source)
        self.assertIn("migration_mode: ${{ vars.MIGRATION_MODE || 'none' }}", source)
        self.assertNotIn("\n  push:", source)

    def test_public_entrypoint_and_required_environment_are_validated_without_real_secrets(self) -> None:
        source = self.read(DEPLOY)
        self.assertIn("PUBLIC_ENTRYPOINT: public/index.php", source)
        self.assertIn('[[ -n "$DOMAIN" ]]', source)
        self.assertIn('[[ -f "$PUBLIC_ENTRYPOINT" ]]', source)
        self.assertIn("persist-credentials: false", source)
        self.assertIn("DEPLOY_TOKEN: ${{ secrets.DEPLOY_TOKEN }}", source)
        self.assertIn("DATABASE_URL: ${{ secrets.DATABASE_URL }}", source)
        self.assertIn("DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}", source)
        self.assertNotIn("control.condorapp.com.co", source)

    def test_observer_fails_closed_on_missing_identity_or_unconfigured_domain(self) -> None:
        source = self.read(OBSERVE)
        condition = "if: vars.DEPLOY_ENABLED == 'true' && vars.DOMAIN != ''"
        self.assertGreaterEqual(source.count(condition), 2)
        self.assertIn("PUBLIC_ENTRYPOINT: public/index.php", source)
        self.assertIn('[[ -n "$DOMAIN" ]]', source)
        self.assertIn('[[ -n "$GITHUB_SHA" ]]', source)
        self.assertIn("expected_sha: ${{ github.sha }}", source)
        self.assertIn("pl0n3r/factory/.github/workflows/observar.yml@v1", source)

    def test_rollback_contract_is_documented_and_non_destructive(self) -> None:
        source = self.read(DOC)
        self.assertIn("## Rollback", source)
        self.assertIn("git revert", source)
        self.assertIn("backup previo", source)
        self.assertIn("no borrar bases de datos, archivos remotos, DNS ni secretos", source)
        self.assertIn("Un deploy exitoso no equivale a producción validada", source)


if __name__ == "__main__":
    unittest.main()
