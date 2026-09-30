import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class RequirementIntakeCoreTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "requirement_intake_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=60,
        )
        cls.d = json.loads(completed.stdout)

    def test_free_text_normalizes_unknowns_without_inventing_facts(self):
        draft = self.d["free"]
        self.assertEqual(draft["problem"], {"status": "known", "value": "Orders are tracked manually"})
        self.assertEqual(draft["user"], {"status": "known", "value": "operations team"})
        self.assertEqual(draft["client"], {"status": "unknown", "value": None})
        self.assertEqual(draft["budget"], {"status": "unknown", "value": None})
        self.assertEqual(draft["deadline"], {"status": "unknown", "value": None})
        self.assertEqual(draft["constraints"], {"status": "unknown", "values": []})
        self.assertRegex(draft["fingerprint"], r"^[0-9a-f]{64}$")

    def test_text_and_transcript_share_contract_without_audio_retention(self):
        text = self.d["text"]
        transcript = self.d["transcript"]
        self.assertEqual(set(text), set(transcript))
        self.assertEqual(text["fingerprint"], transcript["fingerprint"])
        self.assertEqual(text["draft_ref"], transcript["draft_ref"])
        self.assertEqual(text["problem"], transcript["problem"])
        self.assertEqual(text["objectives"], transcript["objectives"])
        for draft in (text, transcript):
            self.assertNotIn("audio", draft)
            self.assertNotIn("raw_audio", draft)

    def test_analysis_builds_epic_proposal_with_risks_dependencies_questions_and_acceptance_slices(self):
        proposal = self.d["proposal"]
        for field in (
            "problem", "user", "objectives", "out_of_scope", "risks",
            "dependencies", "questions", "slices", "project_match",
        ):
            self.assertIn(field, proposal)
        self.assertGreaterEqual(len(proposal["slices"]), 1)
        self.assertGreaterEqual(len(proposal["slices"][0]["acceptance"]), 1)
        self.assertFalse(proposal["execution"])
        self.assertTrue(proposal["requires_approval"])
        self.assertIn("budget", " ".join(proposal["questions"]).lower())

    def test_existing_project_match_is_link_only_not_auto_create(self):
        match = self.d["proposal"]["project_match"]
        self.assertEqual(match["status"], "matched")
        self.assertEqual(match["project_ref"], "controlbot:project/controlbot")
        self.assertEqual(match["action"], "link_proposal")
        self.assertFalse(match["auto_create"])

    def test_secrets_are_redacted_from_draft_and_proposal(self):
        draft = self.d["secret_draft"]
        proposal = self.d["secret_proposal"]
        payload = json.dumps({"draft": draft, "proposal": proposal}).lower()
        for secret in ("hunter2", "supersecrettoken", "abcdefghijklmnopqrstuvwxyz"):
            self.assertNotIn(secret, payload)
        self.assertGreaterEqual(draft["redaction_count"], 3)
        self.assertIn("[redacted]", draft["intent"].lower())

    def test_core_has_no_external_io_or_mutations(self):
        source = (ROOT / "src" / "RequirementIntake.php").read_text(encoding="utf-8").lower()
        forbidden = (
            "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
            "unlink(", "rename(", "shell_exec", "proc_open", "exec(", "system(",
            "mail(", "octokit", "http_client",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__ == "__main__":
    unittest.main()
