import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = [
    ROOT / ".github" / "workflows" / "etiquetas.yml",
    ROOT / ".github" / "workflows" / "roles.yml",
]


class ActionsFanoutTests(unittest.TestCase):
    def test_issue_and_pr_validation_workflows_debounce_bursts(self):
        expected = (
            "concurrency:\n"
            "  group: ${{ github.workflow }}-${{ github.event_name }}-"
            "${{ github.event.issue.number || github.event.pull_request.number || github.ref || github.run_id }}\n"
            "  cancel-in-progress: true\n"
        )
        for workflow in WORKFLOWS:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                self.assertIn(expected, content)

    def test_debounce_is_scoped_after_read_only_default_permissions(self):
        for workflow in WORKFLOWS:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                permissions = content.index("permissions:\n  contents: read\n")
                concurrency = content.index("concurrency:\n")
                jobs = content.index("jobs:\n")
                self.assertLess(permissions, concurrency)
                self.assertLess(concurrency, jobs)


if __name__ == "__main__":
    unittest.main()
