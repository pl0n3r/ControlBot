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


if __name__ == "__main__":
    unittest.main()
