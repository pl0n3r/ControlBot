import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "work_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return result.stdout


class WorkUiTests(unittest.TestCase):
    def test_work_rows_reuse_scheduler_workitem_and_readiness_without_parallel_state(self):
        html = render("ready")
        self.assertIn('data-work="work-incident"', html)
        self.assertIn('data-work="work-feature"', html)
        self.assertIn('data-ready="true"', html)
        self.assertIn("READY", html)
        self.assertLess(html.index('data-work="work-incident"'), html.index('data-work="work-feature"'))
        source = (ROOT / "src" / "WorkUi.php").read_text(encoding="utf-8")
        self.assertIn("SchedulerCore::workItem", source)
        self.assertIn("SchedulerCore::readiness", source)
        for forbidden in ("private const STATES", "approval_state'=>'", "freeze_state'=>'"):
            self.assertNotIn(forbidden, source)

    def test_blocking_reasons_dependencies_reservation_and_generation_are_visible(self):
        html = render("blocked")
        for expected in (
            "work-blocked",
            "BLOCKED",
            "open_dependencies",
            "unknown_dependencies",
            "dep-open",
            "dep-unknown",
            "7c43b4b2-4ec5-4386-ab3b-95dcd9a4a076",
            "session-owner",
            "Generation / attempt",
            "2 / 1",
            "account-main",
        ):
            self.assertIn(expected, html)
        self.assertIn('data-ready="false"', html)

    def test_unknown_pending_or_frozen_work_never_renders_ready(self):
        html = render("guarded")
        self.assertEqual(html.count('data-ready="false"'), 3)
        self.assertNotIn('data-ready="true"', html)
        for expected in ("approval_unknown", "pending_human_gate", "freeze_active"):
            self.assertIn(expected, html)

    def test_loading_empty_error_and_hostile_content_are_explicit_and_escaped(self):
        data = json.loads(render("states"))
        self.assertIn('data-state="loading"', data["loading"])
        self.assertIn("LOADING · Cargando trabajo.", data["loading"])
        self.assertIn('data-state="empty"', data["empty"])
        self.assertIn("EMPTY · Sin trabajo observado.", data["empty"])
        self.assertIn('data-state="error"', data["error"])
        self.assertNotIn("<script>retry</script>", data["error"])
        self.assertIn("&lt;script&gt;retry&lt;/script&gt;", data["error"])

    def test_ui_is_mobile_first_accessible_and_read_only(self):
        html = render("ready")
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        self.assertIn("@media(min-width:760px)", html)
        self.assertIn("prefers-reduced-motion:reduce", html)
        self.assertIn("focus-visible", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", 'method="post"', "<script"):
            self.assertNotIn(forbidden, lowered)
        source = (ROOT / "src" / "WorkUi.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_", "file_get_contents(", "mysqli", "pdo(", "select_next",
            "reserve(", "assign(", "requeue(", "shell_exec(", "proc_open(",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
