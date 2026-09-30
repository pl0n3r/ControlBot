import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "requirement_materialization_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=60,
    )
    return json.loads(run.stdout)


class RequirementIntakeTests(unittest.TestCase):
    def test_approved_materialization_is_idempotent(self):
        data = scenario("idempotent")
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(data["counts"], {"link": 0, "create": 1, "epic": 1, "issue": 1})
        self.assertTrue(data["first"]["replay_safe"])

    def test_materialization_rejects_unapproved_stale_or_unbound_decision(self):
        data = scenario("rejected")
        self.assertEqual(data["blocked"], [True, True, True])
        self.assertEqual(data["mutations"], [0, 0, 0])

    def test_existing_project_is_linked_without_duplicate_creation(self):
        data = scenario("existing")
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(data["first"]["project_ref"], "controlbot:project/controlbot")
        self.assertEqual(data["counts"], {"link": 1, "create": 0, "epic": 1, "issue": 1})

    def test_project_creation_requires_exact_approved_diff(self):
        data = scenario("project-choice")
        self.assertTrue(data["missing_blocked"])
        self.assertTrue(data["ambiguous_blocked"])
        self.assertEqual(data["ambiguous_mutations"], 0)
        self.assertEqual(data["receipt"]["project_ref"], "controlbot:project/new-venture")
        self.assertEqual(data["counts"]["create"], 1)

    def test_epic_and_issue_receipts_are_stable_across_retry(self):
        data = scenario("stable")
        self.assertEqual(data["first"]["receipt_ref"], data["second"]["receipt_ref"])
        self.assertEqual(data["first"]["epic_ref"], data["second"]["epic_ref"])
        self.assertEqual(data["first"]["issues"], data["second"]["issues"])
        self.assertEqual(len(data["first"]["idempotency_keys"]["project"]), 64)
        self.assertEqual(len(data["first"]["issues"][0]["idempotency_key"]), 64)

    def test_materialization_receipt_is_minimized_and_secret_free(self):
        data = scenario("receipt")
        receipt = data["receipt"]
        self.assertTrue(data["secret_blocked"])
        self.assertEqual(
            set(receipt),
            {"receipt_ref", "version", "proposal_ref", "proposal_fingerprint", "decision_ref",
             "decision_fingerprint", "project_ref", "epic_ref", "issues", "idempotency_keys", "replay_safe"},
        )
        serialized = json.dumps(receipt).lower()
        for forbidden in ("raw_transcript", "raw_audio", "password=", "bearer ", "github_pat_"):
            self.assertNotIn(forbidden, serialized)

    def test_materializer_has_no_unauthorized_io_or_actions(self):
        source = (ROOT / "src" / "RequirementMaterializer.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents", "fopen(",
            "unlink(", "rename(", "shell_exec", "proc_open", "system(", "exec(",
            "workflow_dispatch", "dispatchworkflow", "movetag(", "commentissue(",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("interface requirementmaterializationgateway", source)
        self.assertIn("ownercontext", source)
        self.assertIn("projectmodel::normalize", source)


if __name__ == "__main__":
    unittest.main()
