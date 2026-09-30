import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


class WeeklyFocusTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        run=subprocess.run(
            ["php",str(ROOT/"tests"/"weekly_focus_scenarios.php")],
            cwd=ROOT,check=True,text=True,capture_output=True,
        )
        cls.data=json.loads(run.stdout)

    def test_focus_metadata_cannot_make_low_priority_outrank_high(self):
        self.assertTrue(self.data["low_cannot_win"])

    def test_weekly_focus_does_not_create_parallel_selection_authority(self):
        self.assertTrue(self.data["parallel_authority_absent"])
        first=self.data["first"]
        self.assertNotIn("selected_key",first)
        self.assertNotIn("policy_rank",first)
        self.assertEqual(first["preferred_key"],"work-b")

    def test_reorder_changes_next_scheduler_choice_when_policy_ties(self):
        self.assertEqual(self.data["first"]["preferred_key"],"work-b")
        self.assertEqual(self.data["second"]["preferred_key"],"work-a")
        self.assertEqual(self.data["first"]["priority"],self.data["second"]["priority"])
        self.assertTrue(self.data["first"]["focus_influenced"])

    def test_focus_never_outranks_incident_or_makes_blocked_item_eligible(self):
        self.assertTrue(self.data["critical_protected"])
        self.assertEqual(self.data["blocked"]["preferred_key"],"work-b")

    def test_history_reconstructs_focus_active_at_dispatch_time(self):
        self.assertEqual(self.data["history_150"]["version"],1)
        self.assertEqual(self.data["history_150"]["ordered_refs"][0],"controlbot:project/beta")
        self.assertEqual(self.data["history_250"]["version"],2)
        self.assertEqual(self.data["history_250"]["ordered_refs"][0],"controlbot:project/alpha")
        self.assertEqual(self.data["first"]["focus_version"],1)
        self.assertEqual(self.data["first"]["focus_position"],1)
        self.assertEqual(self.data["first"]["preference_reason"],"weekly_focus_tiebreak")
        self.assertRegex(self.data["first"]["request_fingerprint"],r"^[0-9a-f]{64}$")
        self.assertTrue(self.data["conflict_rejected"])
        self.assertEqual(self.data["unavailable"]["preferred_key"],"work-b")
        self.assertIn("controlbot:project/alpha",self.data["history_150"]["ordered_refs"])

    def test_focus_rejects_untyped_issue_reference(self):
        self.assertTrue(self.data["untyped_rejected"])

    def test_shared_focus_position_does_not_claim_influence(self):
        shared=self.data["shared"]
        self.assertEqual(shared["preferred_key"],"work-a")
        self.assertEqual(shared["focus_position"],1)
        self.assertFalse(shared["focus_influenced"])
        self.assertEqual(shared["preference_reason"],"canonical_cohort_order")

    def test_weekly_focus_has_no_external_io_or_actions(self):
        source=(ROOT/"src"/"WeeklyFocus.php").read_text(encoding="utf-8").lower()
        forbidden=(
            "curl_","fsockopen","new pdo","mysqli","file_put_contents","shell_exec",
            "proc_open","exec(","system(","mail(","unlink(","rename(","schedulerassignmentcommit",
            "'selected_key'=>","'policy_rank'=>",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__=="__main__":
    unittest.main()
