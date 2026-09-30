import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "prompt_registry_scenarios.php"


def scenario(name):
    payload = subprocess.check_output(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(payload)


class PromptRegistryTests(unittest.TestCase):
    def test_edits_require_new_immutable_version(self):
        data = scenario("immutable")
        self.assertEqual(data["count"], 2)
        self.assertTrue(data["first_unchanged"])
        self.assertEqual(data["versions"], [1, 2])
        self.assertEqual(data["second_status"], "candidate")

    def test_prompt_edits_create_immutable_versioned_records(self):
        self.test_edits_require_new_immutable_version()

    def test_normal_selection_uses_only_approved_version_and_fails_closed_without_one(self):
        data = scenario("approved")
        self.assertEqual(data["selected"]["version"], 1)
        self.assertEqual(data["selected"]["status"], "approved")
        self.assertTrue(data["missing_fails_closed"])

    def test_dispatcher_uses_only_approved_versions(self):
        self.test_normal_selection_uses_only_approved_version_and_fails_closed_without_one()

    def test_invalid_duplicate_extra_state_ref_timestamp_or_schema_fails_closed(self):
        data = scenario("invalid")
        self.assertTrue(all(data.values()))

    def test_rollback_selects_prior_approved_without_rewriting_history(self):
        data = scenario("rollback")
        self.assertEqual(data["current"], 3)
        self.assertEqual(data["rollback"], 1)
        self.assertTrue(data["history_unchanged"])
        self.assertTrue(data["non_approved_rejected"])

    def test_rollback_selects_prior_approved_version_without_rewriting_history(self):
        self.test_rollback_selects_prior_approved_without_rewriting_history()

    def test_secret_like_template_variables_or_metadata_are_rejected(self):
        data = scenario("secret")
        self.assertTrue(all(data.values()))

    def test_templates_variables_and_evidence_are_secret_free(self):
        self.test_secret_like_template_variables_or_metadata_are_rejected()

    def test_registry_is_deterministic_and_external_io_free(self):
        first = scenario("immutable")
        second = scenario("immutable")
        self.assertEqual(first, second)

        pure = scenario("pure")
        self.assertEqual(pure["methods"], ["active", "register", "rollback"])
        source = pure["source"].lower()
        for forbidden in (
            "new pdo",
            "mysqli_connect(",
            "curl_",
            "http://",
            "https://",
            "file_put_contents",
            "fopen(",
            "shell_exec",
            "exec(",
            "proc_open",
            "factoryrunner",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
