import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "sonar.yml"
PINNED_SCANNER_SHA = "d209202bc7d53ff1cc128f7f907dac145c9d6ae9"
PROJECT_KEY = "pl0n3r_factory-control"


class SonarCiAnalysisWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = WORKFLOW.read_text(encoding="utf-8")

    def test_workflow_supports_pr_main_and_manual_exact_main_with_pinned_scanner_and_minimal_permissions(self):
        text = self.text
        self.assertIn("pull_request:", text)
        self.assertIn("push:", text)
        self.assertIn("workflow_dispatch:", text)
        self.assertGreaterEqual(text.count("branches: [main]"), 2)
        self.assertIn(
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main')",
            text,
        )
        self.assertIn("permissions:\n  contents: read", text)
        self.assertNotRegex(text, r"(?m)^\s+(checks|issues|pull-requests|actions):\s+write\s*$")
        self.assertIn("fetch-depth: 0", text)
        self.assertIn(
            f"uses: SonarSource/sonarqube-scan-action@{PINNED_SCANNER_SHA}",
            text,
        )

    def test_token_is_secret_only_and_never_hardcoded(self):
        text = self.text
        self.assertGreaterEqual(text.count("secrets.SONAR_TOKEN"), 2)
        self.assertNotIn("-Dsonar.token=", text)
        self.assertNotRegex(text, r"(?i)SONAR_TOKEN:\s*['\"]?[A-Za-z0-9_-]{20,}")
        self.assertIn("vars.SONAR_ORGANIZATION", text)
        self.assertIn(f"-Dsonar.projectKey={PROJECT_KEY}", text)

    def test_scanner_failure_is_observable_and_fails_job(self):
        text = self.text
        self.assertIn("-Dsonar.qualitygate.wait=true", text)
        self.assertIn("-Dsonar.qualitygate.timeout=300", text)
        self.assertIn('set -euo pipefail', text)
        self.assertNotIn("continue-on-error: true", text)

    def test_activation_is_explicit_and_fail_closed(self):
        text = self.text
        self.assertIn("vars.SONAR_CI_ENABLED == 'true'", text)
        self.assertIn('[[ -n "$SONAR_ORGANIZATION" ]]', text)
        self.assertIn('[[ -n "$SONAR_TOKEN" ]]', text)

    def test_secretless_pull_requests_are_explicitly_skipped_without_privilege_escalation(self):
        text = self.text
        self.assertIn(
            "github.event.pull_request.head.repo.full_name == github.repository",
            text,
        )
        self.assertIn("github.actor != 'dependabot[bot]'", text)
        self.assertNotIn("pull_request_target:", text)

    def test_analysis_method_check_is_derived_from_successful_ci_scanner(self):
        text = self.text
        self.assertIn("analysis-method:", text)
        self.assertIn("name: SonarQube Cloud Analysis Method = CI-based", text)
        self.assertIn("needs: sonar", text)
        self.assertIn("SONAR_RESULT: ${{ needs.sonar.result }}", text)
        self.assertIn('[[ "$SONAR_RESULT" == "success" ]]', text)
        self.assertNotIn("api/autoscan/activation", text)

    def test_workflow_has_no_legacy_rerequest_or_quality_gate_bypass(self):
        text = self.text
        self.assertNotIn("check-suites", text)
        self.assertNotIn("rerequest", text)
        self.assertNotIn("skipSignatureVerification: true", text)
        self.assertNotRegex(text, re.compile(r"sonar\.(exclusions|cpd\.exclusions)="))


if __name__ == "__main__":
    unittest.main()
