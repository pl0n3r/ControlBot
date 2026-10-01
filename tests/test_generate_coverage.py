import importlib.util
import tempfile
from pathlib import Path
import unittest
from unittest.mock import patch
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
        self.original_generic = GENERATE_COVERAGE.PHP_GENERIC
        GENERATE_COVERAGE.PHP_GENERIC = (
            Path(self.tempdir.name) / "php-generic.xml"
        )

    def tearDown(self):
        GENERATE_COVERAGE.PHP_GENERIC = self.original_generic
        self.tempdir.cleanup()

    def _render_generic(self):
        source = (ROOT / "scripts" / "php_coverage_bootstrap.php").resolve()
        GENERATE_COVERAGE.write_php_generic(
            {source: {1: 1, 2: -1, 3: 0}}
        )
        tree = ET.parse(GENERATE_COVERAGE.PHP_GENERIC)
        return source, tree.getroot()

    def test_php_generic_uses_relative_checkout_paths(self):
        source, root = self._render_generic()
        file_node = root.find("./file")
        self.assertIsNotNone(file_node)
        self.assertEqual(root.tag, "coverage")
        self.assertEqual(root.attrib["version"], "1")

        emitted = Path(file_node.attrib["path"])
        self.assertFalse(emitted.is_absolute())
        self.assertNotIn("..", emitted.parts)
        self.assertEqual((ROOT / emitted).resolve(), source)

    def test_php_generic_preserves_line_coverage(self):
        _, root = self._render_generic()
        line_nodes = root.findall("./file/lineToCover")
        self.assertEqual(
            [
                (node.attrib["lineNumber"], node.attrib["covered"])
                for node in line_nodes
            ],
            [("1", "true"), ("2", "false")],
        )

    def test_php_generic_rejects_empty_executable_coverage(self):
        source = (ROOT / "scripts" / "php_coverage_bootstrap.php").resolve()
        with self.assertRaisesRegex(
            RuntimeError,
            "Cobertura PHP genérica quedó sin líneas ejecutables",
        ):
            GENERATE_COVERAGE.write_php_generic({source: {1: 0}})

    def test_main_writes_expected_reports(self):
        temp = Path(self.tempdir.name)
        python_xml = temp / "python.xml"
        php_generic = temp / "php-generic.xml"
        build = temp / "build"
        source = temp / "source.php"
        source.write_text("<?php\n", encoding="utf-8")

        def fake_run(command, *, env=None):
            if "xml" in command:
                python_xml.write_text("<coverage/>\n", encoding="utf-8")

        with (
            patch.object(GENERATE_COVERAGE, "ROOT", temp),
            patch.object(GENERATE_COVERAGE, "BUILD", build),
            patch.object(GENERATE_COVERAGE, "PYTHON_XML", python_xml),
            patch.object(GENERATE_COVERAGE, "PHP_GENERIC", php_generic),
            patch.object(GENERATE_COVERAGE, "run", side_effect=fake_run),
            patch.object(
                GENERATE_COVERAGE,
                "coverage_environment",
                return_value={},
            ),
            patch.object(
                GENERATE_COVERAGE,
                "aggregate_php",
                return_value={source: {1: 1, 2: -1}},
            ),
        ):
            self.assertEqual(GENERATE_COVERAGE.main(), 0)

        self.assertTrue(python_xml.is_file())
        self.assertTrue(php_generic.is_file())

    def test_main_fails_closed_when_generic_report_is_missing(self):
        temp = Path(self.tempdir.name)
        python_xml = temp / "python.xml"
        php_generic = temp / "php-generic.xml"
        build = temp / "build"

        def fake_run(command, *, env=None):
            if "xml" in command:
                python_xml.write_text("<coverage/>\n", encoding="utf-8")

        with (
            patch.object(GENERATE_COVERAGE, "ROOT", temp),
            patch.object(GENERATE_COVERAGE, "BUILD", build),
            patch.object(GENERATE_COVERAGE, "PYTHON_XML", python_xml),
            patch.object(GENERATE_COVERAGE, "PHP_GENERIC", php_generic),
            patch.object(GENERATE_COVERAGE, "run", side_effect=fake_run),
            patch.object(
                GENERATE_COVERAGE,
                "coverage_environment",
                return_value={},
            ),
            patch.object(GENERATE_COVERAGE, "aggregate_php", return_value={}),
            patch.object(GENERATE_COVERAGE, "write_php_generic"),
        ):
            with self.assertRaisesRegex(
                RuntimeError,
                "Faltan reportes de cobertura esperados",
            ):
                GENERATE_COVERAGE.main()


if __name__ == "__main__":
    unittest.main()
