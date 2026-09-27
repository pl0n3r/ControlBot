import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "project_model_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)
class ProjectModelTests(unittest.TestCase):
    def test_project_can_exist_without_repository(self):
        data = scenario("empty")
        self.assertEqual(data["project_id"], "project-controlbot")
        self.assertEqual(data["repositories"], [])
        self.assertEqual(data["environments"], [])
    def test_project_supports_multiple_repositories_and_environments(self):
        data = scenario("multiple")
        self.assertEqual(len(data["repositories"]), 2)
        self.assertEqual(len(data["environments"]), 2)
        self.assertEqual(data["project_id"], "project-controlbot")
        self.assertTrue(scenario("duplicate")["blocked"])
        self.assertTrue(scenario("duplicate-environment")["blocked"])
        self.assertTrue(scenario("duplicate-environment-source")["blocked"])
        self.assertTrue(scenario("duplicate-repository-case")["blocked"])
    def test_aggregate_view_references_authoritative_sources_without_duplicating_state(self):
        data = scenario("aggregate")
        refs = data["aggregate_refs"]
        self.assertEqual(refs["roadmap"]["ref"], "https://github.com/pl0n3r/ControlBot/issues/1")
        self.assertEqual(refs["health"]["ref"], "controlbot:health/project-controlbot")
        self.assertEqual(set(refs["health"]), {"ref", "observed_at"})
        self.assertTrue(scenario("aggregate-state")["blocked"])
    def test_repository_reassociation_preserves_history_and_identity(self):
        data = scenario("reassociate")
        self.assertEqual(data["project_id"], "project-controlbot")
        self.assertEqual([row["repository"] for row in data["repositories"]], ["pl0n3r/ControlBot"])
        self.assertIn("https://github.com/pl0n3r/FactoryRunner", data["history_refs"])
        self.assertIn("controlbot:project/project-controlbot/created", data["history_refs"])

        renamed = scenario("reassociate-renamed")
        self.assertIn("https://github.com/pl0n3r/ControlBot", renamed["history_refs"])

        full = scenario("reassociate-full-history")
        self.assertEqual(full["repositories"], [])
        self.assertEqual(len(full["history_refs"]), 101)
        self.assertIn("https://github.com/pl0n3r/FactoryRunner", full["history_refs"])
if __name__ == "__main__":
    unittest.main()
