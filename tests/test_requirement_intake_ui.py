import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class RequirementIntakeUiTests(unittest.TestCase):
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
        cls.data = json.loads(completed.stdout)

    def test_owner_decision_shows_summary_questions_impacts_and_materialization_diff(self):
        view = self.data["view"]
        proposal = self.data["proposal"]

        self.assertEqual(view["summary"]["problem"], proposal["problem"])
        self.assertEqual(view["summary"]["user"], proposal["user"])
        self.assertEqual(view["summary"]["objectives"], proposal["objectives"])
        self.assertEqual(view["impacts"]["risks"], proposal["risks"])
        self.assertEqual(view["impacts"]["dependencies"], proposal["dependencies"])
        self.assertEqual(view["impacts"]["questions"], proposal["questions"])
        self.assertEqual(view["impacts"]["project_match"], proposal["project_match"])

        diff = view["materialization_diff"]
        self.assertEqual(set(diff), {"project", "epic", "issues"})
        self.assertEqual(diff["project"]["operation"], "link_existing")
        self.assertEqual(diff["project"]["project_ref"], "controlbot:project/controlbot")
        self.assertEqual(diff["epic"]["operation"], "propose_create")
        self.assertTrue(all(issue["operation"] == "propose_create" for issue in diff["issues"]))
        self.assertFalse(view["execution"])


if __name__ == "__main__":
    unittest.main()
