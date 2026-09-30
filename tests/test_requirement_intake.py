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


def intake_scenario():
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "requirement_intake_scenarios.php")],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=60,
    )
    return json.loads(run.stdout)


class RequirementIntakeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.intake = intake_scenario()

    def test_free_text_normalizes_unknowns_without_inventing_facts(self):
        draft = self.intake["free"]
        self.assertEqual(draft["problem"], {"status": "known", "value": "Orders are tracked manually"})
        self.assertEqual(draft["user"], {"status": "known", "value": "operations team"})
        self.assertEqual(draft["client"], {"status": "unknown", "value": None})
        self.assertEqual(draft["budget"], {"status": "unknown", "value": None})
        self.assertEqual(draft["deadline"], {"status": "unknown", "value": None})
        self.assertEqual(draft["constraints"], {"status": "unknown", "values": []})
        self.assertRegex(draft["fingerprint"], r"^[0-9a-f]{64}$")

    def test_dictation_and_text_share_contract_without_requiring_audio_retention(self):
        text = self.intake["text"]
        transcript = self.intake["transcript"]
        self.assertEqual(set(text), set(transcript))
        self.assertEqual(text["fingerprint"], transcript["fingerprint"])
        self.assertEqual(text["draft_ref"], transcript["draft_ref"])
        self.assertEqual(text["problem"], transcript["problem"])
        self.assertEqual(text["objectives"], transcript["objectives"])
        for draft in (text, transcript):
            self.assertNotIn("audio", draft)
            self.assertNotIn("raw_audio", draft)

    def test_analysis_builds_epic_proposal_with_risks_dependencies_and_acceptance_slices(self):
        proposal = self.intake["proposal"]
        for field in (
            "problem", "user", "objectives", "out_of_scope", "risks",
            "dependencies", "questions", "slices", "project_match",
        ):
            self.assertIn(field, proposal)
        self.assertGreaterEqual(len(proposal["slices"]), 1)
        self.assertGreaterEqual(len(proposal["slices"][0]["acceptance"]), 1)
        self.assertFalse(proposal["execution"])
        self.assertTrue(proposal["requires_approval"])

    def test_existing_project_match_does_not_auto_create_duplicate(self):
        match = self.intake["proposal"]["project_match"]
        self.assertEqual(match["status"], "matched")
        self.assertEqual(match["project_ref"], "controlbot:project/controlbot")
        self.assertEqual(match["action"], "link_proposal")
        self.assertFalse(match["auto_create"])

    def test_unapproved_proposal_has_no_repo_deploy_or_mutating_side_effects(self):
        proposal = self.intake["proposal"]
        self.assertTrue(proposal["requires_approval"])
        self.assertFalse(proposal["execution"])
        source = (ROOT / "src" / "RequirementIntake.php").read_text(encoding="utf-8").lower()
        forbidden = (
            "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
            "unlink(", "rename(", "shell_exec", "proc_open", "exec(", "system(",
            "mail(", "octokit", "http_client", "workflow_dispatch",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))

    def test_approved_materialization_is_idempotent(self):
        data = scenario("idempotent")
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(data["counts"], {"link": 0, "create": 1, "epic": 1, "issue": 1})
        self.assertTrue(data["first"]["replay_safe"])

    def test_intake_redacts_secrets_and_minimizes_transcript_data(self):
        draft = self.intake["secret_draft"]
        proposal = self.intake["secret_proposal"]
        payload = json.dumps({"draft": draft, "proposal": proposal}).lower()
        for secret in ("hunter2", "supersecrettoken", "abcdefghijklmnopqrstuvwxyz"):
            self.assertNotIn(secret, payload)
        self.assertGreaterEqual(draft["redaction_count"], 3)
        self.assertNotIn("content", draft)
        self.assertNotIn("audio", draft)
        self.assertNotIn("raw_audio", draft)
        self.assertNotIn("audio_blob", proposal)
        self.assertNotIn("transcript_text", proposal)

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
