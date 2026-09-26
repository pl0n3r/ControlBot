import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ISSUE_AND_PR_WORKFLOWS = [
    ROOT / ".github" / "workflows" / "etiquetas.yml",
    ROOT / ".github" / "workflows" / "roles.yml",
]
PR_ONLY_WORKFLOWS = [
    ROOT / ".github" / "workflows" / "politica.yml",
    ROOT / ".github" / "workflows" / "privacidad.yml",
    ROOT / ".github" / "workflows" / "aceptacion.yml",
]


class ActionsFanoutTests(unittest.TestCase):
    def test_coordination_sweep_is_housekeeping_not_hourly_baseline(self):
        workflow = (ROOT / ".github" / "workflows" / "coordinacion.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("cron: '17 */6 * * *'", workflow)
        self.assertNotIn("cron: '17 * * * *'", workflow)

    def test_issue_and_pr_validation_workflows_debounce_bursts(self):
        expected = (
            "concurrency:\n"
            "  group: ${{ github.workflow }}-${{ github.event_name }}-"
            "${{ github.event.issue.number || github.event.pull_request.number || github.ref || github.run_id }}\n"
            "  cancel-in-progress: true\n"
        )
        for workflow in ISSUE_AND_PR_WORKFLOWS:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                self.assertIn(expected, content)

    def test_pr_only_gates_cancel_obsolete_heads(self):
        expected = (
            "concurrency:\n"
            "  group: ${{ github.workflow }}-"
            "${{ github.event.pull_request.number || github.ref || github.run_id }}\n"
            "  cancel-in-progress: true\n"
        )
        for workflow in PR_ONLY_WORKFLOWS:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                self.assertIn(expected, content)

    def test_debounce_is_declared_before_jobs(self):
        for workflow in ISSUE_AND_PR_WORKFLOWS + PR_ONLY_WORKFLOWS:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                concurrency = content.index("concurrency:\n")
                jobs = content.index("jobs:\n")
                self.assertLess(concurrency, jobs)

    def test_expensive_pr_gates_wait_until_ready_for_review(self):
        expected_trigger = "ready_for_review"
        expected_gate = "github.event.pull_request.draft == false"
        workflows = [
            ROOT / ".github" / "workflows" / "ci.yml",
            ROOT / ".github" / "workflows" / "politica.yml",
            ROOT / ".github" / "workflows" / "privacidad.yml",
            ROOT / ".github" / "workflows" / "aceptacion.yml",
            *ISSUE_AND_PR_WORKFLOWS,
            ROOT / ".github" / "workflows" / "coordinacion.yml",
        ]
        for workflow in workflows:
            with self.subTest(workflow=workflow.name):
                content = workflow.read_text(encoding="utf-8")
                self.assertIn(expected_trigger, content)
                self.assertIn(expected_gate, content)

    def test_coordination_keeps_pr_state_sync_for_drafts(self):
        workflow = (ROOT / ".github" / "workflows" / "coordinacion.yml").read_text(
            encoding="utf-8"
        )
        pr_block = workflow.split("  pr:\n", 1)[1].split("  validar-pr:\n", 1)[0]
        validate_block = workflow.split("  validar-pr:\n", 1)[1].split(
            "  issue:\n", 1
        )[0]
        self.assertNotIn("draft == false", pr_block)
        self.assertIn("draft == false", validate_block)


if __name__ == "__main__":
    unittest.main()
