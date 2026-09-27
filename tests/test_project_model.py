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

    def test_approved_idea_can_provision_governed_repository_without_manual_file_edits(self):
        data = scenario("provision")
        self.assertEqual(data["first"]["status"], "requested")
        self.assertTrue(data["first"]["dispatched"])
        self.assertEqual(data["dispatches"], 1)
        self.assertEqual(data["inputs"]["governance_ref"], "pl0n3r/factory@v1")
        self.assertEqual(data["inputs"]["target_repository"], "pl0n3r/NewProduct")
        self.assertEqual(len(data["inputs"]["idempotency_key"]), 64)
        self.assertEqual(
            [row["repository"] for row in data["confirmed_project"]["repositories"]],
            ["pl0n3r/NewProduct"],
        )
        self.assertEqual(data["second"]["status"], "confirmed")
        self.assertFalse(data["second"]["dispatched"])
        self.assertTrue(scenario("provision-unapproved")["blocked"])

        real = scenario("provision-real-adapter")
        self.assertEqual(real["result"]["status"], "requested")
        self.assertEqual(len(real["calls"]), 1)
        method, url, _headers, body = real["calls"][0]
        self.assertEqual(method, "POST")
        self.assertEqual(
            url,
            "https://api.github.com/repos/pl0n3r/factory/actions/workflows/provision-project.yml/dispatches",
        )
        payload = json.loads(body)
        self.assertEqual(payload["ref"], "main")
        self.assertEqual(
            set(payload["inputs"]),
            {
                "project_id",
                "project_slug",
                "target_repository",
                "governance_ref",
                "idempotency_key",
            },
        )
        self.assertEqual(payload["inputs"]["governance_ref"], "pl0n3r/factory@v1")
        self.assertEqual(payload["inputs"]["target_repository"], "pl0n3r/NewProduct")

        invalid = scenario("provision-real-adapter-invalid")
        self.assertEqual(invalid["blocked"], [True, True])
        self.assertEqual(invalid["calls"], [])

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

    def test_existing_projects_backfill_idempotently(self):
        data = scenario("backfill")
        self.assertEqual(data["first"], data["second"])
        self.assertTrue(scenario("backfill-conflict")["blocked"])
        self.assertEqual(
            [project["project_id"] for project in data["first"]],
            ["project-brvtal", "project-condor", "project-controlbot", "project-grindflow"],
        )
        repositories = {
            project["project_id"]: project["repositories"][0]["repository"]
            for project in data["first"]
        }
        self.assertEqual(
            repositories,
            {
                "project-brvtal": "pl0n3r/brvtal",
                "project-condor": "pl0n3r/Condor",
                "project-controlbot": "pl0n3r/ControlBot",
                "project-grindflow": "pl0n3r/GrindFlow",
            },
        )


if __name__ == "__main__":
    unittest.main()
