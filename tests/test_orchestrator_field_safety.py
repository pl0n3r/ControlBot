"""Hermetic boundary tests for preparatory ControlBot guard; no live integration."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest


class FieldSafetyTests(unittest.TestCase):
    @staticmethod
    def report(scenario=None):
        php = shutil.which("php")
        if not php:
            raise AssertionError("Real PHP CLI is mandatory for acceptance")
        file = Path(__file__).with_name("orchestrator_field_safety_scenarios.php")
        result = subprocess.run([php, str(file)] + ([scenario] if scenario else []), capture_output=True, text=True, timeout=12)
        if result.returncode:
            raise AssertionError(f"PHP scenarios failed: {result.stderr}")
        return json.loads(result.stdout)

    def test_php_canonical_field_limits_and_pii(self):
        report = self.report()
        self.assertEqual(27, report["total"])
        self.assertEqual(21, report["negatives"])
        self.assertEqual([], report["unexpected"])
        for name, actual in report["cases"].items():
            self.assertEqual(name.startswith("valid_"), actual, name)

    def test_private_identifiers_and_sources_rejected(self):
        cases = self.report()["cases"]
        for name in (
            "id_241_bytes", "email_id", "sensitive_id", "sensitive_front_ref",
            "email_front_ref", "phone_front_ref", "original_length_241_front_ref",
            "decision_id_email", "decision_source_token",
        ):
            self.assertIn(name, cases)
            self.assertFalse(cases[name], name)

    def test_decision_bounds_and_positive_controls(self):
        cases = self.report()["cases"]
        for name in ("valid_projection", "valid_600_effect", "valid_200_label",
                     "valid_300_pro", "valid_160_title", "valid_161_byte_id"):
            self.assertTrue(cases[name], name)
        for name in (
            "decision_title_161", "title_simple_161", "summary_simple_601",
            "label_201", "effect_601", "risk_81", "cost_241",
            "option_explain_401", "pros_item_301", "cons_item_301",
            "pros_seven", "pros_control",
        ):
            self.assertFalse(cases[name], name)

    def test_projection_shapes_and_legacy_fail_closed(self):
        report = self.report("shape")
        self.assertEqual(16, report["total"])
        self.assertEqual(3, report["positives"])
        self.assertEqual([], report["unexpected"])
        for name, accepted in report["cases"].items():
            self.assertEqual(name.startswith("valid_"), accepted, name)

    def test_guard_is_pure_without_runtime_io(self):
        report = self.report()
        self.assertTrue(report["pure_no_side_effects"])
        source = (Path(__file__).parent.parent / "src" / "FactoryOrchestratorFieldSafety.php").read_text()
        for dangerous_call in (
            "file_get_contents(", "file_put_contents(", "fopen(", "fwrite(",
            "curl_init(", "exec(", "shell_exec(", "system(", "proc_open(",
            "eval(", "mail(", "header(", "getenv(",
        ):
            self.assertNotIn(dangerous_call, source, dangerous_call)
        self.assertIn("public static function assertProjection", source)


if __name__ == "__main__":
    unittest.main()
