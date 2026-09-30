import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class RequirementDecisionUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "requirement_intake_decision_ui_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=60,
        )
        cls.d = json.loads(completed.stdout)

    def test_view_shows_summary_impacts_questions_and_match_without_inventing_facts(self):
        view = self.d["view"]
        proposal = self.d["proposal"]
        self.assertEqual(view["summary"]["problem"], proposal["problem"])
        self.assertEqual(view["summary"]["user"], proposal["user"])
        self.assertEqual(view["summary"]["objectives"], proposal["objectives"])
        self.assertEqual(view["impacts"]["risks"], proposal["risks"])
        self.assertEqual(view["impacts"]["dependencies"], proposal["dependencies"])
        self.assertEqual(view["impacts"]["questions"], proposal["questions"])
        self.assertEqual(view["impacts"]["project_match"], proposal["project_match"])
        unknown = self.d["unknown_view"]
        self.assertIn("objectives", unknown["summary"]["unknown_fields"])
        self.assertEqual(unknown["summary"]["objectives"], {"status": "unknown", "values": []})
        self.assertEqual(unknown["summary"]["out_of_scope"], {"status": "unknown", "values": []})

    def test_materialization_diff_is_exact_and_non_mutating(self):
        diff = self.d["view"]["materialization_diff"]
        self.assertEqual(set(diff), {"project", "epic", "issues"})
        self.assertEqual(diff["project"]["artifact"], "project")
        self.assertEqual(diff["project"]["operation"], "link_existing")
        self.assertEqual(diff["project"]["project_ref"], "controlbot:project/controlbot")
        self.assertEqual(diff["epic"]["artifact"], "epic")
        self.assertEqual(diff["epic"]["operation"], "propose_create")
        self.assertEqual(len(diff["issues"]), len(self.d["proposal"]["slices"]))
        self.assertTrue(all(item["artifact"] == "issue" for item in diff["issues"]))
        self.assertTrue(all(item["operation"] == "propose_create" for item in diff["issues"]))
        serialized = json.dumps(diff)
        self.assertNotIn('"execution": true', serialized.lower())

    def test_decision_options_are_typed_and_do_not_execute(self):
        options = self.d["view"]["decision_options"]
        self.assertEqual([item["code"] for item in options], ["approve", "revise", "reject"])
        self.assertEqual(
            [item["intent"] for item in options],
            ["allow_later_materialization", "request_changes", "decline_proposal"],
        )
        self.assertTrue(all(item["execution"] is False for item in options))
        self.assertFalse(self.d["view"]["execution"])

    def test_view_never_exposes_secrets_audio_or_raw_transcript(self):
        payload = json.dumps(self.d["secret_view"]).lower()
        for secret in ("hunter2", "supersecrettoken", "abcdefghijklmnopqrstuvwxyz"):
            self.assertNotIn(secret, payload)
        for forbidden in ("raw_transcript", "raw_audio", "audio_blob", "transcript_text"):
            self.assertNotIn(forbidden, payload)
        self.assertNotIn("[redacted]", payload)

    def test_view_preserves_proposal_identity_and_provenance(self):
        view = self.d["view"]
        proposal = self.d["proposal"]
        self.assertEqual(view["proposal_ref"], proposal["proposal_ref"])
        self.assertEqual(view["proposal_fingerprint"], proposal["fingerprint"])
        self.assertEqual(view["provenance"], {"draft_ref": proposal["draft_ref"]})
        self.assertRegex(view["view_ref"], r"^requirement-decision-view:[0-9a-f]{40}$")
        self.assertRegex(view["fingerprint"], r"^[0-9a-f]{64}$")
        self.assertEqual(view, self.d["replay"])

    def test_mobile_and_desktop_offer_accessible_decision_controls(self):
        surfaces = self.d["view"]["surfaces"]
        self.assertEqual(set(surfaces), {"desktop", "mobile"})
        for surface in surfaces.values():
            self.assertEqual(surface["controls"], ["approve", "revise", "reject"])
            self.assertTrue(surface["keyboard"])
            self.assertFalse(surface["gesture_only"])
            self.assertEqual(surface["sections"], ["summary", "impacts", "materialization_diff", "decision"])

    def test_projection_has_no_external_io_or_actions(self):
        source = (ROOT / "src" / "RequirementIntakeDecisionUi.php").read_text(encoding="utf-8").lower()
        forbidden = (
            "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
            "unlink(", "rename(", "shell_exec", "proc_open", "exec(", "system(",
            "mail(", "octokit", "http_client", "github", "workflow_dispatch",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__ == "__main__":
    unittest.main()
