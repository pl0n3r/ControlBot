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

    def test_reorder_changes_next_scheduler_choice_when_policy_ties(self):
        self.assertEqual(self.data["first"]["selected_key"],"work-b")
        self.assertEqual(self.data["second"]["selected_key"],"work-a")
        self.assertEqual(self.data["first"]["priority"],self.data["second"]["priority"])
        self.assertTrue(self.data["first"]["focus_influenced"])

    def test_focus_never_outranks_incident_or_makes_blocked_item_eligible(self):
        self.assertEqual(self.data["precedence"]["selected_key"],"work-incident")
        self.assertEqual(self.data["precedence"]["policy_rank"],0)
        self.assertEqual(self.data["blocked"]["selected_key"],"work-b")

    def test_scheduler_records_focus_version_position_and_reason(self):
        first=self.data["first"]
        self.assertEqual(first["focus_version"],1)
        self.assertEqual(first["focus_position"],0)
        self.assertEqual(first["selection_reason"],"weekly_focus_tiebreak")
        self.assertRegex(first["request_fingerprint"],r"^[0-9a-f]{64}$")

    def test_concurrent_edits_use_version_conflict_instead_of_lost_update(self):
        self.assertTrue(self.data["conflict_rejected"])
        self.assertEqual(self.data["second"]["focus_version"],2)

    def test_unavailable_project_remains_visible_but_not_dispatchable(self):
        self.assertEqual(self.data["unavailable"]["selected_key"],"work-b")
        self.assertIn("controlbot:project/alpha",self.data["history_150"]["ordered_refs"])

    def test_focus_change_affects_only_future_dispatches(self):
        self.assertEqual(self.data["clear"]["ordered_refs"],[])
        self.assertEqual(self.data["clear"]["version"],3)
        self.assertEqual(self.data["clear_selection"]["selected_key"],"work-a")
        source=(ROOT/"src"/"WeeklyFocus.php").read_text(encoding="utf-8")
        for symbol in ("PauseControl","SchedulerAssignmentCommit","SchedulerRequeuePlan"):
            self.assertNotIn(symbol,source)

    def test_history_reconstructs_focus_active_at_dispatch_time(self):
        self.assertEqual(self.data["history_150"]["version"],1)
        self.assertEqual(self.data["history_150"]["ordered_refs"][0],"controlbot:project/beta")
        self.assertEqual(self.data["history_250"]["version"],2)
        self.assertEqual(self.data["history_250"]["ordered_refs"][0],"controlbot:project/alpha")

    def test_weekly_focus_has_no_external_io_or_actions(self):
        source=(ROOT/"src"/"WeeklyFocus.php").read_text(encoding="utf-8").lower()
        forbidden=(
            "curl_","fsockopen","new pdo","mysqli","file_put_contents","shell_exec",
            "proc_open","exec(","system(","mail(","unlink(","rename(","schedulerassignmentcommit",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__=="__main__":
    unittest.main()
