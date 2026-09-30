import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "sonar-main-recovery.yml"


class SonarMainRecoveryWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text()

    def test_workflow_is_main_push_only_with_minimal_permissions(self):
        self.assertIn("push:", self.source)
        self.assertIn("branches: [main]", self.source)
        self.assertNotIn("pull_request:", self.source)
        self.assertNotIn("workflow_dispatch:", self.source)
        self.assertIn("contents: read", self.source)
        self.assertIn("checks: write", self.source)

    def test_recovery_targets_only_sonarqubecloud_suite_for_exact_sha(self):
        self.assertIn("SHA: ${{ github.sha }}", self.source)
        self.assertIn("SONAR_APP_ID: '12526'", self.source)
        self.assertIn("commits/$SHA/check-suites?per_page=100", self.source)
        self.assertIn("select(.app.id == ${SONAR_APP_ID})", self.source)
        self.assertNotIn("github.event.inputs", self.source)

    def test_rerequest_is_single_bounded_attempt_and_requires_success(self):
        endpoint = "check-suites/$suite_id/rerequest"
        self.assertEqual(self.source.count(endpoint), 1)
        self.assertIn("timeout-minutes: 10", self.source)
        self.assertIn("SECONDS + 180", self.source)
        self.assertIn("SECONDS + 300", self.source)
        self.assertIn('if [[ "$conclusion" == "success" ]]', self.source)
        self.assertIn("observed_reset=true", self.source)

    def test_workflow_has_no_extra_secret_or_quality_gate_bypass(self):
        self.assertIn("GH_TOKEN: ${{ github.token }}", self.source)
        for forbidden in (
            "SONAR_TOKEN",
            "${{ secrets.",
            "git commit",
            "git push",
            "qualitygate.wait=false",
            "continue-on-error: true",
        ):
            self.assertNotIn(forbidden, self.source)


if __name__ == "__main__":
    unittest.main()
