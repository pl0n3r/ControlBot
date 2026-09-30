import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "sonar-main-recovery.yml"


class SonarMainRecoveryWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")

    def test_workflow_is_main_push_only_with_minimal_permissions(self):
        header = self.source.split("\njobs:\n", 1)[0]
        self.assertRegex(
            header,
            r"(?ms)^on:\n  push:\n    branches: \[main\]\n\npermissions:\n  contents: read\n  checks: write\n",
        )
        self.assertNotIn("pull_request:", header)
        self.assertNotIn("workflow_dispatch:", header)
        self.assertNotIn("schedule:", header)
        self.assertIn("timeout-minutes: 12", self.source)
        self.assertIn("cancel-in-progress: false", self.source)

    def test_recovery_targets_only_sonarqubecloud_suite_for_exact_sha(self):
        self.assertIn('SHA: ${{ github.sha }}', self.source)
        self.assertIn('SONAR_APP_ID: "12526"', self.source)
        self.assertIn("commits/${SHA}/check-suites?app_id=${SONAR_APP_ID}", self.source)
        self.assertIn("select(.head_sha == $sha and .app.id == $app_id)", self.source)
        self.assertIn('suite_sha" != "$SHA"', self.source)
        self.assertIn('suite_app_id" != "$SONAR_APP_ID"', self.source)
        self.assertNotIn("github.event.inputs", self.source)

    def test_rerequest_is_single_bounded_attempt_and_requires_success(self):
        self.assertEqual(self.source.count("/rerequest"), 1)
        self.assertIn('WAIT_ATTEMPTS: "40"', self.source)
        self.assertIn('WAIT_SECONDS: "15"', self.source)
        self.assertEqual(self.source.count('for _ in $(seq 1 "$WAIT_ATTEMPTS")'), 2)
        self.assertIn("latest_check_id > before_check_id", self.source)
        self.assertIn('rerequest_conclusion" != "success"', self.source)

    def test_workflow_has_no_extra_secret_or_quality_gate_bypass(self):
        self.assertIn("GITHUB_TOKEN: ${{ github.token }}", self.source)
        self.assertNotRegex(self.source, re.compile(r"\$\{\{\s*secrets\."))
        for forbidden in (
            "SONAR_TOKEN",
            "personal access token",
            "git commit",
            "git push",
            "qualitygate.wait=false",
            "sonar.exclusions",
            "continue-on-error: true",
        ):
            self.assertNotIn(forbidden, self.source)


if __name__ == "__main__":
    unittest.main()
