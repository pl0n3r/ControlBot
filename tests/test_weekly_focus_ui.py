import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class WeeklyFocusUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "weekly_focus_ui_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=60,
        )
        cls.d = json.loads(completed.stdout)

    def test_mobile_and_desktop_support_accessible_reordering(self):
        view = self.d["view"]
        self.assertTrue(view["accessibility"]["desktop_drag"])
        self.assertTrue(view["accessibility"]["mobile_buttons"])
        self.assertTrue(view["accessibility"]["keyboard_buttons"])
        self.assertFalse(view["accessibility"]["drag_only"])
        for item in view["items"]:
            for action in ("move_up", "move_down"):
                self.assertTrue(item["actions"][action]["keyboard_focusable"])
                self.assertTrue(item["actions"][action]["focus_visible"])
                self.assertFalse(item["actions"][action]["requires_drag"])
        self.assertEqual(
            self.d["desktop"]["save_request"]["ordered_refs"],
            ["controlbot:epic/gamma", "controlbot:project/alpha", "controlbot:project/beta"],
        )
        self.assertEqual(
            self.d["mobile"]["save_request"]["ordered_refs"],
            ["controlbot:project/alpha", "controlbot:epic/gamma", "controlbot:project/beta"],
        )

    def test_unavailable_focus_items_remain_visible_with_reason(self):
        beta = next(item for item in self.d["view"]["items"] if item["ref"] == "controlbot:project/beta")
        self.assertEqual(beta["state"], "unavailable")
        self.assertEqual(beta["reason"], "blocked")
        self.assertFalse(beta["dispatchable"])
        self.assertEqual(len(self.d["view"]["items"]), 3)

    def test_version_conflict_requires_reconcile_instead_of_overwrite(self):
        conflict = self.d["conflict"]
        self.assertEqual(conflict["status"], "conflict")
        self.assertEqual(conflict["current_version"], 3)
        self.assertIsNone(conflict["save_request"])
        self.assertTrue(conflict["requires_refresh"])
        self.assertEqual(conflict["reconcile_action"], "reload_latest_focus")
        self.assertFalse(conflict["overwrite_on_conflict"])

    def test_clear_focus_does_not_imply_running_work_preemption(self):
        clear = self.d["clear"]
        self.assertEqual(clear["status"], "ready")
        self.assertEqual(clear["save_request"], {"ordered_refs": [], "expected_version": 3})
        self.assertFalse(clear["affects_running_work"])
        empty = self.d["empty_view"]
        self.assertFalse(empty["explicit_focus"])
        self.assertEqual(empty["items"], [])
        self.assertFalse(empty["clear_focus"]["enabled"])
        self.assertFalse(empty["clear_focus"]["affects_running_work"])
        source = (ROOT / "src" / "WeeklyFocusUi.php").read_text(encoding="utf-8").lower()
        forbidden = ("curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents", "unlink(", "shell_exec", "proc_open", "exec(", "system(")
        self.assertFalse(any(token in source for token in forbidden))


if __name__ == "__main__":
    unittest.main()
