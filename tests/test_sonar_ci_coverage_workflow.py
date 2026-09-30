import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SONAR_WORKFLOW = ROOT / ".github" / "workflows" / "sonar.yml"
PHP_PREPEND = ROOT / "tests" / "sonar_php_coverage_prepend.php"
PHP_MERGE = ROOT / "tests" / "sonar_php_coverage_merge.py"


class SonarCiCoverageWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = SONAR_WORKFLOW.read_text(encoding="utf-8")
        cls.prepend = PHP_PREPEND.read_text(encoding="utf-8")
        cls.merge = PHP_MERGE.read_text(encoding="utf-8")

    def test_ci_generates_python_and_php_coverage_and_sonar_consumes_reports(self):
        workflow = self.workflow
        self.assertIn("coverage: xdebug", workflow)
        self.assertIn("coverage==7.6.12", workflow)
        self.assertIn("python3 -m coverage run --branch", workflow)
        self.assertIn("python3 -m coverage xml -o coverage-python.xml", workflow)
        self.assertIn(
            "python3 tests/sonar_php_coverage_merge.py .coverage/php coverage-php.xml",
            workflow,
        )
        self.assertIn("-Dsonar.tests=tests", workflow)
        self.assertIn("-Dsonar.python.coverage.reportPaths=coverage-python.xml", workflow)
        self.assertIn("-Dsonar.php.coverage.reportPaths=coverage-php.xml", workflow)
        self.assertIn("xdebug_start_code_coverage", self.prepend)
        self.assertIn("CONTROLBOT_PHP_COVERAGE_DIR", self.prepend)
        self.assertIn('fragment_dir.glob("coverage-*.json")', self.merge)
        self.assertIn('"coverage"', self.merge)
        self.assertIn('"project"', self.merge)

    def test_quality_gate_thresholds_are_not_relaxed_or_hidden(self):
        workflow = self.workflow
        self.assertIn("-Dsonar.qualitygate.wait=true", workflow)
        self.assertIn("-Dsonar.qualitygate.timeout=300", workflow)
        self.assertIn("test -s coverage-python.xml", workflow)
        self.assertIn("test -s coverage-php.xml", workflow)
        self.assertNotIn("continue-on-error: true", workflow)
        self.assertNotRegex(
            workflow,
            re.compile(r"sonar\.(?:exclusions|coverage\.exclusions|cpd\.exclusions)="),
        )
        self.assertNotRegex(workflow, re.compile(r"sonar\.qualitygate\.[^=\s]*=false"))


if __name__ == "__main__":
    unittest.main()
