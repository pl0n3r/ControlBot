import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "coordinacion.yml"
REUSABLE = "uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1"


class CoordinationWorkflowTests(unittest.TestCase):
    def test_reusable_callers_grant_required_permission_envelope(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        jobs = workflow.split("\njobs:\n", 1)[1]
        blocks = re.split(r"\n  (?=[A-Za-z0-9_-]+:\n)", jobs)
        callers = [block for block in blocks if REUSABLE in block]

        self.assertEqual(len(callers), 6)
        required = {
            "contents: write",
            "issues: write",
            "pull-requests: write",
            "checks: write",
        }
        for caller in callers:
            permissions = caller.split("    permissions:\n", 1)[1].split("    uses:", 1)[0]
            for permission in required:
                self.assertIn(permission, permissions)

    def test_issue_78_bootstrap_does_not_disable_reservations_globally(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        line = next(
            value.strip()
            for value in workflow.splitlines()
            if value.strip().startswith("require_reservation:")
        )
        self.assertIn("trabajo/issue-78", line)
        self.assertIn("996802183248f84ee8f28c7cb25221247e52dd07", line)
        self.assertIn("&& !(", line)

    def test_workflow_default_remains_read_only(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        header = workflow.split("\njobs:\n", 1)[0]
        self.assertIn("permissions:\n  contents: read\n", header)
        self.assertNotIn("checks: write", header)


    def _pull_request_types(self, workflow: str) -> list[str]:
        match = re.search(r"(?m)^  pull_request:\n    types: \[(.*?)\]$", workflow)
        self.assertIsNotNone(match)
        return [item.strip() for item in match.group(1).split(",")]

    def _job_block(self, workflow: str, job: str) -> str:
        jobs = workflow.split("\njobs:\n", 1)[1]
        match = re.search(
            rf"(?ms)^  {re.escape(job)}:\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
            jobs,
        )
        self.assertIsNotNone(match)
        return match.group(1)

    def test_reopened_pr_triggers_coordination_without_rotating_reservation(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("reopened", self._pull_request_types(workflow))

        caller = self._job_block(workflow, "pr")
        self.assertIn("operation: pr", caller)
        self.assertIn("action: ${{ github.event.action }}", caller)
        self.assertIn(
            "github.event.pull_request.head.repo.full_name == github.repository",
            caller,
        )
        self.assertNotIn("reservation_id", caller)
        self.assertNotIn("condor-reserva", caller)

    def test_synchronize_pr_triggers_coordination_and_preserves_reservation(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("synchronize", self._pull_request_types(workflow))
        self.assertIn(
            "group: coordinacion-${{ github.event.issue.number || github.event.pull_request.head.ref || github.run_id }}",
            workflow,
        )
        self.assertIn("cancel-in-progress: false", workflow)

        caller = self._job_block(workflow, "pr")
        self.assertIn("operation: pr", caller)
        self.assertIn("action: ${{ github.event.action }}", caller)

    def test_reopened_or_synchronized_pr_without_trusted_marker_fails_closed(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        caller = self._job_block(workflow, "pr")

        # El caller local no fabrica ownership ni labels derivados. Delega el
        # evento crudo al contrato Factory v1, que valida marker/rama/PR.
        self.assertIn(REUSABLE, caller)
        self.assertIn(
            "github.event.pull_request.head.repo.full_name == github.repository",
            caller,
        )
        self.assertNotIn("estado: en revisión", workflow)
        self.assertNotIn("condor-reserva-id", workflow)

    def test_pull_request_trigger_keeps_existing_coordination_events(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        event_types = self._pull_request_types(workflow)
        self.assertEqual(
            [
                "opened",
                "reopened",
                "synchronize",
                "ready_for_review",
                "converted_to_draft",
                "closed",
            ],
            event_types,
        )
        self.assertEqual(1, workflow.count("\n  pr:\n"))
        self.assertEqual(1, workflow.count("\n  validar-pr:\n"))


if __name__ == "__main__":
    unittest.main()
