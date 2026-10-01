import importlib.util
import tempfile
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "generate_coverage",
    ROOT / "scripts" / "generate_coverage.py",
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("No fue posible cargar generate_coverage.py")
GENERATE_COVERAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GENERATE_COVERAGE)


class GenerateCoverageTests(unittest.TestCase):
    def setUp(self):
        self.tempdir = tempfile.TemporaryDirectory()
        self.original_clover = GENERATE_COVERAGE.PHP_CLOVER
        GENERATE_COVERAGE.PHP_CLOVER = (
            Path(self.tempdir.name) / "php-clover.xml"
        )

    def tearDown(self):
        GENERATE_COVERAGE.PHP_CLOVER = self.original_clover
        self.tempdir.cleanup()
    def _render_clover(self):
        source = (ROOT / "scripts" / "php_coverage_bootstrap.php").resolve()
        GENERATE_COVERAGE.write_php_clover(
            {source: {1: 1, 2: -1}}
        )
        tree = ET.parse(GENERATE_COVERAGE.PHP_CLOVER)
        return source, tree.getroot()

    def test_php_clover_uses_resolvable_checkout_paths(self):
        source, root = self._render_clover()
        file_node = root.find("./project/package/file")
        self.assertIsNotNone(file_node)

        emitted = Path(file_node.attrib["name"])
        self.assertTrue(emitted.is_absolute())
        self.assertTrue(emitted.exists())
        self.assertEqual(emitted.resolve(), source)
        self.assertTrue(
            emitted.resolve().is_relative_to(ROOT.resolve())
        )

    def test_php_clover_preserves_statement_metrics(self):
        _, root = self._render_clover()
        file_metrics = root.find("./project/package/file/metrics")
        project_metrics = root.find("./project/metrics")

        self.assertIsNotNone(file_metrics)
        self.assertIsNotNone(project_metrics)
        self.assertEqual(file_metrics.attrib["statements"], "2")
        self.assertEqual(file_metrics.attrib["coveredstatements"], "1")
        self.assertEqual(project_metrics.attrib["statements"], "2")
        self.assertEqual(project_metrics.attrib["coveredstatements"], "1")


if __name__ == "__main__":
    unittest.main()
