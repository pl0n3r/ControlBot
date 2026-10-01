import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class SonarCoverageContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        cls.sonar = (ROOT / ".github/workflows/sonar.yml").read_text(encoding="utf-8")
        cls.generator = (ROOT / "scripts/generate_coverage.py").read_text(encoding="utf-8")
        cls.bootstrap = (ROOT / "scripts/php_coverage_bootstrap.php").read_text(encoding="utf-8")

    def test_ci_and_sonar_generate_python_and_php_coverage(self):
        for workflow in (self.ci, self.sonar):
            self.assertIn("coverage: xdebug", workflow)
            self.assertIn("coverage==7.16.2", workflow)
            self.assertIn("python3 scripts/generate_coverage.py", workflow)
        self.assertIn('"python.xml"', self.generator)
        self.assertIn('"php-generic.xml"', self.generator)
        self.assertIn("xdebug_start_code_coverage", self.bootstrap)
        self.assertIn("XDEBUG_FILTER_CODE_COVERAGE", self.bootstrap)

    def test_sonar_consumes_generic_php_coverage_and_verifies_metric(self):
        self.assertIn("-Dsonar.sources=src,scripts", self.sonar)
        self.assertIn("-Dsonar.tests=tests", self.sonar)
        self.assertIn(
            "-Dsonar.python.coverage.reportPaths=build/coverage/python.xml",
            self.sonar,
        )
        self.assertIn(
            "-Dsonar.coverageReportPaths=build/coverage/php-generic.xml",
            self.sonar,
        )
        self.assertNotIn("sonar.php.coverage.reportPaths", self.sonar)
        self.assertIn("name: Sonar Coverage = published", self.sonar)
        self.assertIn("/api/measures/component_tree", self.sonar)
        self.assertIn("SONAR_PHP_PATH", self.sonar)
        self.assertIn('"lines_to_cover"', self.sonar)
        self.assertIn("-Dsonar.qualitygate.wait=true", self.sonar)
        self.assertNotIn("sonar.coverage.exclusions", self.sonar)
        self.assertNotIn("sonar.qualitygate.wait=false", self.sonar)

    def test_coverage_collection_stays_test_only_and_secret_free(self):
        combined = self.generator + self.bootstrap
        self.assertIn("CONTROLBOT_REAL_PHP", self.generator)
        self.assertIn("CONTROLBOT_PHP_COVERAGE_DIR", combined)
        self.assertIn('{"tests", "vendor", "build"}', self.generator)
        self.assertIn("path.relative_to(ROOT).as_posix()", self.generator)
        self.assertIn('"lineToCover"', self.generator)
        self.assertNotIn("php-clover.xml", self.generator)
        for forbidden in (
            "SONAR_TOKEN",
            "password=",
            "Authorization:",
            "curl ",
            "requests.",
            "urllib.request",
        ):
            self.assertNotIn(forbidden, combined)


if __name__ == "__main__":
    unittest.main()
